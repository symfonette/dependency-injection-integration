<?php declare(strict_types=1);

/*
 * This is part of the symfonette/dependency-injection-integration.
 *
 * (c) Martin Hasoň <martin.hason@gmail.com>
 * (c) Webuni s.r.o. <info@webuni.cz>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Symfonette\DependencyInjectionIntegration\Transformer;

use Nette\DI\ContainerBuilder as NetteBuilder;
use Nette\DI\ServiceDefinition;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\DependencyInjection\Argument\ArgumentInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Reference;

final class SymfonyToNetteTransformer
{
    public function transform(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder = null): NetteBuilder
    {
        $netteBuilder = $netteBuilder ?? new NetteBuilder();
        $this->transformParameters($symfonyBuilder, $netteBuilder);
        $this->transformAliases($symfonyBuilder, $netteBuilder);
        $this->transformDefinitions($symfonyBuilder, $netteBuilder);
        $this->fixAutowiredDefinitions($symfonyBuilder, $netteBuilder);
        $this->transformResources($symfonyBuilder, $netteBuilder);

        return $netteBuilder;
    }

    private function transformParameters(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder): void
    {
        foreach ($symfonyBuilder->getParameterBag()->all() as $name => $value) {
            $netteBuilder->parameters[$name] = $value;
        }
    }

    private function transformAliases(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder): void
    {
        foreach ($symfonyBuilder->getAliases() as $name => $alias) {
            if ($alias->isPrivate()) {
                continue;
            }

            $netteBuilder->addAlias($name, (string) $alias);
        }
    }

    private function transformDefinitions(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder): void
    {
        foreach ($symfonyBuilder->getDefinitions() as $name => $symfonyDefinition) {
            if ($symfonyDefinition->isSynthetic() || $symfonyDefinition->isAbstract()) {
                continue;
            }

            $name = $this->sanitizeServiceName($name);
            if ($netteBuilder->hasDefinition($name)) {
                continue;
            }

            $netteDefinition =  $netteBuilder->addDefinition($name);
            $this->transformDefinition($symfonyDefinition, $netteDefinition, $netteBuilder);
        }
    }

    private function transformDefinition(Definition $symfonyDefinition, ServiceDefinition $netteDefinition, NetteBuilder $netteBuilder): ServiceDefinition
    {
        $netteDefinition
            ->setType($symfonyDefinition->getClass())
            ->setTags($symfonyDefinition->getTags())
        ;

        $this->transformFactory($symfonyDefinition, $netteDefinition, $netteBuilder);
        $netteDefinition->setArguments($this->transformArguments($symfonyDefinition->getArguments(), $netteBuilder));

        foreach ($symfonyDefinition->getMethodCalls() as $methodCall) {
            $netteDefinition->addSetup($methodCall[0], $this->transformArguments($methodCall[1], $netteBuilder));
        }

        return $netteDefinition;
    }

    private function transformArguments(array $arguments, NetteBuilder $netteBuilder)
    {
        $netteArguments = [];
        foreach ($arguments as $key => $argument) {
            if (is_array($argument)) {
                $argument = $this->transformArguments($argument, $netteBuilder);
            } elseif ($argument instanceof ArgumentInterface) {
                $argument = $this->transformArguments($argument->getValues(), $netteBuilder);
            } elseif ($argument instanceof Reference) {
                $argument = '@'.$argument;
            } elseif ($argument instanceof Definition) {
                $name = $this->addAnonymousDefinition($argument, $netteBuilder);
                $argument = '@' . $name;
            } elseif (is_string($argument) && preg_match('/^%([^%]+)%$/', $argument, $match)) {
                $argument = $netteBuilder->parameters[$match[1]];
            } else {
                $argument = $netteBuilder->expand($argument);
            }

            $netteArguments[$key] = $argument;
        }

        return $netteArguments;
    }

    private function transformFactory(Definition $symfonyDefinition, ServiceDefinition $netteDefinition, NetteBuilder $netteBuilder)
    {
        $factory = $symfonyDefinition->getFactory();
        if (null === $factory) {
            return $netteDefinition;
        }

        if (is_array($factory) && $factory[0] instanceof Reference) {
            $factory = ['@'.$factory[0], $factory[1]];
        } elseif (is_array($factory) && $factory[0] instanceof Definition) {
            $name = $this->addAnonymousDefinition($factory[0], $netteBuilder);
            $factory = ['@'.$name, $factory[1]];
        }

        $netteDefinition->setFactory($factory);

        return $netteDefinition;
    }

    private function fixAutowiredDefinitions(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder): void
    {
        foreach ($netteBuilder->getClassList() as $class => $definitions) {
            if (!$symfonyBuilder->hasAlias($class) || !isset($definitions[true]) || 2 > count($definitions[true])) {
                continue;
            }

            $alias = (string) $symfonyBuilder->getAlias($class);

            foreach ($definitions[true] as $name) {
                if ($name !== $alias) {
                    $definition = $netteBuilder->getDefinition($name);
                    $definition->setAutowired(false);
                }
            }
        }
    }

    private function sanitizeServiceName($name): string
    {
        return strtr($name, ['-' => '_', '\\' => '.']);
    }

    private function transformResources(SymfonyBuilder $symfonyBuilder, NetteBuilder $netteBuilder): void
    {
        foreach ($symfonyBuilder->getResources() as $resource) {
            if ($resource instanceof FileResource) {
                $netteBuilder->addDependency($resource->getResource());
            }
        }
    }

    private function addAnonymousDefinition(Definition $definition, NetteBuilder $netteBuilder): string
    {
        $name = (count($netteBuilder->getDefinitions()) + 1).'_'.$this->sanitizeServiceName($definition->getClass());
        $netteDefinition = $netteBuilder->addDefinition($name);
        $this->transformDefinition($definition, $netteDefinition, $netteBuilder);
        $netteDefinition->setAutowired(false);

        return $name;
    }
}
