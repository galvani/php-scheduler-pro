<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;

/**
 * MutexInterface on Redis. Each acquisition gets a random token; the lock holds it so only the holder can release
 * or extend the key. When Redis is unreachable it throws LockUnavailableException instead of reporting "free".
 */
final readonly class RedisMutex implements MutexInterface
{
    /** @param Redis $redis a connected phpredis client */
    public function __construct(private Redis $redis) {}

    /**
     * {@inheritDoc}
     *
     * One atomic `SET key token NX PX ttl`: it sets the key only when it does not exist, with a millisecond expiry.
     */
    public function acquire(LockKey $key, int $ttlSeconds): ?LockInterface
    {
        $token = bin2hex(random_bytes(16));
        try {
            $acquired = $this->redis->set($key->value, $token, ['nx', 'px' => $ttlSeconds * 1000]);
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable while locking {$key->value}", previous: $e);
        }

        return true === $acquired
            ? new RedisLock($this->redis, $key, $token, new DateTimeImmutable()->setTimestamp(time() + $ttlSeconds))
            : null;
    }

    /**
     * {@inheritDoc}
     *
     * Checks that the key exists; the token is not compared.
     */
    public function isLocked(LockKey $key): bool
    {
        try {
            return $this->redis->exists($key->value) > 0;
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable while checking {$key->value}", previous: $e);
        }
    }
}
