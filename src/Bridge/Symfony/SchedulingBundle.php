<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Crunz\Schedule;
use DateTimeZone;
use PDO;
use Psr\Log\NullLogger;
use Redis;
use RuntimeException;
use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\Exception\MissingPackageException;
use Scheduling\Lock\MutexInterface;
use Scheduling\Lock\RedisMutex;
use Scheduling\Run\RecordingRunListener;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Scheduler;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\YamlTaskSource;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;

use function Symfony\Component\DependencyInjection\Loader\Configurator\inline_service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service_closure;

/**
 * Wires the package into a Symfony application on the crunz engine: the YAML and optional database task sources,
 * the Redis mutex and run record, Scheduler and CrunzEngine. Configured under `scheduling:`; `redis` is the app's
 * own Redis, a DSN or `@service_id`, see RedisConnection. Every service is
 * private except CrunzTasks, which the crunz tasks file fetches. Symfony's own Scheduler is not used: a Symfony
 * app runs this package on crunz.
 *
 * An unset `host` is read from the machine when the run listener is built, not when the container compiles, so a
 * container cache shared between servers records each server's own name. The database is connected when the
 * Scheduler is built, and an unreachable database falls back to the YAML tasks (SchedulerFactory). The default
 * timezone of the tasks is PHP's default when the container compiles.
 */
final class SchedulingBundle extends AbstractBundle implements CompilerPassInterface
{
    private const string LOGGER = 'scheduling.logger';
    private const string REDIS = 'scheduling.redis';
    private const string PDO = 'scheduling.pdo';

    /**
     * Generates the configuration tree builder.
     *
     * @throws RuntimeException
     */
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('tasks_file')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('console_binary')->isRequired()->cannotBeEmpty()->end()
                ->scalarNode('host')->defaultNull()->end()
                ->scalarNode('redis')->isRequired()->cannotBeEmpty()->end()
                ->arrayNode('database')
                    ->addDefaultsIfNotSet()
                    ->validate()
                        ->ifTrue(static fn (array $database): bool => true === $database['enabled'] && null === $database['dsn'])
                        ->thenInvalid('scheduling.database.dsn is required when the database is enabled')
                    ->end()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('dsn')->defaultNull()->end()
                        ->scalarNode('user')->defaultNull()->end()
                        ->scalarNode('password')->defaultNull()->end()
                        ->scalarNode('table')->defaultValue('scheduler_tasks')->end()
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Loads the processed bundle configuration and registers the services it describes.
     *
     * @param array{
     *     tasks_file: string,
     *     console_binary: string,
     *     host: string|null,
     *     redis: string,
     *     database: array{enabled: bool, dsn: string|null, user: string|null, password: string|null, table: string},
     * } $config
     *
     * @throws MissingPackageException when crunzphp/crunz or symfony/lock is not installed
     */
    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        RequiredPackages::assertInstalled([
            'Crunz\\Schedule' => 'crunzphp/crunz',
            'Symfony\\Component\\Lock\\PersistingStoreInterface' => 'symfony/lock',
        ]);
        $services = $container->services();
        $database = $config['database'];

        if (str_starts_with($config['redis'], '@')) {
            $services->set(self::REDIS, Redis::class)
                ->factory([RedisConnection::class, 'unprefixed'])
                ->args([service(substr($config['redis'], 1)), 'service '.substr($config['redis'], 1), service(self::LOGGER)]);
        } else {
            $services->set(self::REDIS, Redis::class)
                ->factory([RedisConnection::class, 'fromDsn'])
                ->args([$config['redis'], service(self::LOGGER)]);
        }
        $services->set(MutexInterface::class, RedisMutex::class)->args([service(self::REDIS)]);
        $services->set(RunRepositoryInterface::class, RedisRunRepository::class)->args([service(self::REDIS)]);
        $services->set(TaskDefinitionMapper::class)->args([
            inline_service(DateTimeZone::class)->args([date_default_timezone_get()]),
            '%kernel.environment%',
        ]);
        $services->set(YamlTaskSource::class)->args([$config['tasks_file'], service(TaskDefinitionMapper::class)]);

        if ($database['enabled']) {
            $services->set(self::PDO, PDO::class)->args([$database['dsn'], $database['user'], $database['password']]);
            $services->set(SchedulerInterface::class, Scheduler::class)
                ->factory([SchedulerFactory::class, 'withDatabase'])
                ->args([
                    service(YamlTaskSource::class),
                    service_closure(self::PDO),
                    service(TaskDefinitionMapper::class),
                    $database['table'],
                    service(self::LOGGER),
                ]);
        } else {
            $services->set(SchedulerInterface::class, Scheduler::class)->args([service(YamlTaskSource::class)]);
        }

        if (null === $config['host']) {
            $services->set(RunListenerInterface::class, RecordingRunListener::class)
                ->factory([RunListenerFactory::class, 'onThisHost'])
                ->args([service(SchedulerInterface::class), service(RunRepositoryInterface::class), service(self::LOGGER)]);
        } else {
            $services->set(RunListenerInterface::class, RecordingRunListener::class)
                ->factory([RunListenerFactory::class, 'create'])
                ->args([service(SchedulerInterface::class), service(RunRepositoryInterface::class), $config['host'], service(self::LOGGER)]);
        }
        $services->set(Schedule::class);
        $services->set(CrunzEngine::class)->args([
            service(Schedule::class),
            service(MutexInterface::class),
            service(RunListenerInterface::class),
            $config['console_binary'],
        ]);
        $services->set(CrunzTasks::class)
            ->public()
            ->args([service(SchedulerInterface::class), service(CrunzEngine::class), service(self::LOGGER)]);
    }

    /**
     * Registers compilation passes on the container; this bundle registers itself as one.
     */
    public function build(ContainerBuilder $container): void
    {
        $container->addCompilerPass($this);
    }

    /**
     * Modifies the container before it is dumped to PHP code.
     */
    public function process(ContainerBuilder $container): void
    {
        if ($container->has('logger')) {
            $container->setAlias(self::LOGGER, 'logger');
        } else {
            $container->register(self::LOGGER, NullLogger::class);
        }
    }
}
