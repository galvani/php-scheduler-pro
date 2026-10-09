<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;

final class LaravelEventMutex implements EventMutex
{
    /** @var array<string, LockInterface> */
    private array $held = [];

    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
        private readonly CacheEventMutex $laravelDefault,
    ) {}

    /** {@inheritDoc} */
    public function create(Event $event): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task) {
            return $this->laravelDefault->create($event);
        }
        $lock = $this->mutex->acquire(LockKey::forTask($task->id), $task->lock->ttlSeconds);
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

        return $this->mutex->isLocked(LockKey::forTask($task->id));
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
        $lock?->release();
    }
}
