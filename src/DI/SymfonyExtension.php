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

use Nette\DI\CompilerExtension;
use Nette\DI\Statement;
use Nette\Neon\Entity;
use Nette\PhpGenerator\ClassType;
use ProxyManager\Configuration;
use Symfonette\DependencyInjectionIntegration\DependencyInjection\NestedEnvPlaceholderParameterBag;
use Symfonette\DependencyInjectionIntegration\Transformer\NetteToSymfonyTransformer;
use Symfonette\DependencyInjectionIntegration\Transformer\SymfonyToNetteTransformer;
use Symfonette\NeonIntegration\DependencyInjection\NeonFileLoader;
use Symfony\Bridge\ProxyManager\LazyProxy\Instantiator\RuntimeInstantiator;
use Symfony\Component\Config\FileLocator;
use Symfony\Component\Config\Loader\DelegatingLoader;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\Config\Loader\LoaderResolver;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\ExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\ClosureLoader;
use Symfony\Component\DependencyInjection\Loader\DirectoryLoader;
use Symfony\Component\DependencyInjection\Loader\GlobFileLoader;
use Symfony\Component\DependencyInjection\Loader\IniFileLoader;
use Symfony\Component\DependencyInjection\Loader\PhpFileLoader;
use Symfony\Component\DependencyInjection\Loader\XmlFileLoader;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBag;
use Symfony\Component\HttpKernel\Bundle\BundleInterface;
use Symfony\Component\HttpKernel\DependencyInjection\MergeExtensionConfigurationPass;

final class SymfonyExtension extends CompilerExtension
{
    private const CONTAINER_SERVICE_NAME = 'service_container';

    private $defaults = [
        'bundles' => [],
        'extensions' => [],
        'compiler_passes' => [],
        'nette_to_symfony' => [],
        'symfony_to_nette' => [],
        'imports' => [],
        'parameters' => [],
        'services' => [],
    ];

    private $netteToSymfony = [];
    private $symfonyToNette = [];
    private $bundles = [];
    private $projectDir;
    private $debug;
    private $environment;
    /** @var ContainerBuilder */
    private $container;

    public function __construct(string $projectDir, bool $debug, string $environment)
    {
        $this->projectDir = $projectDir;
        $this->debug = $debug;
        $this->environment = $environment;
    }

    public function loadConfiguration(): void
    {
        $config = $this->getConfig($this->defaults);
        $this->bundles = $bundles = $this->createBundles($config['bundles']);
        $this->netteToSymfony = $config['nette_to_symfony'];
        $this->symfonyToNette = $config['symfony_to_nette'];
        $extensions = $this->createExtensions($config['extensions']);
        $compilerPasses = $this->createCompilerPasses($config['compiler_passes']);

        foreach (['bundles', 'extensions', 'compiler_passes', 'nette_to_symfony', 'symfony_to_nette'] as $key) {
            unset($config[$key]);
        }

        $this->container = $this->buildSymfonyContainer($bundles, $extensions, $compilerPasses, $config);
    }

    public function beforeCompile(): void
    {
        $netteContainer = $this->getContainerBuilder();

        $this->addSymfonyContainerAdapter(); // TODO
        $transformer = new NetteToSymfonyTransformer($this->netteToSymfony);
        $transformer->transform($netteContainer, $this->container);
        $this->container->compile();

        $transformer = new SymfonyToNetteTransformer($this->symfonyToNette);
        $transformer->transform($this->container, $netteContainer);
    }

