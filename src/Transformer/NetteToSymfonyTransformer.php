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
use Symfony\Component\DependencyInjection\Alias;
use Symfony\Component\DependencyInjection\ContainerBuilder as SymfonyBuilder;

final class NetteToSymfonyTransformer
{
    private $config;

    public function __construct(array $config = [])
    {
        $this->config = array_merge(['services' => [], 'tags' => []], $config);
    }

    public function transform(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder = null): SymfonyBuilder
    {
        $symfonyBuilder = $symfonyBuilder ?? new SymfonyBuilder();
        $this->transformParameters($netteBuilder, $symfonyBuilder);
        $this->transformAliases($netteBuilder, $symfonyBuilder);
        $this->transformDefinitions($netteBuilder, $symfonyBuilder);
        $this->fixAutowiredDefinitions($netteBuilder, $symfonyBuilder);
//        $this->transformResources($netteBuilder, $symfonyBuilder);

        return $symfonyBuilder;
    }

    private function transformParameters(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder): void
    {
        $symfonyBuilder->getParameterBag()->add($netteBuilder->parameters);
    }

    private function transformAliases(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder): void
    {
        foreach ($netteBuilder->getAliases() as $name => $alias) {
            $name = $this->transformServiceName($name);
            if (!$name || $symfonyBuilder->hasAlias($name)) {
                continue;
            }

            $symfonyBuilder->setAlias($name, new Alias($alias, false));
        }
    }

    private function transformDefinitions(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder): void
    {
        foreach ($netteBuilder->getDefinitions() as $name => $netteDefinition) {
            $name = $this->transformServiceName($name);
            if (!$name) {
                continue;
            }

            if ($symfonyBuilder->has($name) || $symfonyBuilder->hasAlias($name)) {
                $definiton = $symfonyBuilder->findDefinition($name); // continue ??
            } else {
                $definiton = $symfonyBuilder->register($name, $netteDefinition->getType());
            }

            $tags = [];
            foreach ($netteDefinition->getTags() as $tagName => $value) {
                $tags[$tagName] = []; // TODO
            }

            $definiton->setTags($tags);
            $definiton->setPublic(true); // ReplaceAliasByActualDefinition
            $definiton->setSynthetic(true);
        }
    }

    private function fixAutowiredDefinitions(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder): void
    {
        foreach ($netteBuilder->getClassList() as $class => $definitions) {
            if ($symfonyBuilder->has($class) || $symfonyBuilder->hasAlias($class)) {
                continue;
            }

            if (isset($definitions[true]) && 1 === count($definitions[true])) {
                $symfonyBuilder->setAlias($class, new Alias(reset($definitions[true]), false));
            }
        }
    }

    private function transformServiceName($name)
    {
        if (array_key_exists($name, $this->config['services'])) {
            $name = $this->config['services'][$name] ?? false;
        }

        return $name;
    }
}
