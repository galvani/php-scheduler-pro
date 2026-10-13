<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;

/**
 * MutexInterface on Redis. Each acquisition gets a random token; the lock holds it so only the holder can release
 * or extend the key. When Redis is unreachable or answers with an error it throws LockUnavailableException instead
 * of reporting "free" or "held by someone else".
 * Trap: for some error replies (WRONGTYPE, any `ERR ...`) phpredis returns false instead of throwing, the same false
 * a refused `SET NX` returns; only getLastError() tells them apart, so it is cleared before every command.
 */
final readonly class RedisMutex implements MutexInterface
{
    /** @param Redis $redis a connected phpredis client */
    public function __construct(private Redis $redis) {}

    /**
     * Atomic: either this call holds the key afterwards, or it returns null.
     *
     * One atomic `SET key token NX PX ttl`: it sets the key only when it does not exist, with a millisecond expiry.
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
        $token = bin2hex(random_bytes(16));
        try {
            $this->redis->clearLastError();
            $acquired = $this->redis->set($key->value, $token, ['nx', 'px' => $ttlSeconds * 1000]);
            $error = $this->redis->getLastError();
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable while locking {$key->value}", previous: $e);
        }
        if (null !== $error) {
            throw new LockUnavailableException("Redis refused to lock {$key->value}: {$error}");
        }

        return true === $acquired
            ? new RedisLock($this->redis, $key, $token, new DateTimeImmutable()->setTimestamp(time() + $ttlSeconds))
            : null;
    }

    /**
     * Whether any holder has the key now; used by engines that ask before they lock.
     *
     * Checks that the key exists; the token is not compared.
     *
     * @throws LockUnavailableException when the storage cannot be reached or answers with an error
     */
    public function isLocked(LockKey $key): bool
    {
        try {
            $this->redis->clearLastError();
            $exists = $this->redis->exists($key->value);
            $error = $this->redis->getLastError();
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable while checking {$key->value}", previous: $e);
        }
        if (null !== $error) {
            throw new LockUnavailableException("Redis refused to check {$key->value}: {$error}");
        }

        return $exists > 0;
    }
}
