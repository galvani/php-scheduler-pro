<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Scheduling\Exception\LockUnavailableException;

interface LockInterface
{
    public function expiresAt(): DateTimeImmutable;

    /**
     * @param positive-int $ttlSeconds
     *
     * @return bool false when the lock was already lost (expired or taken by another holder)
     *
     * @throws LockUnavailableException
     */
    public function extend(int $ttlSeconds): bool;

    /**
     * Releases only if this holder still owns the key; otherwise a no-op.
     *
     * @throws LockUnavailableException
     */
    public function release(): void;
}
