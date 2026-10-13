<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Crunz\MutexStore;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\Task\TaskId;
use Symfony\Component\Lock\Exception\LockAcquiringException;
use Symfony\Component\Lock\Exception\LockConflictedException;
use Symfony\Component\Lock\Exception\LockExpiredException;
use Symfony\Component\Lock\Exception\LockReleasingException;
use Symfony\Component\Lock\Key;
use Symfony\Component\Lock\Lock;

/** Isolates MutexStore from Redis: the mutex and the locks it hands out are mocks. */
final class MutexStoreUnitTest extends TestCase
{
    private TaskId $task;

    /** @throws InvalidTaskException */
    protected function setUp(): void
    {
        $this->task = new TaskId('job');
    }

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

    /** @throws LockConflictedException */
    public function testSaveSetsTheKeyLifetimeToTwoMinutes(): void
    {
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('acquire')->willReturn($this->createStub(LockInterface::class));
        $key = new Key('resource');

        $this->store($mutex)->save($key);

        self::assertEqualsWithDelta(120, $key->getRemainingLifetime(), 1);
    }

    /** @throws LockConflictedException */
    public function testSaveExtendsTheLockTheKeyAlreadyHolds(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->with(120)->willReturn(true);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->never())->method('acquire');
        $key = $this->keyHolding($lock);

        $this->store($mutex)->save($key);

        self::assertSame($lock, $key->getState(MutexStore::class));
        self::assertEqualsWithDelta(120, $key->getRemainingLifetime(), 1);
    }

    /** @throws LockConflictedException */
    public function testSaveAcquiresAgainWhenTheHeldLockWasLost(): void
    {
        $lost = $this->createMock(LockInterface::class);
        $lost->expects($this->once())->method('extend')->willReturn(false);
        $fresh = $this->createStub(LockInterface::class);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forExternal('job'), 120)->willReturn($fresh);
        $key = $this->keyHolding($lost);

        $this->store($mutex)->save($key);

        self::assertSame($fresh, $key->getState(MutexStore::class));
    }

    public function testSaveConflictsWhenTheLockIsTaken(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn(null);
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($this->task, $this->isInstanceOf(DateTimeImmutable::class), SkipReason::StillRunning);
        $key = new Key('resource');

        try {
            $this->store($mutex, $listener)->save($key);
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
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($this->task, $this->isInstanceOf(DateTimeImmutable::class), SkipReason::LockUnavailable);
        $key = new Key('resource');

        $this->expectException(LockConflictedException::class);

        $this->store($mutex, $listener)->save($key);
    }

    /** @throws LockConflictedException */
    public function testSaveRecordsNoSkipWhenTheHeldLockWasLostAndTheKeyIsTaken(): void
    {
        $lost = $this->createMock(LockInterface::class);
        $lost->expects($this->once())->method('extend')->willReturn(false);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn(null);
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->never())->method('skipped');

        $this->expectException(LockConflictedException::class);

        $this->store($mutex, $listener)->save($this->keyHolding($lost));
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
    public function testPutOffExpirationSetsTheKeyLifetime(): void
    {
        $lock = $this->createStub(LockInterface::class);
        $lock->method('extend')->willReturn(true);
        $key = $this->keyHolding($lock);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($key, 5.0);

        self::assertEqualsWithDelta(5, $key->getRemainingLifetime(), 1);
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
    public function testPutOffExpirationKeepsTheLockWhenStorageIsUnavailable(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('extend')->willThrowException(new LockUnavailableException('Redis is down'));
        $lock->method('expiresAt')->willReturn(new DateTimeImmutable('+20 seconds'));
        $key = $this->keyHolding($lock);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration($key, 120.0);

        self::assertSame($lock, $key->getState(MutexStore::class));
        self::assertEqualsWithDelta(20, $key->getRemainingLifetime(), 1);
    }

    /**
     * crunz calls Lock::refresh() from its run loop and does not catch; a throw there ends crunz and every task it
     * started. A Redis blip must leave a lifetime crunz retries on, never one Symfony reports as expired.
     *
     * @throws LockAcquiringException
     * @throws LockConflictedException
     * @throws LockExpiredException
     */
    public function testRefreshThroughASymfonyLockSurvivesUnreachableStorageAndRetries(): void
    {
        $extensions = 0;
        $held = $this->createStub(LockInterface::class);
        $held->method('extend')->willReturnCallback(static function () use (&$extensions): bool {
            if (++$extensions > 1) {
                throw new LockUnavailableException('Redis is down');
            }

            return true;
        });
        $held->method('expiresAt')->willReturn(new DateTimeImmutable('-5 seconds'));
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('acquire')->willReturn($held);
        $lock = new Lock(new Key('resource'), $this->store($mutex), 120);
        self::assertTrue($lock->acquire());

        $lock->refresh();

        self::assertEqualsWithDelta(1, $lock->getRemainingLifetime(), 0.5);
        self::assertFalse($lock->isExpired());
    }

    /** @throws LockConflictedException */
    public function testPutOffExpirationConflictsWithoutALock(): void
    {
        $this->expectException(LockConflictedException::class);

        $this->store($this->createStub(MutexInterface::class))->putOffExpiration(new Key('resource'), 5.0);
    }

    private function store(MutexInterface $mutex, ?RunListenerInterface $listener = null): MutexStore
    {
        return new MutexStore($mutex, LockKey::forExternal('job'), $listener ?? $this->createStub(RunListenerInterface::class), $this->task);
    }

    private function keyHolding(LockInterface $lock): Key
    {
        $key = new Key('resource');
        $key->setState(MutexStore::class, $lock);

        return $key;
    }
}
