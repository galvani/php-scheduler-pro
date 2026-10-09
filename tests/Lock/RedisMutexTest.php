<?php declare(strict_types=1);

namespace Scheduling\Tests\Lock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\RedisMutex;
use Scheduling\Task\TaskId;
use Scheduling\Tests\RedisConnection;

final class RedisMutexTest extends TestCase
{
    private Redis $redis;

    private LockKey $key;

    /**
     * @throws InvalidTaskException
     * @throws RedisException
     */
    protected function setUp(): void
    {
        $this->redis = RedisConnection::open();
        $this->key = LockKey::forTask(new TaskId('test-'.bin2hex(random_bytes(6))));
    }

    protected function tearDown(): void
    {
        $this->redis->del($this->key->value);
    }

    /** @throws LockUnavailableException */
    public function testAcquireHoldsTheKeyWithATtl(): void
    {
        $lock = new RedisMutex($this->redis)->acquire($this->key, 30);

        self::assertNotNull($lock);
        self::assertTrue(new RedisMutex($this->redis)->isLocked($this->key));
        self::assertEqualsWithDelta(time() + 30, $lock->expiresAt()->getTimestamp(), 2);
        self::assertEqualsWithDelta(30_000, $this->redis->pttl($this->key->value), 2_000);
    }

    /** @throws LockUnavailableException */
    public function testASecondAcquireOfAHeldKeyReturnsNull(): void
    {
        $mutex = new RedisMutex($this->redis);
        $mutex->acquire($this->key, 30);

        self::assertNull($mutex->acquire($this->key, 30));
    }

    /** @throws LockUnavailableException */
    public function testAKeyIsNotLockedUntilAcquired(): void
    {
        self::assertFalse(new RedisMutex($this->redis)->isLocked($this->key));
    }

    /** @throws LockUnavailableException */
    public function testReleaseFreesTheKeyForTheNextHolder(): void
    {
        $mutex = new RedisMutex($this->redis);
        $lock = $mutex->acquire($this->key, 30);
        self::assertNotNull($lock);

        $lock->release();

        self::assertFalse($mutex->isLocked($this->key));
        self::assertNotNull($mutex->acquire($this->key, 30));
    }

    /** @throws LockUnavailableException */
    public function testReleaseOfALostLockLeavesTheNewOwnerAlone(): void
    {
        $mutex = new RedisMutex($this->redis);
        $stale = $mutex->acquire($this->key, 30);
        self::assertNotNull($stale);
        $this->redis->del($this->key->value);
        self::assertNotNull($mutex->acquire($this->key, 30));

        $stale->release();

        self::assertTrue($mutex->isLocked($this->key));
    }

    /** @throws LockUnavailableException */
    public function testExtendPushesTheExpiryOut(): void
    {
        $lock = new RedisMutex($this->redis)->acquire($this->key, 5);
        self::assertNotNull($lock);
        $before = $lock->expiresAt();

        self::assertTrue($lock->extend(120));

        self::assertGreaterThan($before, $lock->expiresAt());
        self::assertEqualsWithDelta(120_000, $this->redis->pttl($this->key->value), 2_000);
    }

    /** @throws LockUnavailableException */
    public function testExtendOfALostLockReturnsFalse(): void
    {
        $mutex = new RedisMutex($this->redis);
        $lock = $mutex->acquire($this->key, 30);
        self::assertNotNull($lock);
        $this->redis->del($this->key->value);
        $expiry = $lock->expiresAt();

        self::assertFalse($lock->extend(120));

        self::assertEquals($expiry, $lock->expiresAt());
        self::assertFalse($mutex->isLocked($this->key));
    }

    /** @throws LockUnavailableException */
    public function testExtendDoesNotStealALockHeldByAnotherOwner(): void
    {
        $mutex = new RedisMutex($this->redis);
        $stale = $mutex->acquire($this->key, 30);
        self::assertNotNull($stale);
        $this->redis->del($this->key->value);
        $mutex->acquire($this->key, 5);

        self::assertFalse($stale->extend(500));

        self::assertLessThan(6_000, $this->redis->pttl($this->key->value));
    }

    /** @throws LockUnavailableException */
    public function testTheExpiryIsInTheFuture(): void
    {
        $lock = new RedisMutex($this->redis)->acquire($this->key, 30);

        self::assertNotNull($lock);
        self::assertGreaterThan(new DateTimeImmutable(), $lock->expiresAt());
    }
}
