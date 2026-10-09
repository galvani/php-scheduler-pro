<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use DateTimeImmutable;
use JsonException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;

/** Isolates RedisRunRepository from a Redis server: phpredis is a mock and the exact commands are verified. */
final class RedisRunRepositoryUnitTest extends TestCase
{
    private const string HISTORY = 'scheduler:runs:report';

    /**
     * @throws InvalidTaskException
     * @throws JsonException
     * @throws StorageException
     */
    public function testAppendPushesTrimsAndRemembersTheLastRecordInOneTransaction(): void
    {
        $json = json_encode([
            'task_id' => 'report',
            'state' => 'failed',
            'at' => '2026-03-04T14:30:00.000000+00:00',
            'host' => 'web-1',
            'detail' => 'boom',
        ], JSON_THROW_ON_ERROR);
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('multi')->willReturnSelf();
        $redis->expects($this->once())->method('lPush')->with(self::HISTORY, $json)->willReturnSelf();
        $redis->expects($this->once())->method('lTrim')->with(self::HISTORY, 0, 4)->willReturnSelf();
        $redis->expects($this->once())->method('hSet')->with(self::HISTORY.':last', 'failed', $json)->willReturnSelf();
        $redis->expects($this->once())->method('exec')->willReturn([1, true, 1]);

        new RedisRunRepository($redis, 5)->append($this->record(RunState::Failed, 'boom'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testAppendKeepsAThousandRunsByDefault(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('lPush')->willReturnSelf();
        $redis->expects($this->once())->method('lTrim')->with(self::HISTORY, 0, 999)->willReturnSelf();
        $redis->method('hSet')->willReturnSelf();
        $redis->method('exec')->willReturn([1, true, 1]);

        new RedisRunRepository($redis)->append($this->record(RunState::Started));
    }

    /** @throws InvalidTaskException */
    public function testAppendMapsRedisFailureToStorageException(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('multi')->willThrowException($failure);

        try {
            new RedisRunRepository($redis)->append($this->record(RunState::Started));
            self::fail('StorageException expected');
        } catch (StorageException $e) {
            self::assertSame($failure, $e->getPrevious());
            self::assertStringContainsString('report', $e->getMessage());
        }
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testAppendMapsFailureInsideTheTransactionToStorageException(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->expects($this->once())->method('lPush')->willThrowException(new RedisException('gone away'));
        $redis->expects($this->never())->method('exec');

        $this->expectException(StorageException::class);

        new RedisRunRepository($redis)->append($this->record(RunState::Started));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testAppendRejectsAnExecThatReturnsFalse(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->method('multi')->willReturnSelf();
        $redis->method('lPush')->willReturnSelf();
        $redis->method('lTrim')->willReturnSelf();
        $redis->method('hSet')->willReturnSelf();
        $redis->expects($this->once())->method('exec')->willReturn(false);

        $this->expectException(StorageException::class);
        $this->expectExceptionMessage('was not applied');

        new RedisRunRepository($redis)->append($this->record(RunState::Started));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testLastOfDecodesTheStoredRecord(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())
            ->method('hGet')
            ->with(self::HISTORY.':last', 'skipped')
            ->willReturn('{"task_id":"report","state":"skipped","at":"2026-03-04T14:30:00.000000+00:00","host":"web-1","detail":"still_running"}');

        $record = new RedisRunRepository($redis)->lastOf(new TaskId('report'), RunState::Skipped);

        self::assertNotNull($record);
        self::assertSame('report', $record->taskId->value);
        self::assertSame(RunState::Skipped, $record->state);
        self::assertSame('web-1', $record->host);
        self::assertSame('still_running', $record->detail);
        self::assertSame('2026-03-04T14:30:00+00:00', $record->at->format('c'));
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testLastOfReturnsNullWhenNothingIsStored(): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('hGet')->willReturn(false);

        self::assertNull(new RedisRunRepository($redis)->lastOf(new TaskId('report'), RunState::Started));
    }

    /** @throws InvalidTaskException */
    public function testLastOfMapsRedisFailureToStorageException(): void
    {
        $failure = new RedisException('gone away');
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('hGet')->willThrowException($failure);

        try {
            new RedisRunRepository($redis)->lastOf(new TaskId('report'), RunState::Started);
            self::fail('StorageException expected');
        } catch (StorageException $e) {
            self::assertSame($failure, $e->getPrevious());
        }
    }

    /**
     * @throws InvalidTaskException
     * @throws StorageException
     */
    #[DataProvider('malformedRecords')]
    public function testLastOfRejectsMalformedStoredRecords(string $stored): void
    {
        $redis = $this->createMock(Redis::class);
        $redis->expects($this->once())->method('hGet')->willReturn($stored);

        $this->expectException(StorageException::class);

        new RedisRunRepository($redis)->lastOf(new TaskId('report'), RunState::Started);
    }

    /**
     * @return iterable<string, array{string}>
     *
     * @throws JsonException
     */
    public static function malformedRecords(): iterable
    {
        $valid = ['task_id' => 'report', 'state' => 'started', 'at' => '2026-03-04T14:30:00.000000+00:00', 'host' => 'web-1', 'detail' => null];

        yield 'not json' => ['{not json'];
        yield 'json scalar' => ['"started"'];
        yield 'empty string' => [''];
        yield 'missing task id' => [self::encode(array_diff_key($valid, ['task_id' => true]))];
        yield 'invalid task id' => [self::encode([...$valid, 'task_id' => 'Not Valid'])];
        yield 'unknown state' => [self::encode([...$valid, 'state' => 'exploded'])];
        yield 'bad timestamp' => [self::encode([...$valid, 'at' => 'yesterday'])];
        yield 'missing host' => [self::encode(array_diff_key($valid, ['host' => true]))];
        yield 'non string detail' => [self::encode([...$valid, 'detail' => 42])];
    }

    /**
     * @param array<string, mixed> $data
     *
     * @throws JsonException
     */
    private static function encode(array $data): string
    {
        return json_encode($data, JSON_THROW_ON_ERROR);
    }

    /** @throws InvalidTaskException */
    private function record(RunState $state, ?string $detail = null): RunRecord
    {
        return new RunRecord(new TaskId('report'), $state, new DateTimeImmutable('2026-03-04 14:30:00 UTC'), 'web-1', $detail);
    }
}
