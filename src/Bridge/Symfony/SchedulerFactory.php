<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Closure;
use PDO;
use PDOException;
use Psr\Log\LoggerInterface;
use Scheduling\Scheduler;
use Scheduling\Task\CompositeTaskSource;
use Scheduling\Task\PdoTaskRepository;
use Scheduling\Task\TaskDefinitionMapper;
use Scheduling\Task\YamlTaskSource;

/**
 * Builds the Scheduler for the Symfony container when the database task source is on. The database connection is
 * opened here rather than by the container, so an unreachable database cannot stop the YAML tasks: it is logged as
 * a warning and the Scheduler runs on the YAML file alone, with no writable source, the same rule the Laravel
 * provider applies.
 */
final readonly class SchedulerFactory
{
    /**
     * Opens the database and returns a Scheduler reading the YAML file and the database table, writing to the table.
     * When the connection fails, logs a warning and returns a Scheduler reading the YAML file only; its writes then
     * throw TaskNotWritableException.
     *
     * @param Closure(): PDO $database opens the connection; called once
     * @param string         $table    the tasks table, see PdoTaskRepository
     */
    public static function withDatabase(
        YamlTaskSource $yaml,
        Closure $database,
        TaskDefinitionMapper $mapper,
        string $table,
        LoggerInterface $logger,
    ): Scheduler {
        try {
            $repository = new PdoTaskRepository($database(), $mapper, $table);
        } catch (PDOException $e) {
            $logger->warning('Database task source unreachable; running the YAML tasks only', ['exception' => $e]);

            return new Scheduler($yaml);
        }

        return new Scheduler(new CompositeTaskSource([$yaml, $repository]), $repository);
    }
}
