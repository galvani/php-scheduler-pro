<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\CacheAware;
use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;

/**
 * Laravel's overlap-lock slot (`withoutOverlapping()`), answered by our mutex for every task in the schedule.
 *
 * - A task of this package locks `scheduler:task:<id>` for its max_runtime × margin, and its skips are recorded:
 *   `exists()` answers true for a held lock and Laravel's `ScheduledTaskSkipped` is recorded by SkippedSubscriber;
 *   a `create()` that loses the race after `exists()` answered false dispatches no event, so it records the skip itself.
 * - A task from the app's own schedule locks `scheduler:external:<Laravel's mutex name>` for the minutes the app
 *   gave `withoutOverlapping()`, so the app's existing tasks get the reliable lock without a change to its code.
 * - A task from the app's own schedule that runs in the background stays on Laravel's cache lock: Laravel
 *   releases it from a separate `schedule:finish` process that never held our owner token, and our mutex
 *   releases only by token. That cache lock uses the store the app picked for the schedule
 *   (`cache.schedule_store`, `SCHEDULE_CACHE_STORE`): Laravel hands it over through useStore().
 */
final class LaravelEventMutex implements EventMutex, CacheAware
{
    /** @var array<string, LockInterface> locks this process holds, by Laravel's mutex name */
    private array $held = [];

    /**
     * @param MutexInterface       $mutex          locks the package's tasks and the app's foreground tasks
     * @param TaskIndex            $index          tells the package's tasks from the app's own
     * @param CacheEventMutex      $laravelDefault Laravel's cache lock, kept for the app's background tasks
     * @param RunListenerInterface $listener       records the skips of the package's tasks
     * @param LoggerInterface      $logger         warns about an app task skipped for unreachable lock storage
     */
    public function __construct(
        private readonly MutexInterface $mutex,
        private readonly TaskIndex $index,
        private readonly CacheEventMutex $laravelDefault,
        private readonly RunListenerInterface $listener,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * Attempts to obtain an event mutex for the given event.
     */
    public function create(Event $event): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task && $event->runInBackground) {
            return $this->laravelDefault->create($event);
        }
        try {
            $lock = null !== $task
                ? $this->mutex->acquire(LockKey::forTask($task->id), $task->lock->ttlSeconds)
                : $this->mutex->acquire(self::externalKey($event), max(1, $event->expiresAt * 60));
        } catch (LockUnavailableException $e) {
            if (null !== $task) {
                $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::LockUnavailable);
            } else {
                $this->logger->warning('Lock storage unreachable; the run is skipped', ['mutex' => $event->mutexName(), 'exception' => $e]);
            }

            return false;
        }
        if (null === $lock) {
            if (null !== $task) {
                $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::StillRunning);
            }

            return false;
        }
        $this->held[$event->mutexName()] = $lock;

        return true;
    }

    /**
     * Determines whether an event mutex exists for the given event.
     */
    public function exists(Event $event): bool
    {
        $task = $this->index->get($event->mutexName());
        if (null === $task && $event->runInBackground) {
            return $this->laravelDefault->exists($event);
        }
        try {
            return $this->mutex->isLocked(null !== $task ? LockKey::forTask($task->id) : self::externalKey($event));
        } catch (LockUnavailableException) {
            return false;
        }
    }

    /**
     * Clears the event mutex for the given event.
     */
    public function forget(Event $event): void
    {
        if (null === $this->index->get($event->mutexName()) && $event->runInBackground) {
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

    /**
     * Specify the cache store that should be used.
     *
     * Forwarded to Laravel's cache lock, the only part of this mutex that uses the cache: the app's background
     * tasks then lock in the schedule's store, shared by every server, instead of the default cache store.
     *
     * @param string $store the name of a store in config/cache.php
     *
     * @return $this
     */
    public function useStore($store): static
    {
        $this->laravelDefault->useStore($store);

        return $this;
    }

    private static function externalKey(Event $event): LockKey
    {
        return LockKey::forExternal($event->mutexName());
    }
}
