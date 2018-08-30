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

use Symfony\Component\DependencyInjection\ParameterBag\EnvPlaceholderParameterBag;

class NestedEnvPlaceholderParameterBag extends EnvPlaceholderParameterBag implements NestedParameterBagInterface
{
    use NestedParameterExpanderTrait;

    private $separator;

    public function __construct(array $parameters = [], $separator = '.')
    {
        $this->separator = $separator;
        parent::__construct($parameters);
    }
}
