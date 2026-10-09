<?php declare(strict_types=1);

namespace Scheduling\Bridge\Laravel;

use Illuminate\Console\Scheduling\Event;
use ReflectionException;
use ReflectionProperty;

/**
 * Reads the parts of a scheduled event that do not map onto a task: what Laravel keeps in protected
 * properties (filters, before and after callbacks) and the output settings. It turns them into the review
 * notes printed above an exported task, so nothing the app configured is lost without a word.
 */
final readonly class EventInspector
{
    public function __construct(private Event $event) {}

    /**
     * The output file the exported task can keep: a real file the event appends to, which is what the Laravel
     * bridge does with `adapters.laravel.output_file`.
     */
    public function appendedOutputFile(): ?string
    {
        return $this->event->shouldAppendOutput ? $this->outputFile() : null;
    }

    /**
     * What changes or is lost when the event becomes a task, one sentence each.
     *
     * @return list<string>
     *
     * @throws ReflectionException
     */
    public function notes(): array
    {
        $notes = [];
        if ($this->conditionCount() > 0) {
            $notes[] = "review: the app's conditions are closures and were not exported";
        }
        if ($this->event->evenInMaintenanceMode) {
            $notes[] = 'review: the app runs this in maintenance mode; the exported task does not';
        }
        // evenWhenPaused exists from Laravel 13 on; read by name so the same code analyses on 12 and 13.
        if (true === (get_object_vars($this->event)['evenWhenPaused'] ?? false)) {
            $notes[] = 'review: the app runs this while the schedule is paused; the exported task does not';
        }
        if ($this->event->runInBackground) {
            $notes[] = 'review: the app runs this in the background; the Laravel bridge runs tasks one after another';
        }
        if ($this->callbackCount() > 0) {
            $notes[] = 'review: before/after callbacks (also emailed output and pings) were not exported';
        }
        if (null !== $this->event->user) {
            $notes[] = "review: the app runs this as user {$this->event->user}; the exported task runs as the scheduler's user";
        }
        if (null !== $this->outputFile() && !$this->event->shouldAppendOutput) {
            $notes[] = "review: the app overwrites {$this->outputFile()} on every run; the exported task has no output file because ours appends";
        }
        if (!$this->event->onOneServer) {
            $notes[] = 'review: the app did not set onOneServer; the exported task always runs on one server';
        }
        if (!$this->event->withoutOverlapping) {
            $notes[] = 'review: the app did not set withoutOverlapping; the exported task always takes the overlap lock';
        }

        return $notes;
    }

    private function outputFile(): ?string
    {
        return $this->event->output === $this->event->getDefaultOutput() ? null : $this->event->output;
    }

    /**
     * Laravel's `withoutOverlapping()` registers one reject of its own; it is not a condition of the app.
     *
     * @throws ReflectionException
     */
    private function conditionCount(): int
    {
        $own = $this->event->withoutOverlapping ? 1 : 0;

        return count($this->property('filters')) + max(0, count($this->property('rejects')) - $own);
    }

    /** @throws ReflectionException */
    private function callbackCount(): int
    {
        return count($this->property('beforeCallbacks')) + count($this->property('afterCallbacks'));
    }

    /**
     * @return array<array-key, mixed>
     *
     * @throws ReflectionException
     */
    private function property(string $name): array
    {
        $value = new ReflectionProperty(Event::class, $name)->getValue($this->event);

        return is_array($value) ? $value : [];
    }
}
