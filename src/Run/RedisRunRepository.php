<?php declare(strict_types=1);

namespace Scheduling\Run;

use DateTimeImmutable;
use JsonException;
use Redis;
use RedisException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Task\TaskId;

/**
 * RunRepositoryInterface on Redis. Per task it keeps `scheduler:runs:<id>`, a list of JSON records capped to the
 * newest N, and the hash `scheduler:runs:<id>:last` holding the latest record per state, which is not capped.
 */
final readonly class RedisRunRepository implements RunRepositoryInterface
{
    private const string TIME_FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * @param Redis        $redis         a connected phpredis client
     * @param positive-int $historyLength records kept per task
     */
    public function __construct(
        private Redis $redis,
        private int $historyLength = 1000,
    ) {}

    /**
     * {@inheritDoc}
     *
     * Pushes the record on the history list, trims it to the cap and updates the per-state hash in one MULTI transaction.
     */
    public function append(RunRecord $record): void
    {
        try {
            $json = json_encode($this->encode($record), JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new StorageException("A run of {$record->taskId->value} cannot be encoded", previous: $e);
        }
        $history = "scheduler:runs:{$record->taskId->value}";
        try {
            $result = $this->redis->multi()
                ->lPush($history, $json)
                ->lTrim($history, 0, $this->historyLength - 1)
                ->hSet("{$history}:last", $record->state->value, $json)
                ->exec();
        } catch (RedisException $e) {
            throw new StorageException("Redis unreachable while recording a run of {$record->taskId->value}", previous: $e);
        }
        if (false === $result) {
            throw new StorageException("Recording a run of {$record->taskId->value} was not applied");
        }
    }

    /**
     * {@inheritDoc}
     *
     * Reads from the per-state hash, so it does not scan the history list.
     */
    public function lastOf(TaskId $id, RunState $state): ?RunRecord
    {
        try {
            $raw = $this->redis->hGet("scheduler:runs:{$id->value}:last", $state->value);
        } catch (RedisException $e) {
            throw new StorageException("Redis unreachable while reading a run of {$id->value}", previous: $e);
        }
        if (false === $raw) {
            return null;
        }

        return $this->decode($raw);
    }

    /** @return array<string, string|null> */
    private function encode(RunRecord $record): array
    {
        return [
            'task_id' => $record->taskId->value,
            'state' => $record->state->value,
            'at' => $record->at->format(self::TIME_FORMAT),
            'host' => $record->host,
            'detail' => $record->detail,
        ];
    }

    /** @throws StorageException */
    private function decode(mixed $raw): RunRecord
    {
        try {
            $data = is_string($raw) ? json_decode($raw, true, flags: JSON_THROW_ON_ERROR) : null;
            if (!is_array($data)) {
                throw new StorageException('Stored run record is not an object');
            }
            $at = is_string($data['at'] ?? null) ? DateTimeImmutable::createFromFormat(self::TIME_FORMAT, $data['at']) : false;
            $state = is_string($data['state'] ?? null) ? RunState::tryFrom($data['state']) : null;
            $detail = $data['detail'] ?? null;
            if (!is_string($data['task_id'] ?? null) || !is_string($data['host'] ?? null) || false === $at || null === $state
                || (null !== $detail && !is_string($detail))) {
                throw new StorageException('Stored run record is malformed');
            }

            return new RunRecord(new TaskId($data['task_id']), $state, $at, $data['host'], $detail);
        } catch (JsonException|InvalidTaskException $e) {
            throw new StorageException('Stored run record is malformed', previous: $e);
        }
    }
}
