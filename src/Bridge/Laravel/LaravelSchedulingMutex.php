<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeInterface;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;

/**
 * Laravel's one-server slot (`onOneServer()`), answered by our mutex for every task in the schedule: the first
 * server to claim a task's minute runs it, the others skip it. A task of this package claims
 * `scheduler:task:<id>:<UTC minute>`; a task from the app's own schedule claims
 * `scheduler:external:<Laravel's mutex name>:<UTC minute>`. A claim is never released, it expires after an hour,
 * so background runs need no exception here.
 */
final class LaravelSchedulingMutex implements SchedulingMutex
{
    private const int TTL_SECONDS = 3600;

    /**
     * @param MutexInterface  $mutex  takes the per-minute claims
     * @param TaskIndex       $index  tells the package's tasks from the app's own
     * @param LoggerInterface $logger warns about a run skipped for unreachable lock storage
     */
    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
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
            return null !== $this->mutex->acquire($key, self::TTL_SECONDS);
        } catch (LockUnavailableException $e) {
            $this->logger->warning('Lock storage unreachable; the run is skipped', [...(null !== $task ? ['task_id' => $task->id->value] : []), 'mutex' => $event->mutexName(), 'exception' => $e]);

            return false;
        }
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
