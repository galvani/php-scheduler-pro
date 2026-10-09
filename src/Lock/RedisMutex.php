<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;

final readonly class RedisMutex implements MutexInterface
{
    public function __construct(private Redis $redis) {}

    /** {@inheritDoc} */
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

    /** {@inheritDoc} */
    public function isLocked(LockKey $key): bool
    {
        try {
            return $this->redis->exists($key->value) > 0;
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable while checking {$key->value}", previous: $e);
        }
    }
}
