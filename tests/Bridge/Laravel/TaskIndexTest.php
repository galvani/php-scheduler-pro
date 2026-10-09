<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Laravel\TaskIndex;
use Scheduling\Tests\Task\Tasks;

/** Isolates the lookup of registered tasks by Laravel mutex name. */
final class TaskIndexTest extends TestCase
{
    public function testReturnsTheTaskAddedUnderAName(): void
    {
        $task = Tasks::make('report');
        $index = new TaskIndex();
        $index->add('scheduler:task:report', $task);

        self::assertSame($task, $index->get('scheduler:task:report'));
    }

    public function testUnknownNameIsNull(): void
    {
        self::assertNull(new TaskIndex()->get('scheduler:task:missing'));
    }

    public function testAddingAgainUnderTheSameNameReplacesTheTask(): void
    {
        $index = new TaskIndex();
        $index->add('name', Tasks::make('first'));
        $second = Tasks::make('second');
        $index->add('name', $second);

        self::assertSame($second, $index->get('name'));
    }
}
