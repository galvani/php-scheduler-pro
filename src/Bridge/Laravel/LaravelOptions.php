<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Scheduling\Exception\InvalidTaskException;

final readonly class LaravelOptions
{
    private const KNOWN = ['output_file'];

    public function __construct(public ?string $outputFile = null)
    {
    }

    /**
     * @param array<string, mixed> $raw
     *
     * @throws InvalidTaskException
     */
    public static function fromArray(array $raw): self
    {
        $unknown = array_diff(array_keys($raw), self::KNOWN);
        if ([] !== $unknown) {
            throw new InvalidTaskException('Unknown laravel options: '.implode(', ', $unknown));
        }
        $output = $raw['output_file'] ?? null;
        if (null !== $output && !is_string($output)) {
            throw new InvalidTaskException('laravel.output_file must be a string');
        }

        return new self($output);
    }
}
