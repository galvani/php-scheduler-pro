<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use Crunz\Schedule;
use Crunz\Task\TaskException;
use DateTimeImmutable;
use Psr\Log\LoggerInterface;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;

/**
 * The crunz bridge. crunz keeps deciding what is due and runs each task as a subprocess; this class registers every
 * task with crunz's own API and adds what crunz lacks:
 *
 * - overlap lock: our mutex as crunz's Symfony Lock store (MutexStore), extended while the task runs;
 * - one server: the same per-minute claim Laravel's slot uses, taken in crunz's skip().
 */
final class CrunzEngine implements SchedulerEngineInterface
{
    private const ONE_SERVER_TTL = 3600;

    /**
     * @param non-empty-string $consoleBinary e.g. "php /app/artisan"
     * @param LoggerInterface  $logger        warns about a run skipped for unreachable lock storage
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly MutexInterface $mutex,
        private readonly string $consoleBinary,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * The engine's name; also the key of its section in a task's adapter options (`adapters: {laravel: …}`).
     *
     * @return non-empty-string
     */
    public function name(): string
    {
        return 'crunz';
    }

    /**
     * Registers the tasks with the engine for the current tick. A disabled task is not registered.
     *
     * @param array<string, TaskDefinition> $tasks keyed by task id
     *
     * @return list<TaskProblem> tasks the engine refused, e.g. invalid adapter options; the rest are registered
     */
    public function register(array $tasks): array
    {
        $problems = [];
        foreach ($tasks as $task) {
            if (!$task->enabled) {
                continue;
            }
            try {
                CrunzOptions::fromArray($task->options->for($this->name()));
                $this->registerOne($task);
            } catch (InvalidTaskException|TaskException $e) {
                $problems[] = new TaskProblem($task->id->value, 'crunz', $e->getMessage());
            }
        }

        return $problems;
    }

    /** The crunz tasks file returns this. */
    public function schedule(): Schedule
    {
        return $this->schedule;
    }

    /** @throws TaskException */
    private function registerOne(TaskDefinition $task): void
    {
        $id = $task->id;
        $this->schedule
            ->run($this->consoleBinary.' '.$task->command->name, self::parameters($task->command))
            ->cron($task->schedule->value)
            ->timezone($task->timezone)
            ->skip(fn (): bool => !$this->claimMinute($id))
            ->preventOverlapping(new MutexStore($this->mutex, LockKey::forTask($id)));
    }

    /** crunz has no onOneServer; this is the same per-minute key Laravel's SchedulingMutex uses. */
    private function claimMinute(TaskId $id): bool
    {
        $minute = new DateTimeImmutable('now');
        $minute = $minute->setTime((int) $minute->format('H'), (int) $minute->format('i'));
        try {
            return null !== $this->mutex->acquire(LockKey::forTaskAt($id, $minute), self::ONE_SERVER_TTL);
        } catch (LockUnavailableException $e) {
            $this->logger->warning('Lock storage unreachable; the run is skipped', ['task_id' => $id->value, 'exception' => $e]);

            return false;
        }
    }

    /**
     * The arguments and options as crunz parameters, one string per word. Unlike Laravel's, crunz's
     * compileParameters() quotes every item itself (Symfony Process::fromArrayCommand()), so nothing is quoted here.
     *
     * @return list<string>
     */
    private static function parameters(Command $command): array
    {
        $parameters = $command->arguments;
        foreach ($command->options as $name => $value) {
            foreach (true === $value ? [null] : (array) $value as $item) {
                $parameters[] = null === $item ? "--{$name}" : "--{$name}={$item}";
            }
        }

        return $parameters;
    }
}
