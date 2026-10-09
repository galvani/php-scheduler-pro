<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;

/**
 * A lock held in Redis, created by RedisMutex. Release and extend run as Lua scripts that act only when the stored
 * value equals this lock's token, so a holder whose key expired cannot delete or prolong the next holder's lock.
 */
final class RedisLock implements LockInterface
{
    private const RELEASE = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('del', KEYS[1]) end
        return 0
        LUA;

    private const EXTEND = <<<'LUA'
        if redis.call('get', KEYS[1]) == ARGV[1] then return redis.call('pexpire', KEYS[1], ARGV[2]) end
        return 0
        LUA;

    /**
     * @param string            $token     the random value stored under the key by RedisMutex
     * @param DateTimeImmutable $expiresAt when the key frees itself, as computed at acquisition
     */
    public function __construct(
        private readonly Redis $redis,
        private readonly LockKey $key,
        private readonly string $token,
        private DateTimeImmutable $expiresAt,
    ) {}

    /** {@inheritDoc} */
    public function expiresAt(): DateTimeImmutable
    {
        return $this->expiresAt;
    }

    /**
     * {@inheritDoc}
     *
     * Resets the expiry to $ttlSeconds from now when the token still matches; false when the key expired or changed hands.
     *
     * @throws LockUnavailableException when Redis cannot be reached
     */
    public function extend(int $ttlSeconds): bool
    {
        if (1 !== $this->run(self::EXTEND, [$this->key->value, $this->token, $ttlSeconds * 1000])) {
            return false;
        }
        $this->expiresAt = new DateTimeImmutable()->setTimestamp(time() + $ttlSeconds);

        return true;
    }

    /**
     * {@inheritDoc}
     *
     * Deletes the key only when the token matches; otherwise does nothing.
     *
     * @throws LockUnavailableException when Redis cannot be reached
     */
    public function release(): void
    {
        $this->run(self::RELEASE, [$this->key->value, $this->token]);
    }

    /**
     * Runs a Lua script with one key.
     *
     * @param list<string|int> $arguments the key first, then the script's ARGV
     *
     * @throws LockUnavailableException
     */
    private function run(string $script, array $arguments): mixed
    {
        try {
            return $this->redis->eval($script, $arguments, 1);
        } catch (RedisException $e) {
            throw new LockUnavailableException("Redis unreachable for {$this->key->value}", previous: $e);
        }
    }
}
