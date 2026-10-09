<?php declare(strict_types=1);

namespace Scheduling\Task;

final readonly class AdapterOptions
{
    /**
     * @param array<string, array<string, mixed>> $byAdapter
     */
    public function __construct(private array $byAdapter = [])
    {
    }

    /** @return array<string, mixed> */
    public function for(string $adapter): array
    {
        return $this->byAdapter[$adapter] ?? [];
    }
}
