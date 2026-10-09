<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use DateTimeImmutable;
use Exception;
use PHPUnit\Framework\TestCase;
use Psr\Log\LogLevel;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RecordingRunListener;
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

        $listener->started($id, $start);
        $listener->finished($id, $start->modify('+10 seconds'));
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

        $listener->failed($id, $at, null, new Exception('boom'));
        $listener->failed($id, $at, 2, null);
        $listener->failed($id, $at, null, null);

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

        $listener->started($id, $start);
        $listener->finished($id, $start->modify('+601 seconds'));
        $listener->started($id, $start);
        $listener->failed($id, $start->modify('+601 seconds'), 1, null);
        $listener->started($id, $start);
        $listener->finished($id, $start->modify('+600 seconds'));

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

        $listener->started($id, $start);
        $listener->finished($id, $start->modify('+1 day'));

        self::assertSame(RunState::Finished, $runs->records[1]->state);
    }

    /** @throws InvalidTaskException */
    public function testStorageFailureIsLoggedAsAWarningAndNeverThrown(): void
    {
        $logger = new CollectingLogger();
        $listener = new RecordingRunListener(new FailingRunRepository(), [], 'web-1', $logger);
        $id = new TaskId('report');
        $at = new DateTimeImmutable('2026-01-01 10:00:00');

        $listener->started($id, $at);
        $listener->finished($id, $at);
        $listener->failed($id, $at, 1, null);
        $listener->skipped($id, $at, SkipReason::LockUnavailable);

        self::assertCount(6, $logger->records);
        foreach ($logger->records as $record) {
            self::assertSame(LogLevel::WARNING, $record['level']);
            self::assertSame('report', $record['context']['task_id']);
            self::assertArrayHasKey('exception', $record['context']);
        }
    }

    /** @throws InvalidTaskException */
    public function testFailedLookupOfTheStartMeansNotOverranAndTheRecordIsStillTried(): void
    {
        $logger = new CollectingLogger();
        $listener = new RecordingRunListener(new FailingRunRepository(), ['report' => Tasks::make()], 'web-1', $logger);

        $listener->finished(new TaskId('report'), new DateTimeImmutable());

        $messages = [];
        foreach ($logger->records as $record) {
            self::assertInstanceOf(StorageException::class, $record['context']['exception']);
            $messages[] = $record['context']['exception']->getMessage();
        }
        self::assertSame(['lastOf failed', 'append failed'], $messages);
    }

    private function listener(InMemoryRunRepository $runs): RecordingRunListener
    {
        return new RecordingRunListener($runs, ['report' => Tasks::make()], 'web-1', new CollectingLogger());
    }
}
