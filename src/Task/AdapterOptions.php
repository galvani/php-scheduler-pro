<?php declare(strict_types=1);

namespace Scheduling\Task;

/**
 * Per-engine settings of a task, passed through untouched. The package never interprets them;
 * each bridge reads only its own adapter's map.
 */
final readonly class AdapterOptions
{
    /**
     * @param array<string, array<string, mixed>> $byAdapter option maps keyed by adapter name
     */
    public function __construct(public array $byAdapter = []) {}

    /**
     * The options for one adapter; an empty map when the task has none for it.
     *
     * @return array<string, mixed>
     */
    public function for(string $adapter): array
    {
        return $this->byAdapter[$adapter] ?? [];
    }
}
