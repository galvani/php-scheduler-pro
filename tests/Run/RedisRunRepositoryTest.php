<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunRecord;
use Scheduling\Run\RunState;
use Scheduling\Task\TaskId;
use Scheduling\Tests\RedisConnection;

final class RedisRunRepositoryTest extends TestCase
{
    private Redis $redis;

    private TaskId $id;

    /**
     * @throws InvalidTaskException
     * @throws RedisException
     */
    protected function setUp(): void
    {
        $this->redis = RedisConnection::open();
        $this->id = new TaskId('test-'.bin2hex(random_bytes(6)));
    }

    protected function tearDown(): void
    {
        $this->redis->del("scheduler:runs:{$this->id->value}", "scheduler:runs:{$this->id->value}:last");
    }

    /** @throws StorageException */
    public function testLastOfReturnsWhatWasAppended(): void
    {
        $repository = new RedisRunRepository($this->redis);
        $record = new RunRecord($this->id, RunState::Failed, new DateTimeImmutable('2026-03-04 05:06:07.123456 +02:00'), 'web-1', 'boom');

        $repository->append($record);
        $found = $repository->lastOf($this->id, RunState::Failed);

        self::assertNotNull($found);
        self::assertSame($this->id->value, $found->taskId->value);
        self::assertSame(RunState::Failed, $found->state);
        self::assertSame('web-1', $found->host);
        self::assertSame('boom', $found->detail);
        self::assertSame('2026-03-04T05:06:07.123456+02:00', $found->at->format('Y-m-d\TH:i:s.uP'));
    }

    /** @throws StorageException */
    public function testLastOfKeepsTheNewestRecordPerState(): void
    {
        $repository = new RedisRunRepository($this->redis);
        $repository->append(new RunRecord($this->id, RunState::Started, new DateTimeImmutable('2026-01-01 10:00:00'), 'a'));
        $repository->append(new RunRecord($this->id, RunState::Finished, new DateTimeImmutable('2026-01-01 10:01:00'), 'a'));
        $repository->append(new RunRecord($this->id, RunState::Started, new DateTimeImmutable('2026-01-01 11:00:00'), 'b'));

        $started = $repository->lastOf($this->id, RunState::Started);
        $finished = $repository->lastOf($this->id, RunState::Finished);

        self::assertSame('b', $started?->host);
        self::assertNull($started->detail);
        self::assertSame('a', $finished?->host);
        self::assertNull($repository->lastOf($this->id, RunState::Failed));
    }

    /** @throws StorageException */
    public function testHistoryIsTrimmedToItsLength(): void
    {
        $repository = new RedisRunRepository($this->redis, 3);
        for ($i = 1; $i <= 5; ++$i) {
            $repository->append(new RunRecord($this->id, RunState::Started, new DateTimeImmutable("2026-01-01 10:00:0{$i}"), "host-{$i}"));
        }

        $history = $this->redis->lRange("scheduler:runs:{$this->id->value}", 0, -1);

        self::assertIsArray($history);
        self::assertCount(3, $history);
        self::assertIsString($history[0]);
        self::assertIsString($history[2]);
        self::assertStringContainsString('host-5', $history[0]);
        self::assertStringContainsString('host-3', $history[2]);
        self::assertSame('host-5', $repository->lastOf($this->id, RunState::Started)?->host);
    }

    /** @throws StorageException */
    public function testCorruptStoredValueIsAStorageException(): void
    {
        $this->redis->hSet("scheduler:runs:{$this->id->value}:last", 'started', '{not json');

        $this->expectException(StorageException::class);

        new RedisRunRepository($this->redis)->lastOf($this->id, RunState::Started);
    }

    /** @throws StorageException */
    public function testMalformedStoredRecordIsAStorageException(): void
    {
        $this->redis->hSet("scheduler:runs:{$this->id->value}:last", 'started', '{"task_id":"x","state":"nope","at":"x","host":"h"}');

        $this->expectException(StorageException::class);

        new RedisRunRepository($this->redis)->lastOf($this->id, RunState::Started);
    }

    /** @throws StorageException */
    public function testStoredRecordWithAnInvalidTaskIdIsAStorageException(): void
    {
        $this->redis->hSet(
            "scheduler:runs:{$this->id->value}:last",
            'started',
            '{"task_id":"Bad Id","state":"started","at":"2026-01-01T10:00:00.000000+00:00","host":"h","detail":null}',
        );

        $this->expectException(StorageException::class);

        new RedisRunRepository($this->redis)->lastOf($this->id, RunState::Started);
    }

    /** @throws StorageException */
    public function testNonObjectStoredValueIsAStorageException(): void
    {
        $this->redis->hSet("scheduler:runs:{$this->id->value}:last", 'started', '"text"');

        $this->expectException(StorageException::class);

        new RedisRunRepository($this->redis)->lastOf($this->id, RunState::Started);
    }

    /** @throws StorageException */
    public function testAnUnreachableRedisIsAStorageException(): void
    {
        $repository = new RedisRunRepository(new Redis());

        $this->expectException(StorageException::class);

        $repository->lastOf($this->id, RunState::Started);
    }
}
