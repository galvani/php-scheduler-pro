<?php declare(strict_types=1);

namespace Scheduling\Lock;

interface MutexInterface
{
    /**
     * Atomic: either this call holds the key afterwards, or it returns null.
     *
     * @param positive-int $ttlSeconds
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface;

    public function isLocked(LockKey $key): bool;
}
