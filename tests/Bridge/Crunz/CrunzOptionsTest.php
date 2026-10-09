<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Crunz;

use PHPUnit\Framework\TestCase;
use Scheduling\Bridge\Crunz\CrunzOptions;
use Scheduling\Exception\InvalidTaskException;

/** Isolates the validation of the crunz section of a task's adapter options. */
final class CrunzOptionsTest extends TestCase
{
    /** @throws InvalidTaskException */
    public function testEmptyOptionsAreAccepted(): void
    {
        $this->expectNotToPerformAssertions();

        CrunzOptions::fromArray([]);
    }

    /** @throws InvalidTaskException */
    public function testUnknownOptionsAreRejectedByName(): void
    {
        $this->expectException(InvalidTaskException::class);
        $this->expectExceptionMessage('Unknown crunz options: speed, colour');

        CrunzOptions::fromArray(['speed' => 1, 'colour' => 'red']);
    }
}
