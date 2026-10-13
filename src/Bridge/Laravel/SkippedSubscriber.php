<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Cache\Repository as Cache;
use Psr\SimpleCache\InvalidArgumentException;
use ReflectionClass;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

/**
 * Records Laravel's `ScheduledTaskSkipped` for the package's own tasks. Laravel sends the same event for every
 * cause and says none, so the reason is read from what actually held the run back, in the order `schedule:run`
 * checks: the schedule paused with `schedule:pause` (Laravel 13 and later), then a condition that fails, then the
 * overlap lock still held in Redis. A skip with none of these is recorded as paused, as the last resort.
 */
final readonly class SkippedSubscriber
{
    /** The cache key `schedule:pause` sets and `schedule:resume` forgets. */
    private const string PAUSE_KEY = 'illuminate:schedule:paused';

    /**
     * @param RunListenerInterface $listener records the skip
     * @param TaskIndex            $index    tells the package's tasks from the app's own
     * @param MutexInterface       $mutex    tells whether the overlap lock held the run back
     * @param Cache                $cache    holds the pause flag of `schedule:pause`, in the store `schedule:run` reads
     */
    public function __construct(
        private RunListenerInterface $listener,
        private TaskIndex $index,
        private MutexInterface $mutex,
        private Cache $cache,
    ) {}

    /**
     * Records the skip of a task this package registered; a skip of the app's own tasks is not recorded.
     *
     * @throws InvalidArgumentException when the cache rejects the pause key
     */
    public function handle(ScheduledTaskSkipped $event): void
    {
        $task = $this->index->get($event->task->mutexName());
        if (null === $task) {
            return;
        }
        if ($this->isPaused($event->task)) {
            $reason = SkipReason::Paused;
        } elseif (!$task->conditionsHold()) {
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

    /**
     * Whether `schedule:run` skipped the event because the schedule is paused. Laravel 12 has no pause and
     * no `$pausable` switch on its Schedule, so there nothing is read. `$pausable` and `evenWhenPaused` are read
     * by name so the same code runs on 12 and 13.
     *
     * @throws InvalidArgumentException when the cache rejects the pause key
     */
    private function isPaused(Event $event): bool
    {
        if (true !== new ReflectionClass(Schedule::class)->getStaticPropertyValue('pausable', false)) {
            return false;
        }
        if (true === (get_object_vars($event)['evenWhenPaused'] ?? false)) {
            return false;
        }

        return (bool) $this->cache->get(self::PAUSE_KEY, false);
    }
}
