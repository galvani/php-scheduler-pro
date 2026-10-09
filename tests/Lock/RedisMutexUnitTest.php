<?php declare(strict_types=1);

namespace Scheduling\Tests\Lock;

use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\RedisMutex;

/** Isolates RedisMutex from a Redis server: phpredis is a mock and the exact commands are verified. */
final class RedisMutexUnitTest extends TestCase
{
    private const string KEY = 'scheduler:external:job';

    /** @throws LockUnavailableException */
    public function testAcquireSetsTheKeyWithNxAndMillisecondTtl(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('set')
            ->with(self::KEY, $this->matchesRegularExpression('/^[0-9a-f]{32}$/'), ['nx', 'px' => 90000])
            ->willReturn(true);

        $before = time();
        $lock = new RedisMutex($redis)->acquire(LockKey::forExternal('job'), 90);

        self::assertNotNull($lock);
        self::assertEqualsWithDelta($before + 90, $lock->expiresAt()->getTimestamp(), 2);
    }

    /** @throws LockUnavailableException */
    public function testAcquireReturnsNullWhenTheKeyIsTaken(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('set')->willReturn(false);

        self::assertNull(new RedisMutex($redis)->acquire(LockKey::forExternal('job'), 90));
    }

    public function testAcquireMapsRedisFailureToLockUnavailable(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('set')->willThrowException($failure);

        try {
            new RedisMutex($redis)->acquire(LockKey::forExternal('job'), 90);
            self::fail('LockUnavailableException expected');
        } catch (LockUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString(self::KEY, $e->getMessage());
        }
    }

    /** @throws LockUnavailableException */
    public function testAcquireUsesAFreshTokenEachTime(): void
    {
        $tokens = [];
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->exactly(2))->method('set')->willReturnCallback(
            static function (string $key, mixed $token) use (&$tokens): bool {
                $tokens[] = $token;

                return true;
            },
        );
        $mutex = new RedisMutex($redis);

        $mutex->acquire(LockKey::forExternal('job'), 10);
        $mutex->acquire(LockKey::forExternal('job'), 10);

        self::assertCount(2, $tokens);
        self::assertNotSame($tokens[0], $tokens[1]);
    }

    /** @throws LockUnavailableException */
    public function testIsLockedAsksRedisWhetherTheKeyExists(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->exactly(2))->method('exists')->with(self::KEY)->willReturnOnConsecutiveCalls(1, 0);
        $mutex = new RedisMutex($redis);

        self::assertTrue($mutex->isLocked(LockKey::forExternal('job')));
        self::assertFalse($mutex->isLocked(LockKey::forExternal('job')));
    }

    public function testIsLockedMapsRedisFailureToLockUnavailable(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('exists')->willThrowException($failure);

        try {
            new RedisMutex($redis)->isLocked(LockKey::forExternal('job'));
            self::fail('LockUnavailableException expected');
        } catch (LockUnavailableException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString(self::KEY, $e->getMessage());
        }
    }
}
