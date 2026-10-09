<?php declare(strict_types=1);

namespace Scheduling\Lock;

use DateTimeImmutable;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;

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

    /** {@inheritDoc} */
    public function extend(int $ttlSeconds): bool
    {
        if (1 !== $this->run(self::EXTEND, [$this->key->value, $this->token, $ttlSeconds * 1000])) {
            return false;
        }
        $this->expiresAt = new DateTimeImmutable()->setTimestamp(time() + $ttlSeconds);

        return true;
    }

    /** {@inheritDoc} */
    public function release(): void
    {
        $this->run(self::RELEASE, [$this->key->value, $this->token]);
    }

    /**
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
