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
    public static function create(
        SchedulerInterface $scheduler,
        RunRepositoryInterface $runs,
        string $host,
        LoggerInterface $logger,
    ): RecordingRunListener {
        return new RecordingRunListener($runs, $scheduler->tasks()->tasks, $host, $logger);
    }
}
