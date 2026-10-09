<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use DateTimeImmutable;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\PersistingStoreInterface;

/**
 * One instance per task. crunz names its lock after an md5 of the command; this store ignores that
 * name and locks the core-built key instead, so crunz and Laravel servers exclude each other.
 */
final readonly class MutexStore implements PersistingStoreInterface
{
    private const INITIAL_TTL = 120;

    public function __construct(
        private MutexInterface $mutex,
        private LockKey $key,
    ) {}

    /**
     * {@inheritDoc}
     *
     * @throws LockConflictedException
     */
    public function save(Key $key): void
    {
        $lock = $this->mutex->acquire($this->key, self::INITIAL_TTL);
        if (null === $lock) {
            throw new LockConflictedException();
        }
        $key->setState(self::class, $lock);
    }

    /** {@inheritDoc} */
    public function delete(Key $key): void
    {
        $this->heldBy($key)?->release();
        $key->removeState(self::class);
    }

    /** {@inheritDoc} */
    public function exists(Key $key): bool
    {
        $lock = $this->heldBy($key);

        return null !== $lock && $lock->expiresAt() > new DateTimeImmutable();
    }

    /**
     * {@inheritDoc}
     *
     * @throws LockConflictedException
     */
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $lock = $this->heldBy($key);
        if (null === $lock || !$lock->extend(max(1, (int) ceil($ttl)))) {
            throw new LockConflictedException();
        }
    }

    private function heldBy(Key $key): ?LockInterface
    {
        $state = $key->hasState(self::class) ? $key->getState(self::class) : null;

        return $state instanceof LockInterface ? $state : null;
    }
}
