<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

/**
 * The identity of a task and the key every lock and run record is built from.
 * Lower-case letters, digits, dots, underscores and hyphens; starts with a letter or digit; 100 characters at most.
 */
final class TaskId
{
    public private(set) string $value {
        /** @throws InvalidTaskException */
        set => preg_match('/^[a-z0-9][a-z0-9._-]{0,99}$/D', $value)
            ? $value
            : throw new InvalidTaskException("Invalid task id: {$value}");
    }

    /**
     * @param string $value the id as written in YAML
     *
     * @throws InvalidTaskException when it does not match the allowed pattern
     */
    public function __construct(string $value)
    {
        $this->value = $value;
    }
}
