<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

final class Command
{
    public private(set) string $name {
        /** @throws InvalidTaskException */
        set => '' !== $value ? $value : throw new InvalidTaskException('Command name is empty');
    }

    /**
     * @param list<string>                                      $arguments positional, in order
     * @param array<non-empty-string, string|list<string>|true> $options   without dashes; true = flag
     *
     * @throws InvalidTaskException
     */
    public function __construct(
        string $name,
        public private(set) array $arguments = [],
        public private(set) array $options = [],
    ) {
        $this->name = $name;
    }
}
