<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

/**
 * How long a task may run and how long its lock lives. The lock TTL is max runtime times margin,
 * so the lock outlasts a run that stays within its limit.
 */
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

    /** @var positive-int the lock lifetime: max_runtime x margin, rounded up, at least 1 second */
    public int $ttlSeconds {
        get => max(1, (int) ceil($this->maxRuntimeSeconds * $this->margin));
    }

    /**
     * @param int   $maxRuntimeSeconds the longest the task is expected to run, in seconds; at least 1
     * @param float $margin            multiplier giving the lock its TTL; at least 1.0
     *
     * @throws InvalidTaskException when either value is out of range
     */
    public function __construct(int $maxRuntimeSeconds, float $margin = 1.5)
    {
        $this->maxRuntimeSeconds = $maxRuntimeSeconds;
        $this->margin = $margin;
    }
}
