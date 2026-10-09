<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateTimeImmutable;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Container\BindingResolutionException;
use JsonException;
use Psr\Log\LoggerInterface;
use RedisException;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\StorageException;
use Scheduling\Run\RedisRunRepository;
use Scheduling\Run\RunState;
use Scheduling\Run\SkipReason;
use Scheduling\Task\TaskId;
use Scheduling\Tests\Run\CollectingLogger;

final class ScheduleRunTest extends LaravelTestCase
{
    private const string DUE_MINUTE = '2026-03-04 14:30:00';

    /** @throws BindingResolutionException */
    public function testYamlTasksBecomeScheduleEventsWithCronTimezoneAndMutexName(): void
    {
        $daily = $this->newTaskId();
        $frequent = $this->newTaskId();
        $this->writeTasks(<<<YAML
            tasks:
              {$daily}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($daily)}"]
                max_runtime: 120
                timezone: Europe/Prague
              {$frequent}:
                schedule: "*/5 * * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($frequent)}"]
                max_runtime: 60
            YAML);

        $events = $this->laravel()->make(Schedule::class)->events();

        self::assertCount(2, $events);
        $byMutex = [];
        foreach ($events as $event) {
            $byMutex[$event->mutexName()] = $event;
        }
        $first = $byMutex["scheduler:task:{$daily}"];
        self::assertSame('30 14 * * *', $first->expression);
        self::assertSame('Europe/Prague', $this->timezoneOf($first));
        self::assertTrue($first->withoutOverlapping);
        self::assertTrue($first->onOneServer);
        self::assertStringContainsString('scheduler-test:mark', (string) $first->command);
        $second = $byMutex["scheduler:task:{$frequent}"];
        self::assertSame('*/5 * * * *', $second->expression);
        self::assertSame('UTC', $this->timezoneOf($second));
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws JsonException
     * @throws RedisException
     * @throws StorageException
     */
    public function testDueCommandRunsHoldsItsLocksAndIsRecorded(): void
    {
        $id = $this->newTaskId();
        $this->writeDueTask($id, 'options: {text: hello, lock-key: "scheduler:task:'.$id.'"}');
        $this->travelTo(new DateTimeImmutable(self::DUE_MINUTE.' UTC'));

        $this->runSchedule();

        self::assertSame("hello locked=1\n", file_get_contents($this->markerFile($id)));
        self::assertSame(0, $this->redis->exists("scheduler:task:{$id}"));
        self::assertGreaterThan(0, $this->redis->ttl("scheduler:task:{$id}:202603041430"));
        self::assertSame([RunState::Finished->value, RunState::Started->value], $this->recordedStates($id));
        self::assertNotNull($this->runs()->lastOf(new TaskId($id), RunState::Started));
        self::assertNotNull($this->runs()->lastOf(new TaskId($id), RunState::Finished));
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws RedisException
     * @throws StorageException
     */
    public function testSecondRunInTheSameMinuteDoesNotRunItAgain(): void
    {
        $id = $this->newTaskId();
        $this->writeDueTask($id);
        $this->travelTo(new DateTimeImmutable(self::DUE_MINUTE.' UTC'));
        $this->runSchedule();

        $this->refreshApplication();
        $this->runSchedule();

        self::assertSame("ran\n", file_get_contents($this->markerFile($id)));
        $skipped = $this->runs()->lastOf(new TaskId($id), RunState::Skipped);
        self::assertNotNull($skipped);
        self::assertSame(SkipReason::RanOnAnotherServer->value, $skipped->detail);
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testHeldOverlapLockSkipsTheRun(): void
    {
        $id = $this->newTaskId();
        $this->writeDueTask($id);
        $this->redis->set("scheduler:task:{$id}", 'another-process', ['nx', 'px' => 60000]);
        $this->travelTo(new DateTimeImmutable(self::DUE_MINUTE.' UTC'));

        $this->runSchedule();

        self::assertFileDoesNotExist($this->markerFile($id));
        self::assertSame('another-process', $this->redis->get("scheduler:task:{$id}"));
        $skipped = $this->runs()->lastOf(new TaskId($id), RunState::Skipped);
        self::assertNotNull($skipped);
        self::assertSame(SkipReason::StillRunning->value, $skipped->detail);
    }

    /**
     * @throws BindingResolutionException
     * @throws InvalidTaskException
     * @throws StorageException
     */
    public function testTaskOutsideItsEnvironmentIsSkippedAndRecorded(): void
    {
        $id = $this->newTaskId();
        $this->writeDueTask($id, 'environments: [production]');
        $this->travelTo(new DateTimeImmutable(self::DUE_MINUTE.' UTC'));

        $this->runSchedule();

        self::assertFileDoesNotExist($this->markerFile($id));
        $skipped = $this->runs()->lastOf(new TaskId($id), RunState::Skipped);
        self::assertNotNull($skipped);
        self::assertSame(SkipReason::ConditionNotMet->value, $skipped->detail);
        self::assertNull($this->runs()->lastOf(new TaskId($id), RunState::Started));
    }

    /** @throws BindingResolutionException */
    public function testBadRowIsLoggedAsWarningAndGoodTaskStillRegisters(): void
    {
        $good = $this->newTaskId();
        $bad = $this->newTaskId();
        $this->writeTasks(<<<YAML
            tasks:
              {$good}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($good)}"]
                max_runtime: 120
              {$bad}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
            YAML);
        $logger = new CollectingLogger();
        $this->laravel()->instance(LoggerInterface::class, $logger);

        $events = $this->laravel()->make(Schedule::class)->events();

        self::assertSame(["scheduler:task:{$good}"], array_map(static fn (Event $e): string => $e->mutexName(), $events));
        self::assertCount(1, $logger->records);
        self::assertSame('warning', $logger->records[0]['level']);
        self::assertSame('max_runtime is required', $logger->records[0]['message']);
        self::assertSame(['task_id' => $bad, 'source' => 'yaml'], $logger->records[0]['context']);
    }

    private function writeDueTask(string $id, string $extra = ''): void
    {
        $this->writeTasks(<<<YAML
            tasks:
              {$id}:
                schedule: "30 14 * * *"
                command: scheduler-test:mark
                arguments: ["{$this->markerFile($id)}"]
                max_runtime: 120
                timezone: UTC
                {$extra}
            YAML);
    }

    private function runs(): RedisRunRepository
    {
        return new RedisRunRepository($this->redis);
    }

    /**
     * @return list<string> newest first
     *
     * @throws JsonException
     * @throws RedisException
     */
    private function recordedStates(string $id): array
    {
        $states = [];
        $records = $this->redis->lRange("scheduler:runs:{$id}", 0, -1);
        foreach (is_array($records) ? $records : [] as $json) {
            $record = is_string($json) ? json_decode($json, true, flags: JSON_THROW_ON_ERROR) : null;
            $states[] = is_array($record) && is_string($record['state'] ?? null) ? $record['state'] : '';
        }

        return $states;
    }

    private function timezoneOf(Event $event): string
    {
        return is_string($event->timezone) ? $event->timezone : $event->timezone->getName();
    }
}
