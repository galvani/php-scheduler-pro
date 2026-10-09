<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Crunz\MutexStore;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Key;

/** Isolates MutexStore from Redis: the mutex and the locks it hands out are mocks. */
final class MutexStoreUnitTest extends TestCase
{
    /** @throws LockConflictedException */
    public function testSaveAcquiresTheKeyForTwoMinutesAndKeepsTheLock(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forExternal('job'), 120)->willReturn($lock);
        $key = new Key('resource');

        $this->store($mutex)->save($key);

        self::assertTrue($key->hasState(MutexStore::class));
        self::assertSame($lock, $key->getState(MutexStore::class));
    }

    public function testSaveConflictsWhenTheLockIsTaken(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn(null);
        $key = new Key('resource');

        try {
            $this->store($mutex)->save($key);
            self::fail('LockConflictedException expected');
        } catch (LockConflictedException) {
            self::assertFalse($key->hasState(MutexStore::class));
        }
    }

    /** @throws LockConflictedException */
    public function testSaveConflictsWhenLockStorageIsUnavailable(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willThrowException(new LockUnavailableException('Redis is down'));
        $key = new Key('resource');

        $this->expectException(LockConflictedException::class);

        $this->store($mutex)->save($key);
    }

    /** @throws LockReleasingException */
    public function testDeleteReleasesTheHeldLockAndDropsTheState(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('release');
        $key = $this->keyHolding($lock);

        $this->store($this->createStub(MutexInterface::class))->delete($key);

        self::assertFalse($key->hasState(MutexStore::class));
    }

    /** @throws LockReleasingException */
    public function testDeleteWithoutALockDoesNothing(): void
    {
        $key = new Key('resource');

        $this->store($this->createStub(MutexInterface::class))->delete($key);

        self::assertFalse($key->hasState(MutexStore::class));
    }

    /** @throws LockReleasingException */
    public function testDeleteSwallowsUnavailableStorageAndStillDropsTheState(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('release')->willThrowException(new LockUnavailableException('Redis is down'));
        $key = $this->keyHolding($lock);

        $this->store($this->createStub(MutexInterface::class))->delete($key);

        self::assertFalse($key->hasState(MutexStore::class));
    }

    public function testExistsIsTrueForALockThatHasNotExpired(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('expiresAt')->willReturn(new DateTimeImmutable('+1 minute'));

        self::assertTrue($this->store($this->createStub(MutexInterface::class))->exists($this->keyHolding($lock)));
    }

    public function testExistsIsFalseForAnExpiredLock(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('expiresAt')->willReturn(new DateTimeImmutable('-1 minute'));

        self::assertFalse($this->store($this->createStub(MutexInterface::class))->exists($this->keyHolding($lock)));
    }

    public function testExistsIsFalseWithoutALock(): void
    {
        self::assertFalse($this->store($this->createStub(MutexInterface::class))->exists(new Key('resource')));
    }

    /**
     * @throws LockConflictedException
     */
    public function testPutOffExpirationExtendsByTheRoundedUpTtl(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->with(4)->willReturn(true);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($this->keyHolding($lock), 3.2);
    }

    /**
     * @throws LockConflictedException
     */
    public function testPutOffExpirationExtendsByAtLeastOneSecond(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->with(1)->willReturn(true);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($this->keyHolding($lock), 0.0);
    }

    /** @throws LockConflictedException */
    public function testPutOffExpirationConflictsWhenTheLockWasLost(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->willReturn(false);

        $this->expectException(LockConflictedException::class);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($this->keyHolding($lock), 5.0);
    }

    /** @throws LockConflictedException */
    public function testPutOffExpirationConflictsWhenStorageIsUnavailable(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->willThrowException(new LockUnavailableException('Redis is down'));

        $this->expectException(LockConflictedException::class);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($this->keyHolding($lock), 5.0);
    }

    /** @throws LockConflictedException */
    public function testPutOffExpirationConflictsWithoutALock(): void
    {
        $this->expectException(LockConflictedException::class);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration(new Key('resource'), 5.0);
    }

    private function store(MutexInterface $mutex): MutexStore
    {
        return new MutexStore($mutex, LockKey::forExternal('job'));
    }

    private function keyHolding(LockInterface $lock): Key
    {
        $key = new Key('resource');
        $key->setState(MutexStore::class, $lock);

        return $key;
    }
}
