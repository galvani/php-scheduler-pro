<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Symfony\RunListenerFactory;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RunId;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskSet;
use Scheduling\Tests\Run\InMemoryRunRepository;
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
        $runs->expects($this->once())->method('append')->with($this->callback(
            static fn (RunRecord $record): bool => $record->taskId === $task->id && RunState::Started === $record->state
                && $record->at === $at && 'web-7' === $record->host && null === $record->detail && $record->runId instanceof RunId,
        ));

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
        $runs = new InMemoryRunRepository();
        $listener = RunListenerFactory::create($scheduler, $runs, 'web-7', $this->createStub(LoggerInterface::class));

        $run = $listener->started(new TaskId('report'), $started);
        $listener->finished(new TaskId('report'), $run, $ended);

        self::assertSame([RunState::Started, RunState::Overran], array_map(static fn (RunRecord $record) => $record->state, $runs->records));
        self::assertSame($ended, $runs->records[1]->at);
        self::assertSame('web-7', $runs->records[1]->host);
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
