<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;

interface LockInterface
{
    public function expiresAt(): DateTimeImmutable;

    /**
     * @param positive-int $ttlSeconds
     *
     * @return bool false when the lock was already lost (expired or taken by another holder)
     */
    public function extend(int $ttlSeconds): bool;

    /** Releases only if this holder still owns the key; otherwise a no-op. */
    public function release(): void;
}
