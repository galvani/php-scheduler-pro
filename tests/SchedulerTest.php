<?php declare(strict_types=1);

namespace Scheduling\Tests;

use PHPUnit\Framework\TestCase;
use Scheduling\Scheduler;
use Scheduling\Task\TaskProblem;
use Scheduling\Task\TaskSet;
use Scheduling\Task\TaskSourceInterface;
use Scheduling\Tests\Task\Tasks;

final class SchedulerTest extends TestCase
{
    public function testRegistersTheLoadedTasksWithTheEngine(): void
    {
        $engine = new FakeEngine();
        $set = new Scheduler($this->source(new TaskSet(['a' => Tasks::make('a'), 'b' => Tasks::make('b')])))->registerWith($engine);

        self::assertSame(['a', 'b'], array_keys($engine->registered));
        self::assertSame(['a', 'b'], array_keys($set->tasks));
        self::assertSame([], $set->problems);
    }

    public function testMergesEngineRefusalsAndSourceProblems(): void
    {
        $engine = new FakeEngine([new TaskProblem('b', 'fake', 'bad options'), new TaskProblem(null, 'fake', 'general')]);
        $set = new Scheduler($this->source(new TaskSet(
            ['a' => Tasks::make('a'), 'b' => Tasks::make('b')],
            [new TaskProblem('broken', 'yaml', 'max_runtime is required')],
        )))->registerWith($engine);

        self::assertSame(['a'], array_keys($set->tasks));
        self::assertSame(['broken', 'b', null], array_map(static fn (TaskProblem $problem) => $problem->taskId, $set->problems));
    }

    public function testTasksReturnsTheSourceContent(): void
    {
        $set = new Scheduler($this->source(new TaskSet(['a' => Tasks::make('a')])))->tasks();

        self::assertSame(['a'], array_keys($set->tasks));
    }

    /** A source that returns the given set on every load. */
    private function source(TaskSet $set): TaskSourceInterface
    {
        return new readonly class($set) implements TaskSourceInterface {
            public function __construct(private TaskSet $set) {}

            /**
             * Never throws: a source that cannot be read, or a row that is invalid, becomes a TaskProblem
             * in the set and every valid task is still returned.
             */
            public function load(): TaskSet
            {
                return $this->set;
            }
        };
    }
}
