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

    private function transformAliases(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder1): void
    {
        foreach ($netteBuilder->getAliases() as $name => $alias) {
            $symfonyBuilder1->setAlias($name, new Alias($alias));
        }
    }

    private function transformDefinitions(NetteBuilder $netteBuilder, SymfonyBuilder $symfonyBuilder): void
    {
        foreach ($netteBuilder->getDefinitions() as $name => $netteDefinition) {
            if ($symfonyBuilder->has($name) || $symfonyBuilder->hasAlias($name)) {
                $definiton = $symfonyBuilder->findDefinition($name); // continue ??
            } else {
                $definiton = $symfonyBuilder->register($name, $netteDefinition->getType());
            }
            $definiton->setPublic(true); // ReplaceAliasByActualDefinition
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
}
