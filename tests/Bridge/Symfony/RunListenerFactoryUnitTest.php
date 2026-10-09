<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Symfony\RunListenerFactory;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskSet;
use Scheduling\Tests\Task\Tasks;

/** Isolates RunListenerFactory: the scheduler, repository and logger are mocks. */
final class RunListenerFactoryUnitTest extends TestCase
{
    public function testCreatedListenerWritesRecordsWithTheGivenHostToTheRepository(): void
    {
        $task = Tasks::make('report');
        $scheduler = $this->createStub(SchedulerInterface::class);
        $scheduler->method('tasks')->willReturn(new TaskSet(['report' => $task]));
        $at = new DateTimeImmutable('2026-03-04 14:30:00 UTC');
        $runs = $this->createMock(RunRepositoryInterface::class);
        $runs->expects($this->once())->method('append')->with(new RunRecord($task->id, RunState::Started, $at, 'web-7'));

        RunListenerFactory::create($scheduler, $runs, 'web-7', $this->createStub(LoggerInterface::class))->started($task->id, $at);
    }

    /** @throws InvalidTaskException */
    public function testCreatedListenerKnowsTheSchedulersTasksWhenJudgingOverruns(): void
    {
        $task = Tasks::make('report');
        $scheduler = $this->createStub(SchedulerInterface::class);
        $scheduler->method('tasks')->willReturn(new TaskSet(['report' => $task]));
        $started = new DateTimeImmutable('2026-03-04 14:00:00 UTC');
        $ended = $started->modify('+2 hours');
        $runs = $this->createMock(RunRepositoryInterface::class);
        $runs->expects($this->once())->method('lastOf')->with($task->id, RunState::Started)->willReturn(new RunRecord($task->id, RunState::Started, $started, 'web-7'));
        $runs->expects($this->once())->method('append')->with(new RunRecord($task->id, RunState::Overran, $ended, 'web-7'));

        RunListenerFactory::create($scheduler, $runs, 'web-7', $this->createStub(LoggerInterface::class))->finished(new TaskId('report'), $ended);
    }

    /** @throws InvalidTaskException */
    public function testCreatedListenerLogsStorageFailuresToTheGivenLogger(): void
    {
        $scheduler = $this->createStub(SchedulerInterface::class);
        $scheduler->method('tasks')->willReturn(new TaskSet());
        $runs = $this->createStub(RunRepositoryInterface::class);
        $runs->method('append')->willThrowException(new StorageException('down'));
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->once())->method('warning')->with('Run history is unavailable', $this->arrayHasKey('task_id'));

        RunListenerFactory::create($scheduler, $runs, 'web-7', $logger)->started(new TaskId('report'), new DateTimeImmutable());
    }
}
