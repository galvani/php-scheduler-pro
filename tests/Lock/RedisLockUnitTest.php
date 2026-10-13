<?php declare(strict_types=1);

namespace Scheduling\Tests\Lock;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\RedisLock;

/** Isolates RedisLock from a Redis server: phpredis is a mock and the exact script calls are verified. */
final class RedisLockUnitTest extends TestCase
{
    private const string KEY = 'scheduler:external:job';

    private const string TOKEN = 'token-1';

    /** @throws LockUnavailableException */
    public function testExtendRunsThePexpireScriptWithKeyTokenAndMilliseconds(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with($this->stringContains('pexpire'), [self::KEY, self::TOKEN, 30000], 1)
            ->willReturn(1);
        $lock = $this->lock($redis, new DateTimeImmutable('2020-01-01'));

        $before = time();

        self::assertTrue($lock->extend(30));
        self::assertEqualsWithDelta($before + 30, $lock->expiresAt()->getTimestamp(), 2);
    }

    /** @throws LockUnavailableException */
    public function testExtendReturnsFalseAndKeepsExpiryWhenTheScriptReturnsZero(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('eval')->willReturn(0);
        $expiresAt = new DateTimeImmutable('2020-01-01');
        $lock = $this->lock($redis, $expiresAt);

        self::assertFalse($lock->extend(30));
        self::assertSame($expiresAt, $lock->expiresAt());
    }

    public function testExtendMapsRedisFailureToLockUnavailable(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('eval')->willThrowException($failure);

        try {
            $this->lock($redis, new DateTimeImmutable())->extend(30);
            self::fail('LockUnavailableException expected');
        } catch (LockUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString(self::KEY, $e->getMessage());
        }
    }

    /** @throws LockUnavailableException */
    public function testReleaseRunsTheDelScriptWithKeyAndToken(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('eval')
            ->with($this->stringContains('del'), [self::KEY, self::TOKEN], 1)
            ->willReturn(1);

        $this->lock($redis, new DateTimeImmutable())->release();
    }

    /** @throws LockUnavailableException */
    public function testReleaseOfAnAlreadyLostLockIsSilent(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('eval')->willReturn(0);

        $this->lock($redis, new DateTimeImmutable())->release();
    }

    public function testReleaseMapsRedisFailureToLockUnavailable(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('eval')->willThrowException($failure);

        try {
            $this->lock($redis, new DateTimeImmutable())->release();
            self::fail('LockUnavailableException expected');
        } catch (LockUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString(self::KEY, $e->getMessage());
        }
    }

    /** @throws LockUnavailableException */
    public function testExtendMapsAnErrorReplyToLockUnavailable(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('clearLastError')->willReturn(true);
        $redis->expects($this->once())->method('eval')->willReturn(false);
        $redis->method('getLastError')->willReturn('WRONGTYPE Operation against a key holding the wrong kind of value');

        $this->expectException(LockUnavailableException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $this->lock($redis, new DateTimeImmutable())->extend(30);
    }

    /** @throws LockUnavailableException */
    public function testReleaseMapsAnErrorReplyToLockUnavailable(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('clearLastError')->willReturn(true);
        $redis->expects($this->once())->method('eval')->willReturn(false);
        $redis->method('getLastError')->willReturn('WRONGTYPE Operation against a key holding the wrong kind of value');

        $this->expectException(LockUnavailableException::class);
        $this->expectExceptionMessage('WRONGTYPE');

        $this->lock($redis, new DateTimeImmutable())->release();
    }

    public function testExpiresAtReturnsTheGivenInstant(): void
    {
        $expiresAt = new DateTimeImmutable('2030-05-06 07:08:09');

        self::assertSame($expiresAt, $this->lock($this->createStub(Redis::class), $expiresAt)->expiresAt());
    }

    private function lock(Redis $redis, DateTimeImmutable $expiresAt): RedisLock
    {
        return new RedisLock($redis, LockKey::forExternal('job'), self::TOKEN, $expiresAt);
    }
}
