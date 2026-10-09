<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Console\Application;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Laravel\ArtisanCommandLine;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\Command;

final class ArtisanCommandLineTest extends TestCase
{
    /**
     * @param list<string>                                      $arguments
     * @param array<non-empty-string, string|list<string>|true> $options
     *
     * @throws InvalidTaskException
     */
    #[DataProvider('readableLines')]
    public function testItReadsBackWhatLaravelWrote(string $line, string $name, array $arguments, array $options): void
    {
        $command = ArtisanCommandLine::parse(Application::formatCommandString($line));

        self::assertSame($name, $command->name);
        self::assertSame($arguments, $command->arguments);
        self::assertSame($options, $command->options);
    }

    /**
     * @return iterable<string, array{string, string, list<string>, array<non-empty-string, string|list<string>|true>}>
     */
    public static function readableLines(): iterable
    {
        yield 'bare command' => ['sync:run', 'sync:run', [], []];
        yield 'escaped arguments' => ["sync:run 'two words' 'it'\\''s' ''", 'sync:run', ['two words', "it's", ''], []];
        yield 'options' => ["sync:run --queue='high' --note='a b' --empty=", 'sync:run', [], ['queue' => 'high', 'note' => 'a b', 'empty' => '']];
        yield 'repeated option' => ['sync:run --tag=a --tag=b --tag=c', 'sync:run', [], ['tag' => ['a', 'b', 'c']]];
        yield 'flag' => ['sync:run weekly --force', 'sync:run', ['weekly'], ['force' => true]];
        yield 'value with equals sign' => ["sync:run --filter='a=b'", 'sync:run', [], ['filter' => 'a=b']];
        yield 'option quoted after the equals sign' => ["sync:run --path='/a b'/c", 'sync:run', [], ['path' => '/a b/c']];
        yield 'double quotes' => ['sync:run "a \\"b\\" \\$c"', 'sync:run', ['a "b" $c'], []];
        yield 'backslash' => ['sync:run a\\ b', 'sync:run', ['a b'], []];
        yield 'dash is an argument' => ['sync:run -', 'sync:run', ['-'], []];
    }

    /**
     * @throws InvalidTaskException
     */
    public function testItReadsALineWithoutLaravelsQuotingOfTheBinaries(): void
    {
        self::assertEquals(new Command('sync:run', ['a']), ArtisanCommandLine::parse('php artisan sync:run a'));
    }

    /**
     * @throws InvalidTaskException
     */
    #[DataProvider('refusedLines')]
    public function testItRefusesWhatItCannotMapWithoutChangingItsMeaning(string $line, string $reason): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage($reason);

        ArtisanCommandLine::parse(Application::formatCommandString($line));
    }

    /**
     * @throws InvalidTaskException
     */
    #[DataProvider('shellCommands')]
    public function testItRefusesAShellCommand(string $command): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('shell command');

        ArtisanCommandLine::parse($command);
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function shellCommands(): iterable
    {
        yield 'ls' => ['ls -la'];
        yield 'another php' => ['/opt/php8/bin/php artisan sync:run'];
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function refusedLines(): iterable
    {
        yield 'pipe' => ['sync:run | tee x', 'shell syntax'];
        yield 'redirect' => ['sync:run > x', 'shell syntax'];
        yield 'variable' => ['sync:run $HOME', 'shell syntax'];
        yield 'variable in double quotes' => ['sync:run "$HOME"', 'shell syntax'];
        yield 'glob' => ['sync:run *.csv', 'shell syntax'];
        yield 'unterminated single quote' => ["sync:run 'a", 'unterminated quote'];
        yield 'unterminated double quote' => ['sync:run "a', 'unterminated quote'];
        yield 'dangling backslash' => ['sync:run a\\', 'dangling backslash'];
        yield 'no name' => ['', 'no artisan command name'];
        yield 'short option' => ['sync:run -q', 'unsupported option -q'];
        yield 'end of options' => ['sync:run -- a', 'unsupported option --'];
        yield 'option without a name' => ['sync:run --=a', 'unsupported option --=a'];
        yield 'flag then word' => ['sync:run --x y', 'cannot tell whether --x takes the value y'];
        yield 'repeated flag' => ['sync:run --x --x', '--x is repeated as a flag'];
        yield 'flag and value' => ['sync:run --x --x=1', '--x is used as a flag and with a value'];
    }
}
