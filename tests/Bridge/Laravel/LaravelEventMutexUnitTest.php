<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Console\Scheduling\CacheEventMutex;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Laravel\LaravelEventMutex;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Tests\Task\Tasks;

/** Isolates LaravelEventMutex: our mutex, Laravel's default mutex and the logger are mocks. */
final class LaravelEventMutexUnitTest extends TestCase
{
    private const string OWN_NAME = 'scheduler:task:report';

    private const string APP_NAME = 'app-job';

    public function testOwnTaskAcquiresItsTaskKeyForTheLockPolicyTtl(): void
    {
        $task = Tasks::make('report');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forTask($task->id), 900)->willReturn($this->createStub(LockInterface::class));

        self::assertTrue($this->subject($mutex)->create($this->event(self::OWN_NAME)));
    }

    public function testOwnTaskLosingTheLockRaceIsRefusedWithoutAWarning(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn(null);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        self::assertFalse($this->subject($mutex, logger: $logger)->create($this->event(self::OWN_NAME)));
    }

    public function testOwnTaskWithUnavailableStorageIsSkippedWithAWarningNamingTheTask(): void
    {
        $failure = new LockUnavailableException('Redis is down');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Lock storage unreachable; the run is skipped', ['task_id' => 'report', 'mutex' => self::OWN_NAME, 'exception' => $failure]);

        self::assertFalse($this->subject($mutex, logger: $logger)->create($this->event(self::OWN_NAME)));
    }

    public function testAppTaskAcquiresTheExternalKeyForItsExpiryInSeconds(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forExternal(self::APP_NAME), 300)->willReturn($this->createStub(LockInterface::class));

        self::assertTrue($this->subject($mutex, withTask: false)->create($this->event(self::APP_NAME)->withoutOverlapping(5)));
    }

    public function testAppTaskWithAHeldLockIsRefusedWithoutAWarning(): void
    {
        $mutex = $this->createStub(MutexInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        self::assertFalse($this->subject($mutex, logger: $logger, withTask: false)->create($this->event(self::APP_NAME)->withoutOverlapping(5)));
    }

    public function testAppTaskWithUnavailableStorageIsSkippedWithAWarningNamingTheMutex(): void
    {
        $failure = new LockUnavailableException('Redis is down');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Lock storage unreachable; the run is skipped', ['mutex' => self::APP_NAME, 'exception' => $failure]);

        self::assertFalse($this->subject($mutex, logger: $logger, withTask: false)->create($this->event(self::APP_NAME)));
    }

    public function testBackgroundAppTaskStaysOnLaravelsMutex(): void
    {
        $event = $this->event(self::APP_NAME)->runInBackground();
        $default = $this->createMock(CacheEventMutex::class);
        $default->expects($this->once())->method('create')->with($event)->willReturn(true);
        $default->expects($this->once())->method('exists')->with($event)->willReturn(true);
        $default->expects($this->once())->method('forget')->with($event);
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->never())->method($this->anything());
        $subject = $this->subject($mutex, laravelDefault: $default, withTask: false);

        self::assertTrue($subject->create($event));
        self::assertTrue($subject->exists($event));
        $subject->forget($event);
    }

    public function testBackgroundOwnTaskStillUsesOurMutex(): void
    {
        $default = $this->createMock(CacheEventMutex::class);
        $default->expects($this->never())->method($this->anything());
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn($this->createStub(LockInterface::class));

        self::assertTrue($this->subject($mutex, laravelDefault: $default)->create($this->event(self::OWN_NAME)->runInBackground()));
    }

    public function testForgetReleasesExactlyTheLockItAcquired(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('release');
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('acquire')->willReturn($lock);
        $subject = $this->subject($mutex);
        $event = $this->event(self::OWN_NAME);
        $subject->create($event);

        $subject->forget($event);
        $subject->forget($event);
    }

    public function testForgetWithoutAnAcquiredLockReleasesNothing(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->never())->method($this->anything());

        $this->subject($mutex)->forget($this->event(self::OWN_NAME));
    }

    public function testForgetSwallowsUnavailableStorage(): void
    {
        $lock = $this->createMock(LockInterface::class);
        $lock->expects($this->once())->method('release')->willThrowException(new LockUnavailableException('Redis is down'));
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('acquire')->willReturn($lock);
        $subject = $this->subject($mutex);
        $event = $this->event(self::OWN_NAME);
        $subject->create($event);

        $subject->forget($event);
    }

    public function testExistsAsksForTheTaskKeyOfAnOwnTask(): void
    {
        $task = Tasks::make('report');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->with(LockKey::forTask($task->id))->willReturn(true);

        self::assertTrue($this->subject($mutex)->exists($this->event(self::OWN_NAME)));
    }

    public function testExistsAsksForTheExternalKeyOfAnAppTask(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->with(LockKey::forExternal(self::APP_NAME))->willReturn(false);

        self::assertFalse($this->subject($mutex, withTask: false)->exists($this->event(self::APP_NAME)));
    }

    public function testExistsIsFalseWhenStorageIsUnavailable(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->willThrowException(new LockUnavailableException('Redis is down'));

        self::assertFalse($this->subject($mutex)->exists($this->event(self::OWN_NAME)));
    }

    public function testTheScheduleCacheStoreReachesLaravelsMutexForBackgroundAppTasks(): void
    {
        $default = $this->createMock(CacheEventMutex::class);
        $default->expects($this->once())->method('useStore')->with('redis')->willReturnSelf();
        $subject = $this->subject($this->createStub(MutexInterface::class), laravelDefault: $default);

        self::assertSame($subject, $subject->useStore('redis'));
    }

    private function subject(
        MutexInterface $mutex,
        ?CacheEventMutex $laravelDefault = null,
        ?LoggerInterface $logger = null,
        bool $withTask = true,
    ): LaravelEventMutex {
        $index = new TaskIndex();
        if ($withTask) {
            $index->add(self::OWN_NAME, Tasks::make('report'));
        }

        return new LaravelEventMutex(
            $mutex,
            $index,
            $laravelDefault ?? $this->createStub(CacheEventMutex::class),
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function event(string $mutexName): Event
    {
        return new Event($this->createStub(EventMutex::class), 'artisan job')->createMutexNameUsing($mutexName);
    }
}
