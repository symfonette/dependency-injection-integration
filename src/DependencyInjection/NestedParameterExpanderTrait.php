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

namespace Symfonette\DependencyInjectionIntegration\DependencyInjection;

trait NestedParameterExpanderTrait
{
    private $map = [];

    public function getExpandedParameters(): array
    {
        $parameters = [];
        foreach ($this->map as $values) {
            $parameters = array_merge($parameters, (array) $values);
        }

        return $parameters;
    }

    public function remove($name): void
    {
        parent::remove($name);

        if (!isset($this->map[$name])) {
            return;
        }

        foreach ($this->map[$name] as $key) {
            parent::remove($key);
        }
    }

    public function set($name, $value): void
    {
        parent::set($name, $value);

        if (!is_array($value)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(new \RecursiveArrayIterator($value), \RecursiveIteratorIterator::SELF_FIRST);
        $breadcrumbs = [$name];
        foreach ($iterator as $index => $item) {
            $depth = $iterator->getDepth() + 1;
            while (count($breadcrumbs) > $depth) {
                array_pop($breadcrumbs);
            }
            $breadcrumbs[] = $index;
            $key = join($this->separator, $breadcrumbs);

            if ($this->has($key)) {
                continue;
            }

            $this->map[$name][] = $key;
            $this->parameters[$key] = $item;
        }
    }
}
