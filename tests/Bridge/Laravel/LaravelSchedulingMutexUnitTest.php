<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Laravel\LaravelSchedulingMutex;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockInterface;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Tests\Task\Tasks;

/** Isolates LaravelSchedulingMutex: our mutex and the logger are mocks. */
final class LaravelSchedulingMutexUnitTest extends TestCase
{
    private const string OWN_NAME = 'scheduler:task:report';

    private const string APP_NAME = 'app-job';

    public function testOwnTaskClaimsItsMinuteKeyForAnHour(): void
    {
        $task = Tasks::make('report');
        $time = $this->minute();
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forTaskAt($task->id, $time), 3600)->willReturn($this->createStub(LockInterface::class));

        self::assertTrue($this->subject($mutex)->create($this->event(self::OWN_NAME), $time));
    }

    public function testOwnTaskRefusedByTheClaimIsSkipped(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willReturn(null);

        self::assertFalse($this->subject($mutex)->create($this->event(self::OWN_NAME), $this->minute()));
    }

    public function testAppTaskClaimsItsExternalMinuteKeyForAnHour(): void
    {
        $time = $this->minute();
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->with(LockKey::forExternalAt(self::APP_NAME, $time), 3600)->willReturn($this->createStub(LockInterface::class));

        self::assertTrue($this->subject($mutex, withTask: false)->create($this->event(self::APP_NAME), $time));
    }

    public function testAppTaskRefusedByTheClaimLogsNothing(): void
    {
        $mutex = $this->createStub(MutexInterface::class);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method($this->anything());

        self::assertFalse($this->subject($mutex, $logger, false)->create($this->event(self::APP_NAME), $this->minute()));
    }

    public function testOwnTaskWithUnavailableStorageLogsAWarningNamingTheTask(): void
    {
        $failure = new LockUnavailableException('Redis is down');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Lock storage unreachable; the run is skipped', ['task_id' => 'report', 'mutex' => self::OWN_NAME, 'exception' => $failure]);

        self::assertFalse($this->subject($mutex, $logger)->create($this->event(self::OWN_NAME), $this->minute()));
    }

    public function testAppTaskWithUnavailableStorageLogsAWarningNamingTheMutex(): void
    {
        $failure = new LockUnavailableException('Redis is down');
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('acquire')->willThrowException($failure);
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())
            ->method('warning')
            ->with('Lock storage unreachable; the run is skipped', ['mutex' => self::APP_NAME, 'exception' => $failure]);

        self::assertFalse($this->subject($mutex, $logger, false)->create($this->event(self::APP_NAME), $this->minute()));
    }

    public function testExistsAsksForTheTaskMinuteKeyOfAnOwnTask(): void
    {
        $task = Tasks::make('report');
        $time = $this->minute();
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->with(LockKey::forTaskAt($task->id, $time))->willReturn(true);

        self::assertTrue($this->subject($mutex)->exists($this->event(self::OWN_NAME), $time));
    }

    public function testExistsAsksForTheExternalMinuteKeyOfAnAppTask(): void
    {
        $time = $this->minute();
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->with(LockKey::forExternalAt(self::APP_NAME, $time))->willReturn(false);

        self::assertFalse($this->subject($mutex, withTask: false)->exists($this->event(self::APP_NAME), $time));
    }

    public function testExistsIsFalseWhenStorageIsUnavailable(): void
    {
        $mutex = $this->createMock(MutexInterface::class);
        $mutex->expects($this->once())->method('isLocked')->willThrowException(new LockUnavailableException('Redis is down'));

        self::assertFalse($this->subject($mutex)->exists($this->event(self::OWN_NAME), $this->minute()));
    }

    private function subject(
        MutexInterface $mutex,
        ?LoggerInterface $logger = null,
        bool $withTask = true,
    ): LaravelSchedulingMutex {
        $index = new TaskIndex();
        if ($withTask) {
            $index->add(self::OWN_NAME, Tasks::make('report'));
        }

        return new LaravelSchedulingMutex(
            $mutex,
            $index,
            $logger ?? $this->createStub(LoggerInterface::class),
        );
    }

    private function minute(): DateTimeImmutable
    {
        return new DateTimeImmutable('2026-03-04 15:30:00', new DateTimeZone('Europe/Prague'));
    }

    private function event(string $mutexName): Event
    {
        return new Event($this->createStub(EventMutex::class), 'artisan job')->createMutexNameUsing($mutexName);
    }
}
