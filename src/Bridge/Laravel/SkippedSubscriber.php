<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

final readonly class SkippedSubscriber
{
    public function __construct(
        private RunListenerInterface $listener,
        private TaskIndex $index,
    ) {}

    public function handle(ScheduledTaskSkipped $event): void
    {
        $task = $this->index->get($event->task->mutexName());
        if (null !== $task) {
            $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::StillRunning);
        }
    }
}
