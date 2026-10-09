<?php declare(strict_types=1);

namespace Scheduling\Lock;

use Scheduling\Exception\LockUnavailableException;

interface MutexInterface
{
    /**
     * Atomic: either this call holds the key afterwards, or it returns null.
     *
     * @param positive-int $ttlSeconds
     *
     * @throws LockUnavailableException
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface;

    /** @throws LockUnavailableException */
    public function isLocked(LockKey $key): bool;
}
