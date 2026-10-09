<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Illuminate\Console\Application;
use Illuminate\Console\Scheduling\Event;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Task\Command;

/**
 * Reads back the command line Laravel built for a scheduled artisan command (`Schedule::command()`), so the
 * export can name the command, its positional arguments and its options instead of keeping a shell string.
 * Only the quoting Laravel itself produces is understood: single quotes with `'\''` for a quote inside,
 * double quotes and backslashes for hand-written lines. Anything that would change meaning in a shell is refused.
 */
final class ArtisanCommandLine
{
    private const string SHELL_SYNTAX = "|&;<>()$`*?[]{}\n";

    private function __construct() {}

    /**
     * Turns a scheduled event's command into a task command.
     *
     * @param string $command the event's `$command`, with the PHP and artisan binaries as Laravel wrote them
     *
     * @throws InvalidTaskException when it is not an artisan command, uses shell syntax, uses short options,
     *                              or has an option followed by a bare word that may or may not be its value
     */
    public static function parse(string $command): Command
    {
        $prefix = Event::normalizeCommand(Application::formatCommandString(''));
        $line = Event::normalizeCommand($command);
        if (!str_starts_with($line, $prefix)) {
            throw new InvalidTaskException('shell command');
        }

        $words = self::words(substr($line, strlen($prefix)));
        $name = array_shift($words);
        if (null === $name || str_starts_with($name, '-')) {
            throw new InvalidTaskException('no artisan command name');
        }

        $arguments = [];
        $options = [];
        foreach ($words as $index => $word) {
            if (!str_starts_with($word, '-') || '-' === $word) {
                $arguments[] = $word;
                continue;
            }
            if (!str_starts_with($word, '--') || '--' === $word) {
                throw new InvalidTaskException("unsupported option {$word}");
            }
            self::addOption($options, substr($word, 2), $words[$index + 1] ?? null);
        }

        return new Command($name, $arguments, $options);
    }

    /**
     * @param array<non-empty-string, string|list<string>|true> $options
     *
     * @throws InvalidTaskException
     */
    private static function addOption(array &$options, string $option, ?string $next): void
    {
        $separator = strpos($option, '=');
        $name = false === $separator ? $option : substr($option, 0, $separator);
        if ('' === $name) {
            throw new InvalidTaskException("unsupported option --{$option}");
        }
        if (false === $separator) {
            if (null !== $next && !str_starts_with($next, '-')) {
                throw new InvalidTaskException("cannot tell whether --{$name} takes the value {$next}");
            }
            if (array_key_exists($name, $options)) {
                throw new InvalidTaskException("--{$name} is repeated as a flag");
            }
            $options[$name] = true;

            return;
        }

        $value = substr($option, $separator + 1);
        $current = $options[$name] ?? null;
        $options[$name] = match (true) {
            null === $current => $value,
            true === $current => throw new InvalidTaskException("--{$name} is used as a flag and with a value"),
            default => [...(array) $current, $value],
        };
    }

    /**
     * @return list<string>
     *
     * @throws InvalidTaskException
     */
    private static function words(string $line): array
    {
        $words = [];
        $word = null;
        $length = strlen($line);
        for ($i = 0; $i < $length; ++$i) {
            $char = $line[$i];
            if (' ' === $char || "\t" === $char) {
                if (null !== $word) {
                    $words[] = $word;
                    $word = null;
                }
                continue;
            }
            $word ??= '';
            $word .= match (true) {
                "'" === $char => self::singleQuoted($line, $i),
                '"' === $char => self::doubleQuoted($line, $i),
                '\\' === $char => $line[++$i] ?? throw new InvalidTaskException('dangling backslash'),
                str_contains(self::SHELL_SYNTAX, $char) => throw new InvalidTaskException('shell syntax'),
                default => $char,
            };
        }

        return null === $word ? $words : [...$words, $word];
    }

    /**
     * @param int $i the position of the opening quote; left on the closing one
     *
     * @throws InvalidTaskException
     */
    private static function singleQuoted(string $line, int &$i): string
    {
        $end = strpos($line, "'", $i + 1);
        if (false === $end) {
            throw new InvalidTaskException('unterminated quote');
        }
        $text = substr($line, $i + 1, $end - $i - 1);
        $i = $end;

        return $text;
    }

    /**
     * @param int $i the position of the opening quote; left on the closing one
     *
     * @throws InvalidTaskException
     */
    private static function doubleQuoted(string $line, int &$i): string
    {
        $text = '';
        for (++$i; $i < strlen($line); ++$i) {
            $char = $line[$i];
            if ('"' === $char) {
                return $text;
            }
            if ('$' === $char || '`' === $char) {
                throw new InvalidTaskException('shell syntax');
            }
            if ('\\' === $char && str_contains('\\"$`', $line[$i + 1] ?? '')) {
                $char = $line[++$i];
            }
            $text .= $char;
        }

        throw new InvalidTaskException('unterminated quote');
    }
}
