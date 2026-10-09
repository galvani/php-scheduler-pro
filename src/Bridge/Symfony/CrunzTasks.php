<?php declare(strict_types=1);

namespace Scheduling\Bridge\Symfony;

use Crunz\Schedule;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\SchedulerInterface;

/**
 * The one public service of the Symfony bundle, called from the crunz tasks file on every tick: it registers the
 * current task list with the crunz engine, logs each task that could not be scheduled, and returns crunz's schedule.
 */
final readonly class CrunzTasks
{
    public function __construct(
        private SchedulerInterface $scheduler,
        private CrunzEngine $engine,
        private LoggerInterface $logger,
    ) {}

    public function schedule(): Schedule
    {
        foreach ($this->scheduler->registerWith($this->engine)->problems as $problem) {
            $this->logger->warning("Task not scheduled: {$problem->reason}", ['task_id' => $problem->taskId, 'source' => $problem->source]);
        }

        return $this->engine->schedule();
    }
}
