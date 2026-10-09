<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use DateTimeImmutable;
use Scheduling\Exception\LockUnavailableException;
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
     * Stores the resource if it is not locked by someone else.
     *
     * @throws LockConflictedException
     */
    public function save(Key $key): void
    {
        try {
            $lock = $this->mutex->acquire($this->key, self::INITIAL_TTL);
        } catch (LockUnavailableException) {
            $lock = null;
        }
        if (null === $lock) {
            throw new LockConflictedException();
        }
        $key->setState(self::class, $lock);
    }

    /**
     * Removes the resource from the storage if the given key owns it.
     *
     * Does nothing when the lock is owned by another key.
     */
    public function delete(Key $key): void
    {
        try {
            $this->heldBy($key)?->release();
        } catch (LockUnavailableException) {
            // The lock expires on its own TTL.
        }
        $key->removeState(self::class);
    }

    /**
     * Returns whether the given key owns the lock on the resource.
     */
    public function exists(Key $key): bool
    {
        $lock = $this->heldBy($key);

        return null !== $lock && $lock->expiresAt() > new DateTimeImmutable();
    }

    /**
     * Extends the TTL of a resource.
     *
     * @param float $ttl amount of seconds to keep the lock in the store
     *
     * @throws LockConflictedException
     */
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $lock = $this->heldBy($key);
        try {
            $extended = null !== $lock && $lock->extend(max(1, (int) ceil($ttl)));
        } catch (LockUnavailableException) {
            $extended = false;
        }
        if (!$extended) {
            throw new LockConflictedException();
        }
    }

    private function heldBy(Key $key): ?LockInterface
    {
        $state = $key->hasState(self::class) ? $key->getState(self::class) : null;

        return $state instanceof LockInterface ? $state : null;
    }
}
