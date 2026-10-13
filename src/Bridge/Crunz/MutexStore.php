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
 *
 * crunz drives the store through a Symfony Lock with a 120-second TTL: it takes the lock in its skip filter, takes
 * it again when it starts the process, and refreshes it from its run loop once fewer than 30 seconds remain. Every
 * write therefore sets the Key's lifetime, because crunz refreshes only a lock whose remaining lifetime is known,
 * and a second save() by the holder extends its own lock instead of failing on it.
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
     * A Key that still holds the lock extends it to two minutes; a Key whose lock was lost, or that never held one,
     * acquires the core-built key for two minutes. Either way the Key's lifetime is set to those two minutes. A held
     * lock survives a lock storage failure, as in putOffExpiration(); a new one does not, so crunz skips that run.
     *
     * @throws LockConflictedException when another holder has the key, or a new lock meets unreachable lock storage
     */
    public function save(Key $key): void
    {
        $key->reduceLifetime(self::INITIAL_TTL);
        $held = $this->heldBy($key);
        if (null !== $held && $this->prolong($key, $held, self::INITIAL_TTL)) {
            return;
        }
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
     * Extends by $ttl rounded up to whole seconds, at least one, and sets the Key's lifetime to $ttl. When the lock
     * storage cannot be reached, nothing is thrown: the Key's lifetime drops to what the lock had left, at least one
     * second, so crunz refreshes again on its next pass instead of dying with every task it runs.
     *
     * @param float $ttl amount of seconds to keep the lock in the store
     *
     * @throws LockConflictedException when the Key holds no lock, or the lock was lost to expiry or another holder
     */
    public function putOffExpiration(Key $key, float $ttl): void
    {
        $key->reduceLifetime($ttl);
        $lock = $this->heldBy($key);
        if (null === $lock || !$this->prolong($key, $lock, $ttl)) {
            throw new LockConflictedException();
        }
    }

    /**
     * Extends the lock; false only when the lock is lost. A storage failure keeps the lock as held and shortens the
     * Key's lifetime to what the lock had left, at least one second, so the next refresh retries.
     */
    private function prolong(Key $key, LockInterface $lock, float $ttl): bool
    {
        try {
            return $lock->extend(max(1, (int) ceil($ttl)));
        } catch (LockUnavailableException) {
            $key->reduceLifetime(max(1, $lock->expiresAt()->getTimestamp() - time()));

            return true;
        }
    }

    private function heldBy(Key $key): ?LockInterface
    {
        $state = $key->hasState(self::class) ? $key->getState(self::class) : null;

        return $state instanceof LockInterface ? $state : null;
    }
}
