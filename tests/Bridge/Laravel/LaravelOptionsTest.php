<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Laravel\LaravelOptions;
use Scheduling\Exception\InvalidTaskException;

/** Isolates the validation of the laravel section of a task's adapter options. */
final class LaravelOptionsTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testEmptyOptionsHaveNoOutputFile(): void
    {
        self::assertNull(LaravelOptions::fromArray([])->outputFile);
    }

    /** @throws InvalidTaskException */
    public function testOutputFileIsRead(): void
    {
        self::assertSame('/tmp/x.log', LaravelOptions::fromArray(['output_file' => '/tmp/x.log'])->outputFile);
    }

    /** @throws InvalidTaskException */
    public function testNullOutputFileIsAccepted(): void
    {
        self::assertNull(LaravelOptions::fromArray(['output_file' => null])->outputFile);
    }

    /** @throws InvalidTaskException */
    public function testUnknownOptionsAreRejectedByName(): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('Unknown laravel options: speed, colour');

        LaravelOptions::fromArray(['output_file' => '/tmp/x.log', 'speed' => 1, 'colour' => 'red']);
    }

    /** @throws InvalidTaskException */
    public function testNonStringOutputFileIsRejected(): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('laravel.output_file must be a string');

        LaravelOptions::fromArray(['output_file' => 42]);
    }
}
