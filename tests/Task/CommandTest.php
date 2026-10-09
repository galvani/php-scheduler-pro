<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\Command;

final class CommandTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testKeepsNameArgumentsAndOptions(): void
    {
        $command = new Command('reports:send', ['weekly'], ['force' => true]);

        self::assertSame('reports:send', $command->name);
        self::assertSame(['weekly'], $command->arguments);
        self::assertSame(['force' => true], $command->options);
    }

    /** @throws InvalidTaskException */
    public function testArgumentsAndOptionsDefaultToEmpty(): void
    {
        $command = new Command('reports:send');

        self::assertSame([], $command->arguments);
        self::assertSame([], $command->options);
    }

    /** @throws InvalidTaskException */
    public function testRejectsAnEmptyName(): void
    {
        $this->expectException(InvalidTaskException::class);

        new Command('');
    }
}
