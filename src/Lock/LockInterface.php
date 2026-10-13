<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Scheduling\Exception\LockUnavailableException;

/**
 * One holder's lock on one key, returned by MutexInterface::acquire(). It carries the holder's token,
 * so it can only ever extend or release its own lock, never one another holder took after it expired.
 */
interface LockInterface
{
    /** When the key frees itself unless extended. */
    public function expiresAt(): DateTimeImmutable;

    /**
     * Keeps the lock for a long run. crunz extends it while a task runs; Laravel's lock slot cannot.
     *
     * @param positive-int $ttlSeconds the new lifetime, counted from now
     *
     * @return bool false when the lock was already lost (expired or taken by another holder)
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function extend(int $ttlSeconds): bool;

    /**
     * Releases only if this holder still owns the key; otherwise a no-op.
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function release(): void;
}
