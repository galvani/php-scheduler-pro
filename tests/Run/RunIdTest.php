<?php declare(strict_types=1);

namespace Scheduling\Tests\Run;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidRunIdException;
use Scheduling\Run\RunId;

final class RunIdTest extends TestCase
{
    /** @throws InvalidRunIdException */
    public function testGeneratedIdsAreValidAndDistinct(): void
    {
        $first = RunId::generate();
        $second = RunId::generate();

        self::assertSame($first->value, new RunId($first->value)->value);
        self::assertMatchesRegularExpression('/^[0-9a-f]{32}$/D', $first->value);
        self::assertNotSame($first->value, $second->value);
    }

    /** @throws InvalidRunIdException */
    #[DataProvider('invalidIds')]
    public function testRejectsAnythingButThirtyTwoLowerCaseHexCharacters(string $value): void
    {
        $this->expectException(InvalidRunIdException::class);

        new RunId($value);
    }

    /** @return iterable<string, array{string}> */
    public static function invalidIds(): iterable
    {
        yield 'empty' => [''];
        yield 'too short' => [str_repeat('a', 31)];
        yield 'too long' => [str_repeat('a', 33)];
        yield 'upper case' => [str_repeat('A', 32)];
        yield 'trailing newline' => [str_repeat('a', 32)."\n"];
    }
}
