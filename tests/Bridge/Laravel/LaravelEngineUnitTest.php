<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use DateInvalidTimeZoneException;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\EventMutex;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Console\Scheduling\SchedulingMutex;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use LogicException;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Scheduling\Bridge\Laravel\LaravelEngine;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Capability\Capability;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Task\AdapterOptions;
use Scheduling\Task\Command;
use Scheduling\Task\Condition\ConditionInterface;
use Scheduling\Task\CronExpression;
use Scheduling\Task\LockPolicy;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;
use Scheduling\Tests\Task\Tasks;

/** Isolates LaravelEngine: it registers on a real Laravel Schedule and reports to a mocked run listener. */
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
        self::assertSame('laravel', $this->engine($this->newSchedule(), $this->createStub(RunListenerInterface::class), new TaskIndex())->name());
    }

    public function testCapabilitiesReportWhatLaravelDoesAndDoesNot(): void
    {
        $report = $this->engine($this->newSchedule(), $this->createStub(RunListenerInterface::class), new TaskIndex())->capabilities();

        self::assertSame(Fulfilment::Native, $report->of(Capability::OverlapLock));
        self::assertSame(Fulfilment::Native, $report->of(Capability::OneServer));
        self::assertSame(Fulfilment::Native, $report->of(Capability::Conditions));
        self::assertSame(Fulfilment::Polyfilled, $report->of(Capability::RunRecord));
        self::assertSame(Fulfilment::Unavailable, $report->of(Capability::SubMinute));
        self::assertSame(Fulfilment::Unavailable, $report->of(Capability::MissedRunCatchUp));
    }

    /** @throws DateInvalidTimeZoneException */
    public function testRegisterSetsCronTimezoneMutexNameOverlapAndOneServer(): void
    {
        $schedule = $this->newSchedule();
        $index = new TaskIndex();
        $task = Tasks::make('report', timezone: 'Europe/Prague');

        $problems = $this->engine($schedule, $this->createStub(RunListenerInterface::class), $index)->register($this->keyed($task));

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

        $this->engine($schedule, $this->createStub(RunListenerInterface::class), new TaskIndex())->register($this->keyed(Tasks::make('report')));

        $command = $schedule->events()[0]->command ?? '';
        self::assertStringContainsString('reports:send', $command);
        self::assertStringContainsString('weekly', $command);
        self::assertStringContainsString("--queue='high'", $command);
        self::assertStringContainsString('--force', $command);
    }

    public function testRegisterAppendsOutputToTheConfiguredFile(): void
    {
        $schedule = $this->newSchedule();

        $this->engine($schedule, $this->createStub(RunListenerInterface::class), new TaskIndex())->register($this->keyed(Tasks::make('report')));

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

        $this->engine($schedule, $this->createStub(RunListenerInterface::class), new TaskIndex())->register($this->keyed($task));

        self::assertFalse($schedule->events()[0]->shouldAppendOutput);
    }

    public function testDisabledTaskIsNotRegistered(): void
    {
        $schedule = $this->newSchedule();
        $index = new TaskIndex();

        $problems = $this->engine($schedule, $this->createStub(RunListenerInterface::class), $index)->register($this->keyed(Tasks::make('off', enabled: false)));

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

        $problems = $this->engine($schedule, $this->createStub(RunListenerInterface::class), $index)->register($this->keyed($bad, Tasks::make('good')));

        self::assertEquals([new TaskProblem('bad', 'laravel', 'Unknown laravel options: speed')], $problems);
        self::assertCount(1, $schedule->events());
        self::assertNull($index->get('scheduler:task:bad'));
        self::assertNotNull($index->get('scheduler:task:good'));
    }

    /** @throws InvalidTaskException */
    public function testBeforeHookTellsTheListenerTheTaskStarted(): void
    {
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('started')->with(new TaskId('report'), $this->isInstanceOf(DateTimeImmutable::class));
        $listener->expects($this->never())->method('finished');
        $listener->expects($this->never())->method('failed');
        $event = $this->registered($listener);

        $event->callBeforeCallbacks($this->container);
    }

    /** @throws InvalidTaskException */
    public function testSuccessHookTellsTheListenerTheTaskFinished(): void
    {
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('finished')->with(new TaskId('report'), $this->isInstanceOf(DateTimeImmutable::class));
        $listener->expects($this->never())->method('failed');
        $event = $this->registered($listener);

        $event->exitCode = 0;
        $event->callAfterCallbacks($this->container);
    }

    /** @throws InvalidTaskException */
    public function testFailureHookTellsTheListenerTheTaskFailed(): void
    {
        $listener = $this->createMock(RunListenerInterface::class);
        $listener->expects($this->once())->method('failed')->with(new TaskId('report'), $this->isInstanceOf(DateTimeImmutable::class), null, null);
        $listener->expects($this->never())->method('finished');
        $event = $this->registered($listener);

        $event->exitCode = 1;
        $event->callAfterCallbacks($this->container);
    }

    public function testRegisteredEventRunsOnlyWhileTheTaskConditionsHold(): void
    {
        $condition = $this->createStub(ConditionInterface::class);
        $condition->method('isSatisfied')->willReturn(false);
        $schedule = $this->newSchedule();
        $this->engine($schedule, $this->createStub(RunListenerInterface::class), new TaskIndex())->register($this->keyed(Tasks::make('report', [$condition])));

        self::assertFalse($schedule->events()[0]->filtersPass($this->container));
    }

    public function testRegisteredEventPassesItsFiltersWhenConditionsHold(): void
    {
        $schedule = $this->newSchedule();
        $this->engine($schedule, $this->createStub(RunListenerInterface::class), new TaskIndex())->register($this->keyed(Tasks::make('report')));

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

    private function registered(RunListenerInterface $listener): Event
    {
        $schedule = $this->newSchedule();
        $this->engine($schedule, $listener, new TaskIndex())->register($this->keyed(Tasks::make('report')));

        return $schedule->events()[0];
    }

    private function engine(Schedule $schedule, RunListenerInterface $listener, TaskIndex $index): LaravelEngine
    {
        return new LaravelEngine($schedule, $listener, $index);
    }
}
