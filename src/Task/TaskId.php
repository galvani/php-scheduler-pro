<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

final class TaskId
{
    public private(set) string $value {
        /** @throws InvalidTaskException */
        set => preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $value)
            ? $value
            : throw new InvalidTaskException("Invalid task id: {$value}");
    }

    /** @throws InvalidTaskException */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
