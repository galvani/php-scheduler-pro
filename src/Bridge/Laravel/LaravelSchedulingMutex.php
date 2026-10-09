<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Console\Scheduling\CacheSchedulingMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

final class LaravelSchedulingMutex implements SchedulingMutex
{
    private const TTL_SECONDS = 3600;

    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
        private readonly RunListenerInterface $listener,
        private readonly CacheSchedulingMutex $laravelDefault,
    ) {}

    /** {@inheritDoc} */
    public function create(Event $event, DateTimeInterface $time): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task) {
            return $this->laravelDefault->create($event, $time);
        }
        try {
            $claimed = null !== $this->mutex->acquire(LockKey::forTaskAt($task->id, $time), self::TTL_SECONDS);
        } catch (LockUnavailableException) {
            $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::LockUnavailable);

            return false;
        }
        if ($claimed) {
            return true;
        }
        // Laravel dispatches no event for this skip; this is the only place it can be recorded.
        $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::RanOnAnotherServer);

        return false;
    }

    /** {@inheritDoc} */
    public function exists(Event $event, DateTimeInterface $time): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task) {
            return $this->laravelDefault->exists($event, $time);
        }

        try {
            return $this->mutex->isLocked(LockKey::forTaskAt($task->id, $time));
        } catch (LockUnavailableException) {
            return false;
        }
    }
}
