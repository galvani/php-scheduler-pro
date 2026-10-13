<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use Closure;
use Crunz\Event;
use Crunz\Schedule;
use DateInvalidTimeZoneException;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Redis;
use RedisException;
use ReflectionException;
use ReflectionProperty;
use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\RedisMutex;
use Scheduling\Task\AdapterOptions;
use Scheduling\Task\Command;
use Scheduling\Task\Condition\Environments;
use Scheduling\Task\CronExpression;
use Scheduling\Task\LockPolicy;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Tests\RedisConnection;
use Scheduling\Tests\Task\Tasks;

final class CrunzEngineTest extends TestCase
{
    private const CONSOLE = 'php /app/bin/console';

    private Redis $redis;

    private RedisMutex $mutex;

    private string $id;

    /** @throws RedisException */
    protected function setUp(): void
    {
        $this->redis = RedisConnection::open();
        $this->mutex = new RedisMutex($this->redis);
        $this->id = 'engine-'.bin2hex(random_bytes(6));
    }

    /** @throws RedisException */
    protected function tearDown(): void
    {
        foreach ($this->keys() as $key) {
            $this->redis->del($key);
        }
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws InvalidTaskException
     * @throws ReflectionException
     */
    public function testEventCarriesExpressionTimezoneAndEscapedCommandLine(): void
    {
        $task = new TaskDefinition(
            new TaskId($this->id),
            new CronExpression('*/5 6 * * 1-5'),
            new Command('reports:send', ['two words', "it's"], ['note' => 'a b', 'tag' => ['x', 'y'], 'force' => true]),
            new DateTimeZone('Europe/Prague'),
            new LockPolicy(600),
        );

        $event = $this->register($task, new CollectingListener());

        self::assertSame('*/5 6 * * 1-5', $event->getExpression());
        $timezone = new ReflectionProperty(Event::class, 'timezone')->getValue($event);
        self::assertInstanceOf(DateTimeZone::class, $timezone);
        self::assertSame('Europe/Prague', $timezone->getName());
        self::assertSame(
            "php /app/bin/console reports:send 'two words' 'it'\\''s' '--note=a b' '--tag=x' '--tag=y' '--force'",
            $event->buildCommand(),
        );
    }

    public function testDisabledTaskIsNotRegistered(): void
    {
        $engine = $this->engine($schedule = new Schedule(), new CollectingListener());

        $problems = $engine->register([$this->id => Tasks::make($this->id, enabled: false)]);

        self::assertSame([], $problems);
        self::assertSame([], $schedule->events());
    }

    public function testUnknownCrunzOptionsAreRefusedAsATaskProblem(): void
    {
        $base = Tasks::make($this->id);
        $task = new TaskDefinition(
            $base->id,
            $base->schedule,
            $base->command,
            $base->timezone,
            $base->lock,
            options: new AdapterOptions(['crunz' => ['bogus' => 1, 'other' => true]]),
        );
        $engine = $this->engine($schedule = new Schedule(), new CollectingListener());

        $problems = $engine->register([$this->id => $task]);

        self::assertCount(1, $problems);
        self::assertSame($this->id, $problems[0]->taskId);
        self::assertSame('crunz', $problems[0]->source);
        self::assertSame('Unknown crunz options: bogus, other', $problems[0]->reason);
        self::assertSame([], $schedule->events());
    }

    /**
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testOnlyTheFirstServerPassesTheMinuteClaim(): void
    {
        $this->avoidMinuteBoundary();
        $task = Tasks::make($this->id);
        $firstListener = new CollectingListener();
        $secondListener = new CollectingListener();
        $first = $this->register($task, $firstListener);
        $second = $this->register($task, $secondListener);
        $utc = new DateTimeZone('UTC');

        $firstPasses = $first->filtersPass($utc);
        $secondPasses = $second->filtersPass($utc);

        self::assertTrue($firstPasses);
        self::assertFalse($secondPasses);
        self::assertSame([], $firstListener->calls);
        self::assertSame(["skipped:{$this->id}:ran_on_another_server"], $secondListener->calls);
        self::assertCount(1, $this->minuteClaims());
    }

    /** @throws RedisException */
    public function testFalseConditionSkipsWithConditionNotMetBeforeAnyClaim(): void
    {
        $listener = new CollectingListener();
        $event = $this->register(Tasks::make($this->id, [new Environments(['production'], 'staging')]), $listener);

        $passes = $event->filtersPass(new DateTimeZone('UTC'));

        self::assertFalse($passes);
        self::assertSame(["skipped:{$this->id}:condition_not_met"], $listener->calls);
        self::assertSame([], $this->keys());
    }

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testOverlapLockIsTakenThroughTheMutexAndReleasedAfterTheRun(): void
    {
        $this->avoidMinuteBoundary();
        $listener = new CollectingListener();
        $event = $this->register(Tasks::make($this->id), $listener);
        $overlapKey = LockKey::forTask(new TaskId($this->id));
        self::assertFalse($this->mutex->isLocked($overlapKey));

        self::assertTrue($event->filtersPass(new DateTimeZone('UTC')));

        self::assertTrue($this->mutex->isLocked($overlapKey));
        self::assertEqualsWithDelta(120_000, $this->redis->pttl($overlapKey->value), 2_000);
        $this->invokeAll($event->beforeCallbacks());
        $this->invokeAll($event->afterCallbacks());
        self::assertFalse($this->mutex->isLocked($overlapKey));
        self::assertSame(["started:{$this->id}", "finished:{$this->id}"], $listener->calls);
    }

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testOverlapCheckRecordsStillRunningWhileThePreviousRunHoldsTheLock(): void
    {
        $this->avoidMinuteBoundary();
        $listener = new CollectingListener();
        $event = $this->register(Tasks::make($this->id), $listener);
        self::assertNotNull($this->mutex->acquire(LockKey::forTask(new TaskId($this->id)), 60));

        $passes = $event->filtersPass(new DateTimeZone('UTC'));

        self::assertFalse($passes);
        self::assertSame(["skipped:{$this->id}:still_running"], $listener->calls);
    }

    /**
     * @param list<string>                                      $arguments
     * @param array<non-empty-string, string|list<string>|true> $options
     *
     * @throws DateInvalidTimeZoneException
     * @throws InvalidTaskException
     */
    #[DataProvider('commandLines')]
    public function testEveryArgumentAndOptionIsQuotedAsOneWord(array $arguments, array $options, string $expected): void
    {
        $task = new TaskDefinition(new TaskId($this->id), new CronExpression('* * * * *'), new Command('app:hello', $arguments, $options), new DateTimeZone('UTC'), new LockPolicy(60));

        self::assertSame('php /app/bin/console app:hello'.$expected, $this->register($task, new CollectingListener())->buildCommand());
    }

    /** @return iterable<string, array{list<string>, array<non-empty-string, string|list<string>|true>, string}> */
    public static function commandLines(): iterable
    {
        yield 'argument that looks like an option' => [['--x; touch /tmp/pwned1'], [], " '--x; touch /tmp/pwned1'"];
        yield 'short option lookalike argument' => [['-x'], [], " '-x'"];
        yield 'numeric argument' => [['42'], [], " '42'"];
        yield 'option value that looks like an option' => [[], ['from' => '--y; touch /tmp/pwned2'], " '--from=--y; touch /tmp/pwned2'"];
        yield 'list option value with a semicolon' => [[], ['tag' => ['a', 'b; id']], " '--tag=a' '--tag=b; id'"];
        yield 'empty value' => [[], ['note' => ''], " '--note='"];
    }

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testFailedRunReleasesTheLockAndIsRecorded(): void
    {
        $this->avoidMinuteBoundary();
        $listener = new CollectingListener();
        $schedule = new Schedule();
        $this->engine($schedule, $listener)->register([$this->id => Tasks::make($this->id)]);
        $event = $schedule->events()[0];
        $overlapKey = LockKey::forTask(new TaskId($this->id));
        self::assertTrue($event->filtersPass(new DateTimeZone('UTC')));
        self::assertTrue($this->mutex->isLocked($overlapKey));

        $this->invokeAll($event->errorCallbacks());
        foreach ($schedule->errorCallbacks() as $callback) {
            $callback($event);
        }

        self::assertFalse($this->mutex->isLocked($overlapKey));
        self::assertSame(["failed:{$this->id}"], $listener->calls);
    }

    /**
     * @throws InvalidTaskException
     * @throws LockUnavailableException
     * @throws RedisException
     */
    public function testEachEndCarriesTheRunIdItsOwnStartReturned(): void
    {
        $this->avoidMinuteBoundary();
        $listener = new CollectingListener();
        $schedule = new Schedule();
        $other = "{$this->id}-b";
        $this->engine($schedule, $listener)->register([$this->id => Tasks::make($this->id), $other => Tasks::make($other)]);
        [$first, $second] = $schedule->events();
        self::assertTrue($first->filtersPass(new DateTimeZone('UTC')));
        self::assertTrue($second->filtersPass(new DateTimeZone('UTC')));

        $this->invokeAll($first->beforeCallbacks());
        $this->invokeAll($second->beforeCallbacks());
        $this->invokeAll($first->afterCallbacks());
        $this->invokeAll($second->errorCallbacks());
        foreach ($schedule->errorCallbacks() as $callback) {
            $callback($second);
        }

        self::assertSame(["started:{$this->id}", "started:{$other}", "finished:{$this->id}", "failed:{$other}"], $listener->calls);
        [$firstStart, $secondStart, $firstEnd, $secondEnd] = $listener->runIds;
        self::assertSame($firstStart, $firstEnd);
        self::assertSame($secondStart, $secondEnd);
        self::assertNotSame($firstStart, $secondStart);
    }

    private function register(TaskDefinition $task, CollectingListener $listener): Event
    {
        $schedule = new Schedule();
        $this->engine($schedule, $listener)->register([$task->id->value => $task]);

        return $schedule->events()[0];
    }

    private function engine(Schedule $schedule, CollectingListener $listener): CrunzEngine
    {
        return new CrunzEngine($schedule, $this->mutex, $listener, self::CONSOLE);
    }

    /** @param array<Closure> $callbacks */
    private function invokeAll(array $callbacks): void
    {
        foreach ($callbacks as $callback) {
            $callback();
        }
    }

    private function avoidMinuteBoundary(): void
    {
        if ((int) date('s') >= 57) {
            sleep(4);
        }
    }

    /**
     * @return list<string>
     *
     * @throws RedisException
     */
    private function minuteClaims(): array
    {
        return array_values(array_filter(
            $this->keys(),
            fn (string $key): bool => str_starts_with($key, "scheduler:task:{$this->id}:"),
        ));
    }

    /**
     * @return list<string>
     *
     * @throws RedisException
     */
    private function keys(): array
    {
        $keys = $this->redis->keys("scheduler:task:{$this->id}*");

        return is_array($keys) ? array_values($keys) : [];
    }
}
