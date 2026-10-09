<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use DateTimeZone;
use Illuminate\Config\Repository as Config;
use Illuminate\Console\Command;
use Illuminate\Console\Scheduling\CallbackEvent;
use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;
use ReflectionException;
use Scheduling\Exception\InvalidTaskException;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * `scheduling:export`: the migration path from an application's own schedule to the tasks file. It reads the
 * entries the app registered itself (`routes/console.php`, `Kernel::schedule()`) and prints a YAML tasks file
 * for every artisan command, with review notes above each task for what changes. Everything it cannot express
 * (closures, jobs, shell commands, sub-minute entries) is listed in a trailing `# Not exported:` block. The
 * output is meant to be pasted into the tasks file one task at a time, deleting the PHP line after each.
 * Tasks the package registered itself are skipped, so running the export again after a paste lists the rest.
 */
final class ExportCommand extends Command
{
    private const string OWN_MUTEX_PREFIX = 'scheduler:task:';
    private const int PLACEHOLDER_MAX_RUNTIME = 3600;
    private const int MAX_ID_LENGTH = 100;

    protected $signature = 'scheduling:export';

    protected $description = 'Print the schedule defined in PHP as a tasks file, listing the entries that cannot be exported';

    /**
     * Prints the tasks file to stdout; always succeeds, because an entry that cannot be exported is reported
     * in the output, not as a failure.
     *
     * @throws ReflectionException
     */
    public function handle(Schedule $schedule, Config $config): int
    {
        $taken = [];
        $appEvents = [];
        foreach ($schedule->events() as $event) {
            $mutex = $event->mutexName();
            if (str_starts_with($mutex, self::OWN_MUTEX_PREFIX)) {
                $taken[] = substr($mutex, strlen(self::OWN_MUTEX_PREFIX));
            } else {
                $appEvents[] = $event;
            }
        }

        $tasks = [];
        $notExported = [];
        foreach ($appEvents as $event) {
            try {
                $tasks[] = $this->renderTask($event, $config->string('app.timezone'), $taken);
            } catch (InvalidTaskException $e) {
                $notExported[] = self::describeSkipped($event, $e->getMessage());
            }
        }

        $this->output->write($this->document($tasks, $notExported), false, OutputInterface::OUTPUT_RAW);

        return self::SUCCESS;
    }

    /**
     * @param list<string> $taken ids already used by the package's own tasks or earlier exported entries; the new id is added
     *
     * @return string the task's comment lines and YAML, indented to sit under `tasks:`
     *
     * @throws InvalidTaskException when the entry has no task form
     * @throws ReflectionException
     */
    private function renderTask(Event $event, string $appTimezone, array &$taken): string
    {
        if ($event instanceof CallbackEvent) {
            throw new InvalidTaskException('closure or job');
        }
        if (null !== $event->repeatSeconds) {
            throw new InvalidTaskException('sub-minute schedule');
        }

        $command = ArtisanCommandLine::parse((string) $event->command);
        $inspector = new EventInspector($event);
        $id = self::uniqueId($command->name, $taken);
        $taken[] = $id;

        $lines = [];
        if (is_string($event->description) && '' !== $event->description) {
            $lines[] = '# '.self::oneLine($event->description);
        }
        foreach ($inspector->notes() as $note) {
            $lines[] = "# {$note}";
        }

        $row = ['schedule' => $event->expression, 'command' => $command->name];
        if ([] !== $command->arguments) {
            $row['arguments'] = $command->arguments;
        }
        if ([] !== $command->options) {
            $row['options'] = $command->options;
        }
        $timezone = $event->timezone instanceof DateTimeZone ? $event->timezone->getName() : (string) $event->timezone;
        if ('' !== $timezone && $timezone !== $appTimezone) {
            $row['timezone'] = $timezone;
        }
        if ($event->withoutOverlapping) {
            $row['max_runtime'] = (int) $event->expiresAt * 60;
            $row['lock_margin'] = 1.0;
        } else {
            $row['max_runtime'] = self::PLACEHOLDER_MAX_RUNTIME;
        }
        if ([] !== $event->environments) {
            $row['environments'] = array_values(array_filter($event->environments, is_string(...)));
        }
        $outputFile = $inspector->appendedOutputFile();
        if (null !== $outputFile) {
            $row['adapters'] = ['laravel' => ['output_file' => $outputFile]];
        }

        foreach (explode("\n", rtrim(Yaml::dump([$id => $row], 2, 2))) as $line) {
            $lines[] = $event->withoutOverlapping ? $line : (string) preg_replace('/^(\s+max_runtime: \d+)$/', '$1 # placeholder: set the real maximum runtime by hand', $line);
        }

        return implode("\n", array_map(static fn (string $line): string => '  '.$line, $lines));
    }

    /**
     * @param list<string> $tasks
     * @param list<string> $notExported
     */
    private function document(array $tasks, array $notExported): string
    {
        $sections = [];
        if ([] !== $tasks) {
            $sections[] = "tasks:\n".implode("\n\n", $tasks);
        }
        if ([] !== $notExported) {
            $sections[] = "# Not exported:\n".implode("\n", $notExported);
        }

        return ([] === $sections ? '# Nothing to export.' : implode("\n\n", $sections))."\n";
    }

    private static function describeSkipped(Event $event, string $reason): string
    {
        $what = self::oneLine($event instanceof CallbackEvent ? $event->getSummaryForDisplay() : Event::normalizeCommand((string) $event->command));
        $description = is_string($event->description) ? self::oneLine($event->description) : '';
        $suffix = '' !== $description && $what !== $description ? " ({$description})" : '';

        return "#   {$event->expression}  {$what}{$suffix}  [{$reason}]";
    }

    /**
     * The command name as a task id: lower-case, characters outside `[a-z0-9._-]` replaced by `-`, `-2`, `-3`
     * and so on when the id is taken.
     *
     * @param list<string> $taken
     */
    private static function uniqueId(string $commandName, array $taken): string
    {
        $slug = ltrim((string) preg_replace('/[^a-z0-9._-]/', '-', strtolower($commandName)), '._-');
        $slug = '' === $slug ? 'task' : $slug;
        $id = substr($slug, 0, self::MAX_ID_LENGTH);
        for ($n = 2; in_array($id, $taken, true); ++$n) {
            $suffix = "-{$n}";
            $id = substr($slug, 0, self::MAX_ID_LENGTH - strlen($suffix)).$suffix;
        }

        return $id;
    }

    private static function oneLine(string $text): string
    {
        return trim((string) preg_replace('/\s+/', ' ', $text));
    }
}
