<?php declare(strict_types=1);

namespace Scheduling\Bridge\Crunz;

use Crunz\Event;
use Crunz\Schedule;
use Crunz\Task\TaskException;
use DateTimeImmutable;
use Scheduling\Exception\InvalidTaskException;
use Scheduling\Exception\LockUnavailableException;
use Scheduling\Lock\LockKey;
use Scheduling\Lock\MutexInterface;
use Scheduling\Run\RunListenerInterface;
use Scheduling\Run\SkipReason;
use Scheduling\SchedulerEngineInterface;
use Scheduling\Task\Command;
use Scheduling\Task\TaskDefinition;
use Scheduling\Task\TaskId;
use Scheduling\Task\TaskProblem;

final class CrunzEngine implements SchedulerEngineInterface
{
    private const ONE_SERVER_TTL = 3600;

    /** @var array<int, TaskId> keyed by spl_object_id of the crunz Event */
    private array $idsByEvent = [];

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
            $id = $this->idsByEvent[spl_object_id($event)] ?? null;
            if (null !== $id) {
                $this->listener->failed($id, new DateTimeImmutable(), null, null);
            }
        });
    }

    /** {@inheritDoc} */
    public function name(): string
    {
        return 'crunz';
    }

    /** {@inheritDoc} */
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
            ->skip(fn (): bool => !$this->claimMinute($id))
            ->preventOverlapping(new MutexStore($this->mutex, LockKey::forTask($id)))
            ->before(fn () => $this->listener->started($id, new DateTimeImmutable()))
            ->after(fn () => $this->listener->finished($id, new DateTimeImmutable()));

        $this->idsByEvent[spl_object_id($event)] = $id;
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