    public function afterCompile(ClassType $class): void
    {
        $initializerMethod = $class->getMethod('initialize');
        $initializerMethod->addBody('
            foreach (? as $bundle) {
                $bundle->setContainer($this->getService(?));
                $bundle->boot();
            }', [$this->bundles, self::CONTAINER_SERVICE_NAME])
        ;
    }

    private function buildSymfonyContainer(array $bundles, array $extensions, array $compilerPasses, array $config)
    {
        $container = $this->getSymfonyContainerBuilder($bundles);
        $container->addObjectResource($this);
        $this->prepareSymfonyContainer($container, $bundles, $extensions, $compilerPasses);

        $loader = $this->getContainerLoader($container);
        if (null !== $cont = $loader->load($this->processConfig($config, $loader))) {
            $container->merge($cont);
        }

        return $container;
    }

    private function getSymfonyContainerBuilder(array $bundles): ContainerBuilder
    {
        $parameterBag = new NestedEnvPlaceholderParameterBag($this->getSymfonyParameters($bundles));
//        $parameterBag = new EnvPlaceholderParameterBag($this->getSymfonyParameters($bundles));
        $container = new ContainerBuilder($parameterBag);

        if (class_exists(Configuration::class) && class_exists(RuntimeInstantiator::class)) {
            $container->setProxyInstantiator(new RuntimeInstantiator());
        }

        return $container;
    }

    /**
     * @param BundleInterface[]|array $bundles
     * @param ExtensionInterface[]|array $extensions
     */
    protected function prepareSymfonyContainer(ContainerBuilder $container, array $bundles, array $extensions, array $compilerPasses): void
    {
        foreach ($bundles as $bundle) {
            if ($extension = $bundle->getContainerExtension()) {
                $container->registerExtension($extension);
            }

            if ($this->debug) {
                $container->addObjectResource($bundle);
            }
        }

        foreach ($extensions as $extension) {
            $container->registerExtension($extension);
        }

        foreach ($bundles as $bundle) {
            $bundle->build($container);
        }

        foreach ($compilerPasses as $compilerPass) {
            $container->addCompilerPass($compilerPass['pass'], $compilerPass['type'], $compilerPass['priority']);
        }

        if (class_exists(MergeExtensionConfigurationPass::class)) {
            $extensionNames = [];
            foreach ($container->getExtensions() as $extension) {
                $extensionNames[] = $extension->getAlias();
            }

            $container->getCompilerPassConfig()->setMergePass(new MergeExtensionConfigurationPass($extensionNames));
        }
    }

    /**
     * @return BundleInterface[]
     * @throws \ReflectionException
     */
    private function createBundles(array $bundles): array
    {
        $instances = [];
        foreach ($bundles as $bundle) {
            $instances[] = $bundle instanceof BundleInterface ? $bundle : (new \ReflectionClass($bundle))->newInstance();
        }

        return $instances;
    }

    /**
     * @return ExtensionInterface[]
     * @throws \ReflectionException
     */
    private function createExtensions(array $extensions): array
    {
        $instances = [];
        foreach ($extensions as $extension) {
            $instances[] = $extension instanceof ExtensionInterface ? $extension : (new \ReflectionClass($extension))->newInstance();
        }

        return $instances;
    }

    private function getContainerLoader(ContainerBuilder $container): LoaderInterface
    {
        $locator = new FileLocator($this->getLocatorPaths($container));
        $resolver = new LoaderResolver([
            new XmlFileLoader($container, $locator),
            new YamlFileLoader($container, $locator),
            new IniFileLoader($container, $locator),
            new PhpFileLoader($container, $locator),
            new GlobFileLoader($container, $locator),
            new DirectoryLoader($container, $locator),
            new ClosureLoader($container),
        ]);

        if (class_exists(NeonFileLoader::class)) {
            $resolver->addLoader(new NeonFileLoader($container, $locator));
        }

        return new DelegatingLoader($resolver);
    }

    /**
     * @param BundleInterface[] $bundles
     */
    private function getSymfonyParameters(array $bundles): array
    {
        $bundlesInstance = [];
        $bundlesMetadata = [];

        foreach ($bundles as $name => $bundle) {
            $bundlesInstance[$name] = \get_class($bundle);
            $bundlesMetadata[$name] = [
                'path' => $bundle->getPath(),
                'namespace' => $bundle->getNamespace(),
            ];
        }

        $time = time();
        $hash = substr(base64_encode(hash('sha256', serialize($this->config), true)), 0, 7);
        return [
            'kernel.root_dir' => '%appDir%',
            'kernel.project_dir' => realpath($this->projectDir) ?: $this->projectDir,
            'kernel.environment' => $this->environment,
            'kernel.debug' => $this->debug,
            'kernel.name' => 'Nette',
            'kernel.cache_dir' => '%tempDir%/cache/symfony/%kernel.environment%',
            'kernel.logs_dir' => '%kernel.project_dir%/log',
            'kernel.bundles' => $bundlesInstance,
            'kernel.bundles_metadata' => $bundlesMetadata,
            'kernel.charset' => 'utf-8',
            'kernel.container_class' => $this->name.ucfirst($this->environment).($this->debug ? 'Debug' : '').'ProjectContainer',
            'container.build_hash' => $hash,
            'container.build_time' => $time,
            'container.build_id' => hash('crc32', $hash.$time),
        ];
    }

    private function processConfig(array $config, LoaderInterface $loader): \Closure
    {
        return function (ContainerBuilder $container) use ($config, $loader): void {
            array_walk_recursive($config, function (&$v): void {
                $v = $v instanceof Statement ? new Entity($v->entity, $v->arguments) : $v;
            });

            foreach ($config['imports'] as $name => $value) {
                $loader->load($value);
            }

            $parameterBag = $container->getParameterBag();
            foreach ($config['parameters'] as $name => $value) {
                $parameterBag->set($name, $value);
            }

            unset($config['imports'], $config['parameters'], $config['services']);
            foreach ($config as $key => $value) {
                $extension = $container->getExtension($key);
                $extension->load([$value], $container);
            }
        };
    }

    private function getLocatorPaths(ContainerBuilder $container): array
    {
        $parameters = new ParameterBag($container->getParameterBag()->all());
        $netteContainer = $this->getContainerBuilder();
        foreach ($netteContainer->parameters as $key => $value) {
            $parameters->set($key, $value);
        }

        $parameters->resolve();
        return [
            $parameters->get('appDir'),
            $parameters->get('appDir').'/config',
        ];
    }

    private function addSymfonyContainerAdapter(): void
    {
        $this->getContainerBuilder()
            ->addDefinition(self::CONTAINER_SERVICE_NAME)
            ->setClass(SymfonyContainerAdapter::class)
        ;
    }

    /**
     * @throws \ReflectionException
     */
    private function createCompilerPasses(array $passes): array
    {
        $defaults = [
            'type' => PassConfig::TYPE_BEFORE_OPTIMIZATION,
            'priority' => 0,
        ];

        $instances = [];
        foreach ($passes as $key => $pass) {
            if (!is_array($pass)) {
                $pass = ['class' => $pass];
            }

            $pass = array_merge($defaults, $pass);

            if (!isset($pass['class'])) {
                $pass['class'] = $key;
            }

            $pass['pass'] = $pass['class'] instanceof CompilerPassInterface ? $pass['class'] : (new \ReflectionClass($pass['class']))->newInstance();

            $instances[] = $pass;
        }

        return $instances;
    }
}
