<?php declare(strict_types=1);

namespace Scheduling\Lock;

use Scheduling\Exception\LockUnavailableException;

/**
 * Our own lock, replacing Laravel's cache-based one. Generic: the caller picks the key (LockKey)
 * and the lifetime. The bridges use it for both scheduler locks: the overlap lock per task and the
 * one-server claim per task and minute. A reliable implementation takes the key atomically, gives
 * each holder its own token, and lets the key expire if the holder dies.
 */
interface MutexInterface
{
    /**
     * Atomic: either this call holds the key afterwards, or it returns null.
     *
     * @param positive-int $ttlSeconds the key frees itself after this, even if the holder never releases it
     *
     * @return LockInterface|null null when another holder has the key
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error; callers skip
     *                                  the run rather than run unlocked
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface;

    /**
     * Whether any holder has the key now; used by engines that ask before they lock.
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function isLocked(LockKey $key): bool;
}
