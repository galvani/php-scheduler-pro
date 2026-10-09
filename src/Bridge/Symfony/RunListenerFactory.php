<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Psr\Log\LoggerInterface;
use Scheduling\Run\RecordingRunListener;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\SchedulerInterface;

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
