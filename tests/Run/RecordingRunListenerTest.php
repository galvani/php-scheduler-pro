<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RecordingRunListener;
use Scheduling\Run\RunId;
use Scheduling\Run\RunRepositoryInterface;
use Scheduling\Run\RunState;
use Scheduling\Run\SkipReason;
use Scheduling\Task\TaskId;
use Scheduling\Tests\Task\Tasks;

final class RecordingRunListenerTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testRecordsTheLifecycleOfARun(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $run = $listener->started($id, $start);
        $listener->finished($id, $run, $start->modify('+10 seconds'));
        $listener->skipped($id, $start->modify('+1 minute'), SkipReason::StillRunning);

        self::assertSame(
            [RunState::Started, RunState::Finished, RunState::Skipped],
            array_map(static fn ($record) => $record->state, $runs->records),
        );
        self::assertSame('web-1', $runs->records[0]->host);
        self::assertSame('still_running', $runs->records[2]->detail);
    }

    /** @throws InvalidTaskException */
    public function testFailureRecordsTheErrorMessageOrTheExitCode(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $at = new DateTimeImmutable('2026-01-01 10:00:00');

        $listener->failed($id, RunId::generate(), $at, null, new Exception('boom'));
        $listener->failed($id, RunId::generate(), $at, 2, null);
        $listener->failed($id, RunId::generate(), $at, null, null);

        self::assertSame(['boom', 'exit code 2', null], array_map(static fn ($record) => $record->detail, $runs->records));
        self::assertSame(RunState::Failed, $runs->records[0]->state);
    }

    /** @throws InvalidTaskException */
    public function testARunLongerThanItsMaximumIsRecordedAsOverran(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $run = $listener->started($id, $start);
        $listener->finished($id, $run, $start->modify('+601 seconds'));
        $run = $listener->started($id, $start);
        $listener->failed($id, $run, $start->modify('+601 seconds'), 1, null);
        $run = $listener->started($id, $start);
        $listener->finished($id, $run, $start->modify('+600 seconds'));

        $states = array_map(static fn ($record) => $record->state, $runs->records);
        self::assertSame(
            [RunState::Started, RunState::Overran, RunState::Started, RunState::Overran, RunState::Started, RunState::Finished],
            $states,
        );
    }

    /** @throws InvalidTaskException */
    public function testUnknownTaskNeverOverruns(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('other');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $run = $listener->started($id, $start);
        $listener->finished($id, $run, $start->modify('+1 day'));

        self::assertSame(RunState::Finished, $runs->records[1]->state);
    }

    /** @throws InvalidTaskException */
    public function testStorageFailureIsLoggedAsAWarningAndNeverThrown(): void
    {
        $logger = new CollectingLogger();
        $listener = new RecordingRunListener(new FailingRunRepository(), [], 'web-1', $logger);
        $id = new TaskId('report');
        $at = new DateTimeImmutable('2026-01-01 10:00:00');

        $run = $listener->started($id, $at);
        $listener->finished($id, $run, $at);
        $listener->failed($id, $run, $at, 1, null);
        $listener->skipped($id, $at, SkipReason::LockUnavailable);

        self::assertCount(4, $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::WARNING, $record['level']);
            self::assertSame('report', $record['context']['task_id']);
            self::assertArrayHasKey('exception', $record['context']);
        }
    }

    /** @throws InvalidTaskException */
    public function testJudgingAnEndNeverReadsTheStorageSoOnlyTheFailedAppendIsLogged(): void
    {
        $logger = new CollectingLogger();
        $listener = new RecordingRunListener(new FailingRunRepository(), ['report' => Tasks::make()], 'web-1', $logger);

        $listener->finished(new TaskId('report'), RunId::generate(), new DateTimeImmutable());

        $messages = [];
        foreach ($logger->records as $record) {
            self::assertInstanceOf(StorageException::class, $record['context']['exception']);
            $messages[] = $record['context']['exception']->getMessage();
        }
        self::assertSame(['append failed'], $messages);
    }

    /** @throws InvalidTaskException */
    public function testOverranIsMeasuredFromTheStartOfTheSameRunWhenASecondCopyStartedInBetween(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $first = $listener->started($id, $start);
        $second = $listener->started($id, $start->modify('+500 seconds'));
        $listener->finished($id, $first, $start->modify('+700 seconds'));
        $listener->finished($id, $second, $start->modify('+800 seconds'));

        self::assertSame(
            [RunState::Started, RunState::Started, RunState::Overran, RunState::Finished],
            array_map(static fn ($record) => $record->state, $runs->records),
        );
    }

    /** @throws InvalidTaskException */
    public function testALostStartRecordDoesNotMakeTheNextRunOverran(): void
    {
        $runs = new DroppingRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $first = $listener->started($id, $start);
        $listener->finished($id, $first, $start->modify('+10 seconds'));
        $runs->dropNext = true;
        $later = $start->modify('+1 hour');
        $second = $listener->started($id, $later);
        $listener->finished($id, $second, $later->modify('+10 seconds'));

        self::assertSame(
            [RunState::Started, RunState::Finished, RunState::Finished],
            array_map(static fn ($record) => $record->state, $runs->records),
        );
    }

    /** @throws InvalidTaskException */
    public function testRecordsOfARunCarryItsRunIdAndASkipCarriesNone(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $at = new DateTimeImmutable('2026-01-01 10:00:00');

        $finishedRun = $listener->started($id, $at);
        $listener->finished($id, $finishedRun, $at);
        $failedRun = $listener->started($id, $at);
        $listener->failed($id, $failedRun, $at, 1, null);
        $listener->skipped($id, $at, SkipReason::StillRunning);

        self::assertSame(
            [$finishedRun->value, $finishedRun->value, $failedRun->value, $failedRun->value, null],
            array_map(static fn ($record) => $record->runId?->value, $runs->records),
        );
        self::assertNotSame($finishedRun->value, $failedRun->value);
    }

    /** @throws InvalidTaskException */
    public function testAnUnknownRunIdIsNeverOverran(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $listener->started($id, $start);
        $listener->finished($id, RunId::generate(), $start->modify('+1 day'));
        $listener->failed($id, RunId::generate(), $start->modify('+1 day'), 1, null);

        self::assertSame(
            [RunState::Started, RunState::Finished, RunState::Failed],
            array_map(static fn ($record) => $record->state, $runs->records),
        );
    }

    /** @throws InvalidTaskException */
    public function testAnEndedRunIsForgottenSoASecondEndIsNotOverran(): void
    {
        $runs = new InMemoryRunRepository();
        $listener = $this->listener($runs);
        $id = new TaskId('report');
        $start = new DateTimeImmutable('2026-01-01 10:00:00');

        $run = $listener->started($id, $start);
        $listener->finished($id, $run, $start->modify('+10 seconds'));
        $listener->failed($id, $run, $start->modify('+1 day'), 1, null);

        self::assertSame(RunState::Failed, $runs->records[2]->state);
    }

    private function listener(RunRepositoryInterface $runs): RecordingRunListener
    {
        return new RecordingRunListener($runs, ['report' => Tasks::make()], 'web-1', new CollectingLogger());
    }
}
