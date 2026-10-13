<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\TaskDefinitionMapper;

final class TaskDefinitionMapperTest extends TestCase
{
    private const array MINIMAL = ['schedule' => '0 6 * * *', 'command' => 'reports:send', 'max_runtime' => 600];

    /** @throws InvalidTaskException */
    public function testMapsAMinimalRowWithDefaults(): void
    {
        $task = $this->mapper()->fromArray('report', self::MINIMAL);

        self::assertSame('report', $task->id->value);
        self::assertSame('0 6 * * *', $task->schedule->value);
        self::assertSame('reports:send', $task->command->name);
        self::assertSame([], $task->command->arguments);
        self::assertSame([], $task->command->options);
        self::assertSame('Europe/Prague', $task->timezone->getName());
        self::assertSame(600, $task->lock->maxRuntimeSeconds);
        self::assertSame(1.5, $task->lock->margin);
        self::assertTrue($task->enabled);
        self::assertSame([], $task->options->byAdapter);
    }

    /** @throws InvalidTaskException */
    public function testMapsEveryKey(): void
    {
        $task = $this->mapper()->fromArray('report', [
            'schedule' => '0 6 * * *',
            'command' => 'reports:send',
            'arguments' => ['weekly'],
            'options' => ['queue' => 'high', 'force' => true, 'tag' => ['a', 'b']],
            'timezone' => 'UTC',
            'max_runtime' => 600,
            'lock_margin' => 2,
            'enabled' => false,
            'adapters' => ['laravel' => ['output_file' => '/tmp/x.log'], 'crunz' => []],
        ]);

        self::assertSame(['weekly'], $task->command->arguments);
        self::assertSame(['queue' => 'high', 'force' => true, 'tag' => ['a', 'b']], $task->command->options);
        self::assertSame('UTC', $task->timezone->getName());
        self::assertSame(2.0, $task->lock->margin);
        self::assertFalse($task->enabled);
        self::assertSame(['output_file' => '/tmp/x.log'], $task->options->for('laravel'));
        self::assertSame([], $task->options->for('crunz'));
    }

    /**
     * @param array<array-key, mixed> $row
     *
     * @throws InvalidTaskException
     */
    #[DataProvider('invalidRows')]
    public function testRejectsAnInvalidRowNamingTheKey(array $row, string $mentions): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage($mentions);

        $this->mapper()->fromArray('report', $row);
    }

    /** @return iterable<string, array{array<array-key, mixed>, string}> */
    public static function invalidRows(): iterable
    {
        $without = static fn (string $key): array => array_diff_key(self::MINIMAL, [$key => true]);

        yield 'unknown key' => [[...self::MINIMAL, 'cron' => 'x'], 'cron'];
        yield 'missing schedule' => [$without('schedule'), 'schedule'];
        yield 'missing command' => [$without('command'), 'command'];
        yield 'missing max_runtime' => [$without('max_runtime'), 'max_runtime'];
        yield 'schedule not a string' => [[...self::MINIMAL, 'schedule' => 5], 'schedule'];
        yield 'invalid schedule' => [[...self::MINIMAL, 'schedule' => 'nope'], 'cron'];
        yield 'command not a string' => [[...self::MINIMAL, 'command' => ['a']], 'command'];
        yield 'empty command' => [[...self::MINIMAL, 'command' => ''], 'Command'];
        yield 'command with shell syntax' => [[...self::MINIMAL, 'command' => 'app:hello; touch /tmp/pwned'], 'Command name'];
        yield 'option name with shell syntax' => [[...self::MINIMAL, 'options' => ['z; touch /tmp/pwned #' => true]], 'Option name'];
        yield 'max_runtime not an int' => [[...self::MINIMAL, 'max_runtime' => '600'], 'max_runtime'];
        yield 'max_runtime float' => [[...self::MINIMAL, 'max_runtime' => 60.5], 'max_runtime'];
        yield 'max_runtime zero' => [[...self::MINIMAL, 'max_runtime' => 0], 'max_runtime'];
        yield 'lock_margin not a number' => [[...self::MINIMAL, 'lock_margin' => 'big'], 'lock_margin'];
        yield 'lock_margin below one' => [[...self::MINIMAL, 'lock_margin' => 0.5], 'margin'];
        yield 'enabled not a bool' => [[...self::MINIMAL, 'enabled' => 'yes'], 'enabled'];
        yield 'timezone not a string' => [[...self::MINIMAL, 'timezone' => 1], 'timezone'];
        yield 'unknown timezone' => [[...self::MINIMAL, 'timezone' => 'Mars/Base'], 'Mars/Base'];
        yield 'arguments not a list' => [[...self::MINIMAL, 'arguments' => 'weekly'], 'arguments'];
        yield 'arguments holding an int' => [[...self::MINIMAL, 'arguments' => [1]], 'arguments'];
        yield 'arguments keyed' => [[...self::MINIMAL, 'arguments' => ['a' => 'b']], 'arguments'];
        yield 'options not a map' => [[...self::MINIMAL, 'options' => 'force'], 'options'];
        yield 'options holding false' => [[...self::MINIMAL, 'options' => ['force' => false]], 'options.force'];
        yield 'options holding an int' => [[...self::MINIMAL, 'options' => ['retries' => 3]], 'options.retries'];
        yield 'options holding a list of ints' => [[...self::MINIMAL, 'options' => ['ids' => [1]]], 'options.ids'];
        yield 'options with a numeric name' => [[...self::MINIMAL, 'options' => ['a']], 'options'];
        yield 'adapters not a map' => [[...self::MINIMAL, 'adapters' => ['laravel']], 'adapters'];
        yield 'adapter options not a map' => [[...self::MINIMAL, 'adapters' => ['laravel' => 'x']], 'adapters'];
        yield 'adapter options keyed by number' => [[...self::MINIMAL, 'adapters' => ['laravel' => ['x']]], 'adapters.laravel'];
    }

    /** @throws InvalidTaskException */
    public function testRejectsAnInvalidId(): void
    {
        $this->expectException(InvalidTaskException::class);

        $this->mapper()->fromArray('Not Valid', self::MINIMAL);
    }

    private function mapper(): TaskDefinitionMapper
    {
        return new TaskDefinitionMapper(new DateTimeZone('Europe/Prague'));
    }
}
