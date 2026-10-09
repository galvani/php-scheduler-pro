<?php declare(strict_types=1);

namespace Scheduling\Tests\Task;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\LockPolicy;

final class LockPolicyTest extends TestCase
{
    /** @throws InvalidTaskException */
    #[DataProvider('ttls')]
    public function testTtlIsTheRuntimeTimesTheMarginRoundedUp(int $runtime, float $margin, int $ttl): void
    {
        self::assertSame($ttl, new LockPolicy($runtime, $margin)->ttlSeconds);
    }

    /** @return iterable<string, array{int, float, int}> */
    public static function ttls(): iterable
    {
        yield 'default margin' => [600, 1.5, 900];
        yield 'rounded up' => [3, 1.5, 5];
        yield 'margin of one' => [60, 1.0, 60];
        yield 'one second' => [1, 1.0, 1];
    }

    /** @throws InvalidTaskException */
    public function testMarginDefaultsToOneAndAHalf(): void
    {
        self::assertSame(1.5, new LockPolicy(60)->margin);
    }

    /** @throws InvalidTaskException */
    #[DataProvider('invalidRuntimes')]
    public function testRejectsANonPositiveRuntime(int $runtime): void
    {
        $this->expectException(InvalidTaskException::class);

        new LockPolicy($runtime);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidRuntimes(): iterable
    {
        yield 'zero' => [0];
        yield 'negative' => [-5];
    }

    /** @throws InvalidTaskException */
    public function testRejectsAMarginBelowOne(): void
    {
        $this->expectException(InvalidTaskException::class);

        new LockPolicy(60, 0.9);
    }
}
