<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use Exception;
use Scheduling\Bridge\Symfony\SchedulingBundle;
use Scheduling\Tests\Run\CollectingLogger;
use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Component\Config\Loader\LoaderInterface;
use Symfony\Component\DependencyInjection\Compiler\PassConfig;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\HttpKernel\Kernel;

final class TestKernel extends Kernel
{
    public readonly ServiceVisibility $visibility;

    /**
     * @param array<string, mixed> $scheduling the `scheduling` bundle config
     * @param list<string>         $expose     service ids made public for the test
     */
    public function __construct(
        private readonly array $scheduling,
        private readonly string $directory,
        private readonly bool $withLogger = false,
        array $expose = [],
    ) {
        $this->visibility = new ServiceVisibility($expose);
        parent::__construct('test', true);
    }

    /**
     * Returns the bundles to register.
     */
    public function registerBundles(): iterable
    {
        return [new FrameworkBundle(), new SchedulingBundle()];
    }

    /**
     * Loads the container configuration.
     *
     * @throws Exception
     */
    public function registerContainerConfiguration(LoaderInterface $loader): void
    {
        $loader->load(function (ContainerBuilder $container): void {
            $container->loadFromExtension('framework', ['secret' => 'test', 'http_method_override' => false]);
            $container->loadFromExtension('scheduling', $this->scheduling);
            $container->addCompilerPass($this->visibility, PassConfig::TYPE_BEFORE_REMOVING);
            if ($this->withLogger) {
                $container->register('logger', CollectingLogger::class)->setPublic(true);
            }
        });
    }

    /**
     * Gets the cache directory.
     *
     * This directory is used for caches that are written at runtime.
     */
    public function getCacheDir(): string
    {
        return "{$this->directory}/cache";
    }

    /**
     * Gets the log directory.
     */
    public function getLogDir(): string
    {
        return "{$this->directory}/log";
    }
}
