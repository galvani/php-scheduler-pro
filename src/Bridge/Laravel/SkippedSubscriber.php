<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

/**
 * Records Laravel's `ScheduledTaskSkipped` for the package's own tasks. Laravel sends the same event for every
 * cause and says none, so the reason is read from what actually held the run back: a condition that fails, the
 * overlap lock still held in Redis, or neither, which leaves the schedule itself (paused with `schedule:pause`).
 */
final readonly class SkippedSubscriber
{
    public function __construct(
        private RunListenerInterface $listener,
        private TaskIndex $index,
        private MutexInterface $mutex,
    ) {}

    /** Records the skip of a task this package registered; a skip of the app's own tasks is not recorded. */
    public function handle(ScheduledTaskSkipped $event): void
    {
        $task = $this->index->get($event->task->mutexName());
        if (null === $task) {
            return;
        }
        if (!$task->conditionsHold()) {
            $reason = SkipReason::ConditionNotMet;
        } else {
            try {
                $reason = $this->mutex->isLocked(LockKey::forTask($task->id)) ? SkipReason::StillRunning : SkipReason::Paused;
            } catch (LockUnavailableException) {
                $reason = SkipReason::LockUnavailable;
            }
        }
        $this->listener->skipped($task->id, new DateTimeImmutable(), $reason);
    }
}
