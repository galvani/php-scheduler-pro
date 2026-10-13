<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use Crunz\Event;
use Crunz\Schedule;
use Crunz\Task\TaskException;
use DateTimeImmutable;
use Scheduling\Capability\Capability;
use Scheduling\Capability\CapabilityReport;
use Scheduling\Capability\Fulfilment;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunId;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;

/**
 * The crunz bridge. crunz keeps deciding what is due and runs each task as a subprocess; this class registers every
 * task with crunz's own API and adds what crunz lacks:
 *
 * - overlap lock: native, our mutex as crunz's Symfony Lock store (MutexStore), extended while the task runs;
 * - one server: polyfilled, the same per-minute claim Laravel's slot uses, taken in crunz's skip();
 * - conditions: native, through crunz's when();
 * - run record: polyfilled from before(), after() and the schedule-level onError(). before() keeps the run id the
 *   listener returns, keyed like the task ids, and after() or onError() report the end of that same run. All three
 *   run in crunz's parent process.
 */
final class CrunzEngine implements SchedulerEngineInterface
{
    private const ONE_SERVER_TTL = 3600;

    /** @var array<int, TaskId> keyed by spl_object_id of the crunz Event */
    private array $idsByEvent = [];

    /** @var array<int, RunId> the run in progress, keyed by spl_object_id of the crunz Event */
    private array $runsByEvent = [];

    /**
     * @param non-empty-string $consoleBinary e.g. "php /app/artisan"
     */
    public function __construct(
        private readonly Schedule $schedule,
        private readonly MutexInterface $mutex,
        private readonly RunListenerInterface $listener,
        private readonly string $consoleBinary,
    ) {
        $this->schedule->onError(function (Event $event): void {
            $key = spl_object_id($event);
            $id = $this->idsByEvent[$key] ?? null;
            if (null !== $id) {
                $this->listener->failed($id, $this->endRun($key), new DateTimeImmutable(), null, null);
            }
        });
    }

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
     * What the engine does itself, what this package adds on top, and what neither can do.
     */
    public function capabilities(): CapabilityReport
    {
        return new CapabilityReport([
            Capability::OverlapLock->value => Fulfilment::Native,
            Capability::OneServer->value => Fulfilment::Polyfilled,
            Capability::Conditions->value => Fulfilment::Native,
            Capability::RunRecord->value => Fulfilment::Polyfilled,
            Capability::SubMinute->value => Fulfilment::Unavailable,
            Capability::MissedRunCatchUp->value => Fulfilment::Unavailable,
        ]);
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
        $event = $this->schedule
            ->run($this->consoleBinary.' '.$task->command->name, self::parameters($task->command))
            ->cron($task->schedule->value)
            ->timezone($task->timezone)
            ->when(fn (): bool => $this->conditionsHold($task))
            ->skip(fn (): bool => !$this->claimMinute($id))
            ->preventOverlapping(new MutexStore($this->mutex, LockKey::forTask($id)));
        $key = spl_object_id($event);
        $event
            ->before(function () use ($id, $key): void {
                $this->runsByEvent[$key] = $this->listener->started($id, new DateTimeImmutable());
            })
            ->after(fn () => $this->listener->finished($id, $this->endRun($key), new DateTimeImmutable()));

        $this->idsByEvent[$key] = $id;
    }

    /**
     * Takes the run in progress of an event off the map. An end without a start before it gets a fresh run id,
     * which the listener does not know and so never judges overran.
     */
    private function endRun(int $key): RunId
    {
        $run = $this->runsByEvent[$key] ?? RunId::generate();
        unset($this->runsByEvent[$key]);

        return $run;
    }

    private function conditionsHold(TaskDefinition $task): bool
    {
        if ($task->conditionsHold()) {
            return true;
        }
        $this->listener->skipped($task->id, new DateTimeImmutable(), SkipReason::ConditionNotMet);

        return false;
    }

    /** crunz has no onOneServer; this is the same per-minute key Laravel's SchedulingMutex uses. */
    private function claimMinute(TaskId $id): bool
    {
        $minute = new DateTimeImmutable('now');
        $minute = $minute->setTime((int) $minute->format('H'), (int) $minute->format('i'));
        try {
            $claimed = null !== $this->mutex->acquire(LockKey::forTaskAt($id, $minute), self::ONE_SERVER_TTL);
        } catch (LockUnavailableException) {
            $this->listener->skipped($id, new DateTimeImmutable(), SkipReason::LockUnavailable);

            return false;
        }
        if ($claimed) {
            return true;
        }
        $this->listener->skipped($id, new DateTimeImmutable(), SkipReason::RanOnAnotherServer);

        return false;
    }

    /** @return list<string> crunz accepts strings only and escapes each item as one argv entry */
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
