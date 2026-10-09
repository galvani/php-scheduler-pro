<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\TestCase;
use Scheduling\Task\CompositeTaskSource;

final class CompositeTaskSourceTest extends TestCase
{
    public function testMergesDistinctTasks(): void
    {
        $set = new CompositeTaskSource([
            new InMemoryTaskRepository(['a' => Tasks::make('a')]),
            new InMemoryTaskRepository(['b' => Tasks::make('b')]),
        ])->load();

        self::assertSame(['a', 'b'], array_keys($set->tasks));
        self::assertSame([], $set->problems);
    }

    public function testDuplicateIdSkipsBothCopiesAndReportsIt(): void
    {
        $set = new CompositeTaskSource([
            new InMemoryTaskRepository(['a' => Tasks::make('a'), 'dup' => Tasks::make('dup')]),
            new InMemoryTaskRepository(['dup' => Tasks::make('dup'), 'b' => Tasks::make('b')]),
        ])->load();

        self::assertSame(['a', 'b'], array_keys($set->tasks));
        self::assertCount(1, $set->problems);
        self::assertSame('dup', $set->problems[0]->taskId);
        self::assertSame('composite', $set->problems[0]->source);
    }
}
