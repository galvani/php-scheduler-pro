<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Events\ScheduledTaskSkipped;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Contracts\Cache\Repository as Cache;
use PHPUnit\Framework\TestCase;
use Psr\SimpleCache\InvalidArgumentException;
use Scheduling\Bridge\Laravel\SkippedSubscriber;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\Task\Condition\ConditionInterface;
use Scheduling\Tests\Task\Tasks;

/** Isolates SkippedSubscriber: the run listener, the mutex and the task's condition are mocks. */
final class SkippedSubscriberUnitTest extends TestCase
{
    /** @throws InvalidArgumentException */
    public function testSkipWhileTheOverlapLockIsHeldIsRecordedAsStillRunning(): void
    {
        $this->assertRecorded(true, SkipReason::StillRunning);
    }

    /** @throws InvalidArgumentException */
    public function testSkipWithNoLockAndHoldingConditionsIsRecordedAsPaused(): void
    {
        $this->assertRecorded(false, SkipReason::Paused);
    }

    /** @throws InvalidArgumentException */
    public function testSkipOfAPausedScheduleWithAHeldLockIsRecordedAsPaused(): void
    {
        $this->assertPausedRecorded(null);
    }

    /** @throws InvalidArgumentException */
    public function testSkipOfAPausedScheduleWithAFailingConditionIsRecordedAsPaused(): void
    {
        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);

        $this->assertPausedRecorded($condition);
    }

    /** @throws InvalidArgumentException */
    public function testSkipOfAPausedScheduleWhoseTaskRunsWhenPausedIsNotRecordedAsPaused(): void
    {
        $task = Tasks::make('report');
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('isLocked')->willReturn(true);
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($task->id, $this->isInstanceOf(DateTimeImmutable::class), SkipReason::StillRunning);
        $index = new TaskIndex();
        $index->add('scheduler:task:report', $task);
        $event = new Event($this->createStub(EventMutex::class), 'artisan job')->createMutexNameUsing('scheduler:task:report');
        $event->evenWhenPaused();

        new SkippedSubscriber($listener, $index, $mutex, $this->cache(true))->handle(new ScheduledTaskSkipped($event));
    }

    /** @throws InvalidArgumentException */
    public function testSkipWhenTheLockStorageIsUnreachableIsRecordedAsLockUnavailable(): void
    {
        $this->assertRecorded(new LockUnavailableException('Redis is down'), SkipReason::LockUnavailable);
    }

    /** @throws InvalidArgumentException */
    public function testSkipWithFailingConditionIsRecordedAsConditionNotMet(): void
    {
        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $task = Tasks::make('report', [$condition]);
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($task->id, $this->isInstanceOf(DateTimeImmutable::class), SkipReason::ConditionNotMet);
        $index = new TaskIndex();
        $index->add('scheduler:task:report', $task);

        new SkippedSubscriber($listener, $index, $this->createStub(MutexInterface::class), $this->cache(false))->handle($this->skipped('scheduler:task:report'));
    }

    /** @throws InvalidArgumentException */
    public function testSkipOfATaskNotInTheIndexIsIgnored(): void
    {
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->never())->method($this->anything());

        new SkippedSubscriber($listener, new TaskIndex(), $this->createStub(MutexInterface::class), $this->cache(false))->handle($this->skipped('app-job'));
    }

    /** @throws InvalidArgumentException */
    private function assertRecorded(bool|LockUnavailableException $locked, SkipReason $expected): void
    {
        $task = Tasks::make('report');
        $mutex = $this->createMock(MutexInterface::class);
        $isLocked = $mutex->expects($this->once())->method('isLocked')->with(LockKey::forTask($task->id));
        if ($locked instanceof LockUnavailableException) {
            $isLocked->willThrowException($locked);
        } else {
            $isLocked->willReturn($locked);
        }
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($task->id, $this->isInstanceOf(DateTimeImmutable::class), $expected);
        $index = new TaskIndex();
        $index->add('scheduler:task:report', $task);

        new SkippedSubscriber($listener, $index, $mutex, $this->cache(false))->handle($this->skipped('scheduler:task:report'));
    }

    /** @throws InvalidArgumentException */
    private function assertPausedRecorded(?ConditionInterface $condition): void
    {
        $task = Tasks::make('report', null === $condition ? [] : [$condition]);
        $mutex = $this->createStub(MutexInterface::class);
        $mutex->method('isLocked')->willReturn(true);
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('skipped')->with($task->id, $this->isInstanceOf(DateTimeImmutable::class), SkipReason::Paused);
        $index = new TaskIndex();
        $index->add('scheduler:task:report', $task);

        new SkippedSubscriber($listener, $index, $mutex, $this->cache(true))->handle($this->skipped('scheduler:task:report'));
    }

    private function cache(bool $paused): Cache
    {
        $cache = $this->createStub(Cache::class);
        $cache->method('get')->willReturnMap([['illuminate:schedule:paused', false, $paused]]);

        return $cache;
    }

    private function skipped(string $mutexName): ScheduledTaskSkipped
    {
        return new ScheduledTaskSkipped(new Event($this->createStub(EventMutex::class), 'artisan job')->createMutexNameUsing($mutexName));
    }
}
