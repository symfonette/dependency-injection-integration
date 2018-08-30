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

namespace Symfonette\DependencyInjectionIntegration\DI;

use Nette\DI\Container;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Exception\InvalidArgumentException;
use Symfony\Component\DependencyInjection\Exception\ServiceNotFoundException;

final class SymfonyContainerAdapter implements ContainerInterface
{
    private $container;

    public function __construct(Container $container)
    {
        $this->container = $container;
    }

    public function set($id, $service): void
    {
        throw new \BadMethodCallException();
    }

    public function get($id, $invalidBehavior = self::EXCEPTION_ON_INVALID_REFERENCE)
    {
        if ($this->has($id)) {
            return $this->container->getService($id);
        }

        throw new ServiceNotFoundException(sprintf('Service "%s" was not found.', $id));
    }

    public function has($id): bool
    {
        return $this->container->hasService($id);
    }

    public function getParameter($name)
    {
        if ($this->hasParameter($name)) {
            return $this->container->getParameters()[$name];
        }

        throw new InvalidArgumentException(sprintf('Parameter "%s" was not found.', $name));
    }

    public function hasParameter($name): bool
    {
        return array_key_exists($name, $this->container->parameters);
    }

    public function setParameter($name, $value): void
    {
        throw new \BadMethodCallException();
    }

    public function initialized($id): void
    {
        throw new \BadMethodCallException();
    }
}
