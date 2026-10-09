<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

final class LockPolicy
{
    public private(set) int $maxRuntimeSeconds {
        /** @throws InvalidTaskException */
        set => $value >= 1 ? $value : throw new InvalidTaskException('max_runtime must be positive');
    }

    public private(set) float $margin {
        /** @throws InvalidTaskException */
        set => $value >= 1.0 ? $value : throw new InvalidTaskException('margin must be at least 1.0');
    }

    /** @var positive-int */
    public int $ttlSeconds {
        get => max(1, (int) ceil($this->maxRuntimeSeconds * $this->margin));
    }

    /** @throws InvalidTaskException */
    public function __construct(int $maxRuntimeSeconds, float $margin = 1.5)
    {
        $this->maxRuntimeSeconds = $maxRuntimeSeconds;
        $this->margin = $margin;
    }
}
