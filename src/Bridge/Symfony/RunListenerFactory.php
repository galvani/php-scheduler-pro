<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Psr\Log\LoggerInterface;
use Scheduling\Run\RecordingRunListener;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\SchedulerInterface;

/**
 * Builds the run listener for the Symfony container. RecordingRunListener needs the task map to tell an overrun
 * from a normal finish, and the map is read from SchedulerInterface when the listener is built.
 */
final readonly class RunListenerFactory
{
    /**
     * Builds the listener for the configured `host`, the name every run record of this server carries.
     */
    public static function create(
        SchedulerInterface $scheduler,
        RunRepositoryInterface $runs,
        string $host,
        LoggerInterface $logger,
    ): RecordingRunListener {
        return new RecordingRunListener($runs, $scheduler->tasks()->tasks, $host, $logger);
    }

    /**
     * Builds the listener when no `host` is configured: the run records carry this machine's hostname, read now
     * rather than when the container compiles, or "unknown" when PHP cannot read it.
     */
    public static function onThisHost(
        SchedulerInterface $scheduler,
        RunRepositoryInterface $runs,
        LoggerInterface $logger,
    ): RecordingRunListener {
        return self::create($scheduler, $runs, gethostname() ?: 'unknown', $logger);
    }
}
