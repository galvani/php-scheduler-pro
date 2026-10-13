<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Closure;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;

/**
 * The mutex handed to Laravel's lock slots, resolved on the first lock call instead of when Laravel builds its
 * Schedule. Laravel builds the Schedule, and with it both lock slots, on every artisan command; resolving the
 * mutex there would connect to Redis, and fail on a misconfigured connection, for `migrate` and `config:clear` too.
 */
final class LazyMutex implements MutexInterface
{
    private ?MutexInterface $mutex = null;

    /** @param Closure(): MutexInterface $resolve builds the real mutex; called once, on the first lock call */
    public function __construct(private readonly Closure $resolve) {}

    /**
     * Atomic: either this call holds the key afterwards, or it returns null.
     *
     * Resolves the real mutex first; a misconfigured connection throws from here, unchecked.
     *
     * @param positive-int $ttlSeconds the key frees itself after this, even if the holder never releases it
     *
     * @return LockInterface|null null when another holder has the key
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error; callers skip
     *                                  the run rather than run unlocked
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface
    {
        return $this->mutex()->acquire($key, $ttlSeconds);
    }

    /**
     * Whether any holder has the key now; used by engines that ask before they lock.
     *
     * Resolves the real mutex first; a misconfigured connection throws from here, unchecked.
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function isLocked(LockKey $key): bool
    {
        return $this->mutex()->isLocked($key);
    }

    private function mutex(): MutexInterface
    {
        return $this->mutex ??= ($this->resolve)();
    }
}
