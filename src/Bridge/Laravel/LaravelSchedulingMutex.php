<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

/**
 * Laravel's one-server slot (`onOneServer()`), answered by our mutex for every task in the schedule: the first
 * server to claim a task's minute runs it, the others skip it. A task of this package claims
 * `scheduler:task:<id>:<UTC minute>` and its skips are recorded; a task from the app's own schedule claims
 * `scheduler:external:<Laravel's mutex name>:<UTC minute>`. A claim is never released, it expires after an hour,
 * so background runs need no exception here.
 */
final class LaravelSchedulingMutex implements SchedulingMutex
{
    private const int TTL_SECONDS = 3600;

    /**
     * @param MutexInterface       $mutex    takes the per-minute claims
     * @param TaskIndex            $index    tells the package's tasks from the app's own
     * @param RunListenerInterface $listener records the skips of the package's tasks
     * @param LoggerInterface      $logger   warns about an app task skipped for unreachable lock storage
     */
    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
        private readonly RunListenerInterface $listener,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attempts to obtain a scheduling mutex for the given event.
     */
    public function create(Event $event, DateTimeInterface $time): bool
    {
        $task = $this->index->get($event->mutexName());
        $key = null !== $task ? LockKey::forTaskAt($task->id, $time) : LockKey::forExternalAt($event->mutexName(), $time);
        try {
            $claimed = null !== $this->mutex->acquire($key, self::TTL_SECONDS);
        } catch (LockUnavailableException $e) {
            if (null !== $task) {
                $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::LockUnavailable);
            } else {
                $this->logger->warning('Lock storage unreachable; the run is skipped', ['mutex' => $event->mutexName(), 'exception' => $e]);
            }

            return false;
        }
        if (!$claimed && null !== $task) {
            // Laravel dispatches no event for this skip; this is the only place it can be recorded.
            $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::RanOnAnotherServer);
        }

        return $claimed;
    }

    /**
     * Determines whether a scheduling mutex exists for the given event.
     */
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        $task = $this->index->get($event->mutexName());
        try {
            return $this->mutex->isLocked(
                null !== $task ? LockKey::forTaskAt($task->id, $time) : LockKey::forExternalAt($event->mutexName(), $time),
            );
        } catch (LockUnavailableException) {
            return false;
        }
    }
}
