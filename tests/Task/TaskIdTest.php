<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\TaskId;

final class TaskIdTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testKeepsAValidId(): void
    {
        self::assertSame('reports.weekly_1-a', new TaskId('reports.weekly_1-a')->value);
    }

    /** @throws InvalidTaskException */
    public function testAcceptsTheLongestId(): void
    {
        self::assertSame(100, strlen(new TaskId(str_repeat('a', 100))->value));
    }

    /** @throws InvalidTaskException */
    #[DataProvider('invalidIds')]
    public function testRejectsAnInvalidId(string $id): void
    {
        $this->expectException(InvalidTaskException::class);

        new TaskId($id);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'upper case' => ['Report'];
        yield 'leading dash' => ['-report'];
        yield 'space' => ['my report'];
        yield 'colon' => ['a:b'];
        yield 'too long' => [str_repeat('a', 101)];
        yield 'trailing newline' => ["report\n"];
    }
}
