<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

final class LaravelEventMutex implements EventMutex
{
    /** @var array<string, LockInterface> */
    private array $held = [];

    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
        private readonly CacheEventMutex $laravelDefault,
        private readonly RunListenerInterface $listener,
    ) {}

    /** {@inheritDoc} */
    public function create(Event $event): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task) {
            return $this->laravelDefault->create($event);
        }
        try {
            $lock = $this->mutex->acquire(LockKey::forTask($task->id), $task->lock->ttlSeconds);
        } catch (LockUnavailableException) {
            $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::LockUnavailable);

            return false;
        }
        if (null === $lock) {
            return false;
        }
        $this->held[$event->mutexName()] = $lock;

        return true;
    }

    /** {@inheritDoc} */
    public function exists(Event $event): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task) {
            return $this->laravelDefault->exists($event);
        }

        try {
            return $this->mutex->isLocked(LockKey::forTask($task->id));
        } catch (LockUnavailableException) {
            return false;
        }
    }

    /** {@inheritDoc} */
    public function forget(Event $event): void
    {
        if (null === $this->index->get($event->mutexName())) {
            $this->laravelDefault->forget($event);

            return;
        }
        $lock = $this->held[$event->mutexName()] ?? null;
        unset($this->held[$event->mutexName()]);
        try {
            $lock?->release();
        } catch (LockUnavailableException) {
            // The lock expires on its own TTL.
        }
    }
}
