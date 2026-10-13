<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;

/**
 * What a task runs: a console command name with its arguments and options, never a shell string.
 * The name and the option names end up on a command line unquoted, so they are held to the characters a console
 * command or option name is made of; a name carrying shell syntax is refused when the command is built, whichever
 * source (YAML, database, code) it came from. Arguments and option values are free text: every engine quotes them.
 */
final class Command
{
    /**
     * The console command name, e.g. `reports:send`: letters, digits and `: _ . -`, starting with a letter or digit.
     */
    public private(set) string $name {
        /** @throws InvalidTaskException when empty or made of anything else */
        set {
            if ('' === $value) {
                throw new InvalidTaskException('Command name is empty');
            }
            if (1 !== preg_match('/^[A-Za-z0-9][A-Za-z0-9:_.-]*$/D', $value)) {
                throw new InvalidTaskException("Command name '{$value}' may only hold letters, digits and : _ . - and must start with a letter or digit");
            }
            $this->name = $value;
        }
    }

    /**
     * The options by name, without dashes; `true` is a flag, a list repeats the option. Option names are letters,
     * digits and `_ -`, starting with a letter, because engines write them unquoted.
     *
     * @var array<non-empty-string, string|list<string>|true>
     */
    public private(set) array $options {
        /** @throws InvalidTaskException when an option name is made of anything else */
        set {
            foreach (array_keys($value) as $option) {
                if (1 !== preg_match('/^[A-Za-z][A-Za-z0-9_-]*$/D', (string) $option)) {
                    throw new InvalidTaskException("Option name '{$option}' may only hold letters, digits and _ - and must start with a letter");
                }
            }
            $this->options = $value;
        }
    }

    /**
     * @param string                                            $name      the command name; empty or unsafe is refused
     * @param list<string>                                      $arguments positional, in order
     * @param array<non-empty-string, string|list<string>|true> $options   without dashes; true = flag
     *
     * @throws InvalidTaskException when the name or an option name is not made of the allowed characters
     */
    public function __construct(
        string $name,
        public private(set) array $arguments = [],
        array $options = [],
    ) {
        $this->name = $name;
        $this->options = $options;
    }
}
