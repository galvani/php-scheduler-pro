<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Symfony;

use Crunz\Schedule;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\SchedulerInterface;
use Scheduling\Task\TaskProblem;
use Scheduling\Task\TaskSet;
use Stringable;

/** Isolates CrunzTasks: the scheduler and the logger are mocks, the engine wraps a real crunz Schedule. */
final class CrunzTasksUnitTest extends TestCase
{
    public function testScheduleRegistersWithTheEngineAndReturnsItsSchedule(): void
    {
        $crunz = new Schedule();
        $engine = $this->engine($crunz);
        $scheduler = $this->createMock(SchedulerInterface::class);
        $scheduler->expects($this->once())->method('registerWith')->with($this->identicalTo($engine))->willReturn(new TaskSet());
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->never())->method('warning');

        self::assertSame($crunz, new CrunzTasks($scheduler, $engine, $logger)->schedule());
    }

    public function testEachProblemIsLoggedAsAWarningWithItsContext(): void
    {
        $crunz = new Schedule();
        $engine = $this->engine($crunz);
        $scheduler = $this->createStub(SchedulerInterface::class);
        $scheduler->method('registerWith')->willReturn(new TaskSet([], [
            new TaskProblem('first', 'crunz', 'Unknown crunz options: speed'),
            new TaskProblem(null, 'yaml', 'Task has no id'),
        ]));
        $logged = [];
        $logger = $this->createMock(LoggerInterface::class);
        $logger->expects($this->exactly(2))->method('warning')->willReturnCallback(
            static function (string|Stringable $message, array $context) use (&$logged): void {
                $logged[] = [(string) $message, $context];
            },
        );

        $schedule = new CrunzTasks($scheduler, $engine, $logger)->schedule();

        self::assertSame($crunz, $schedule);
        self::assertSame([
            ['Task not scheduled: Unknown crunz options: speed', ['task_id' => 'first', 'source' => 'crunz']],
            ['Task not scheduled: Task has no id', ['task_id' => null, 'source' => 'yaml']],
        ], $logged);
    }

    private function engine(Schedule $schedule): CrunzEngine
    {
        return new CrunzEngine($schedule, $this->createStub(MutexInterface::class), $this->createStub(RunListenerInterface::class), 'php bin/console');
    }
}
