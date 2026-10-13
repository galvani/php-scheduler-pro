<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\Attributes\DataProvider;
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

    /** @throws InvalidTaskException */
    #[DataProvider('unsafeNames')]
    public function testRejectsAnUnsafeName(string $name): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('Command name');

        new Command($name);
    }

    /** @return iterable<string, array{string}> */
    public static function unsafeNames(): iterable
    {
        yield 'semicolon' => ['app:hello; touch /tmp/pwned'];
        yield 'space' => ['app:hello world'];
        yield 'pipe' => ['app:hello|cat'];
        yield 'ampersand' => ['app:hello&&id'];
        yield 'dollar' => ['app:$HOME'];
        yield 'backtick' => ['app:`id`'];
        yield 'quote' => ["app:'x"];
        yield 'newline' => ["app:hello\ntouch"];
        yield 'trailing newline' => ["app:hello\n"];
        yield 'leading dash' => ['--version'];
        yield 'leading colon' => [':hello'];
    }

    /** @throws InvalidTaskException */
    #[DataProvider('safeNames')]
    public function testAcceptsAConsoleCommandName(string $name): void
    {
        self::assertSame($name, new Command($name)->name);
    }

    /** @return iterable<string, array{string}> */
    public static function safeNames(): iterable
    {
        yield 'plain' => ['cleanup'];
        yield 'namespaced' => ['reports:send'];
        yield 'dash and underscore' => ['scheduler-test:mark_all'];
        yield 'dot' => ['app:v1.2'];
    }

    /**
     * @param array<non-empty-string, string|true> $options
     *
     * @throws InvalidTaskException
     */
    #[DataProvider('unsafeOptionNames')]
    public function testRejectsAnUnsafeOptionName(array $options): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('Option name');

        new Command('reports:send', [], $options);
    }

    /** @return iterable<string, array{array<non-empty-string, string|true>}> */
    public static function unsafeOptionNames(): iterable
    {
        yield 'semicolon' => [['z; touch /tmp/pwned #' => true]];
        yield 'space' => [['a b' => 'x']];
        yield 'equals sign' => [['a=b' => 'x']];
        yield 'leading dash' => [['-force' => true]];
        yield 'dollar' => [['$HOME' => 'x']];
        yield 'trailing newline' => [["force\n" => true]];
    }

    /** @throws InvalidTaskException */
    public function testAcceptsDashedAndUnderscoredOptionNames(): void
    {
        $command = new Command('reports:send', [], ['dry-run' => true, 'max_rows' => '5']);

        self::assertSame(['dry-run' => true, 'max_rows' => '5'], $command->options);
    }

    /** @throws InvalidTaskException */
    public function testRejectsAnOptionWithoutAName(): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('Option name');

        new Command('reports:send', [], ['x']); // @phpstan-ignore argument.type
    }
}
