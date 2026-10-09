<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use Scheduling\Exception\InvalidTaskException;

/**
 * The crunz section of a task's adapter options (`adapters: {crunz: …}`). crunz takes none; the class exists
 * so an unknown crunz option is refused as a TaskProblem instead of being ignored.
 */
final readonly class CrunzOptions
{
    private const KNOWN = [];

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidTaskException
     */
    public static function fromArray(array $raw): self
    {
        $unknown = array_diff(array_keys($raw), self::KNOWN);
        if ([] !== $unknown) {
            throw new InvalidTaskException('Unknown crunz options: '.implode(', ', $unknown));
        }

        return new self();
    }
}
