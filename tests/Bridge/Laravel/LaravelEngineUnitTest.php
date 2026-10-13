<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateInvalidTimeZoneException;
use DateTimeZone;
use Illuminate\Console\Application as ConsoleApplication;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scheduling\Bridge\Laravel\LaravelEngine;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\AdapterOptions;
use Scheduling\Task\Command;
use Scheduling\Task\CronExpression;
use Scheduling\Task\LockPolicy;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;
use Scheduling\Tests\Task\Tasks;

/** Isolates LaravelEngine: it registers on a real Laravel Schedule. */
final class LaravelEngineUnitTest extends TestCase
{
    private Application $container;

    protected function setUp(): void
    {
        $this->container = new Application(sys_get_temp_dir());
        $this->container->instance(EventMutex::class, $this->createStub(EventMutex::class));
        $this->container->instance(SchedulingMutex::class, $this->createStub(SchedulingMutex::class));
        Container::setInstance($this->container);
    }

    protected function tearDown(): void
    {
        Container::setInstance(null);
    }

    public function testNameIsLaravel(): void
    {
        self::assertSame('laravel', $this->engine($this->newSchedule(), new TaskIndex())->name());
    }

    /** @throws DateInvalidTimeZoneException */
    public function testRegisterSetsCronTimezoneMutexNameOverlapAndOneServer(): void
    {
        $schedule = $this->newSchedule();
        $index = new TaskIndex();
        $task = Tasks::make('report', timezone: 'Europe/Prague');

        $problems = $this->engine($schedule, $index)->register($this->keyed($task));

        self::assertSame([], $problems);
        $events = $schedule->events();
        self::assertCount(1, $events);
        $event = $events[0];
        self::assertSame('0 6 * * *', $event->expression);
        self::assertEquals(new DateTimeZone('Europe/Prague'), $event->timezone);
        self::assertSame('scheduler:task:report', $event->mutexName());
        self::assertTrue($event->withoutOverlapping);
        self::assertSame(15, $event->expiresAt);
        self::assertTrue($event->onOneServer);
        self::assertSame($task, $index->get('scheduler:task:report'));
    }

    public function testRegisterBuildsTheCommandLineFromArgumentsAndOptions(): void
    {
        $schedule = $this->newSchedule();

        $this->engine($schedule, new TaskIndex())->register($this->keyed(Tasks::make('report')));

        $command = $schedule->events()[0]->command ?? '';
        self::assertStringContainsString('reports:send', $command);
        self::assertStringContainsString('weekly', $command);
        self::assertStringContainsString("--queue='high'", $command);
        self::assertStringContainsString('--force', $command);
    }

    /**
     * @param list<string>                                      $arguments
     * @param array<non-empty-string, string|list<string>|true> $options
     *
     * @throws InvalidTaskException
     */
    #[DataProvider('commandLines')]
    public function testRegisterQuotesEveryArgumentAndOptionValue(array $arguments, array $options, string $expected): void
    {
        $schedule = $this->newSchedule();
        $task = new TaskDefinition(new TaskId('quoted'), new CronExpression('* * * * *'), new Command('app:hello', $arguments, $options), new DateTimeZone('UTC'), new LockPolicy(60));

        $this->engine($schedule, new TaskIndex())->register($this->keyed($task));

        self::assertSame(ConsoleApplication::formatCommandString('app:hello').$expected, $schedule->events()[0]->command);
    }

    /** @return iterable<string, array{list<string>, array<non-empty-string, string|list<string>|true>, string}> */
    public static function commandLines(): iterable
    {
        yield 'nothing' => [[], [], ''];
        yield 'argument that looks like an option' => [['--x; touch /tmp/pwned1'], [], " '--x; touch /tmp/pwned1'"];
        yield 'short option lookalike argument' => [['-x'], [], " '-x'"];
        yield 'numeric argument' => [['42'], [], " '42'"];
        yield 'option value that looks like an option' => [[], ['from' => '--y; touch /tmp/pwned2'], " --from='--y; touch /tmp/pwned2'"];
        yield 'list option value with a semicolon' => [[], ['tag' => ['a', 'b; id']], " --tag='a' --tag='b; id'"];
        yield 'spaces and a quote' => [['a b'], ['from' => "it's"], " 'a b' --from='it'\\''s'"];
        yield 'flag' => [[], ['force' => true], ' --force'];
        yield 'empty value' => [[], ['note' => ''], " --note=''"];
    }

    public function testRegisterAppendsOutputToTheConfiguredFile(): void
    {
        $schedule = $this->newSchedule();

        $this->engine($schedule, new TaskIndex())->register($this->keyed(Tasks::make('report')));

        $event = $schedule->events()[0];
        self::assertSame('/tmp/x.log', $event->output);
        self::assertTrue($event->shouldAppendOutput);
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws InvalidTaskException
     */
    public function testRegisterWithoutOutputFileLeavesOutputUntouched(): void
    {
        $schedule = $this->newSchedule();
        $task = new TaskDefinition(new TaskId('quiet'), new CronExpression('* * * * *'), new Command('quiet:run'), new DateTimeZone('UTC'), new LockPolicy(60));

        $this->engine($schedule, new TaskIndex())->register($this->keyed($task));

        self::assertFalse($schedule->events()[0]->shouldAppendOutput);
    }

    public function testDisabledTaskIsNotRegistered(): void
    {
        $schedule = $this->newSchedule();
        $index = new TaskIndex();

        $problems = $this->engine($schedule, $index)->register($this->keyed(Tasks::make('off', enabled: false)));

        self::assertSame([], $problems);
        self::assertSame([], $schedule->events());
        self::assertNull($index->get('scheduler:task:off'));
    }

    /**
     * @throws DateInvalidTimeZoneException
     * @throws InvalidTaskException
     */
    public function testUnknownLaravelOptionIsReportedAsAProblemAndTheTaskSkipped(): void
    {
        $schedule = $this->newSchedule();
        $index = new TaskIndex();
        $bad = new TaskDefinition(
            new TaskId('bad'),
            new CronExpression('* * * * *'),
            new Command('bad:run'),
            new DateTimeZone('UTC'),
            new LockPolicy(60),
            options: new AdapterOptions(['laravel' => ['speed' => 9]]),
        );

        $problems = $this->engine($schedule, $index)->register($this->keyed($bad, Tasks::make('good')));

        self::assertEquals([new TaskProblem('bad', 'laravel', 'Unknown laravel options: speed')], $problems);
        self::assertCount(1, $schedule->events());
        self::assertNull($index->get('scheduler:task:bad'));
        self::assertNotNull($index->get('scheduler:task:good'));
    }

    public function testRegisteredEventPassesItsFilters(): void
    {
        $schedule = $this->newSchedule();
        $this->engine($schedule, new TaskIndex())->register($this->keyed(Tasks::make('report')));

        self::assertTrue($schedule->events()[0]->filtersPass($this->container));
    }

    private function newSchedule(): Schedule
    {
        try {
            return new Schedule();
        } catch (RuntimeException $e) {
            throw new LogicException('No container implementation', previous: $e);
        }
    }

    /** @return array<string, TaskDefinition> */
    private function keyed(TaskDefinition ...$tasks): array
    {
        $keyed = [];
        foreach ($tasks as $task) {
            $keyed[$task->id->value] = $task;
        }

        return $keyed;
    }

    private function engine(Schedule $schedule, TaskIndex $index): LaravelEngine
    {
        return new LaravelEngine($schedule, $index);
    }
}
