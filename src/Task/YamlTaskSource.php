<?php declare(strict_types=1);

namespace Scheduling\Task;

use Scheduling\Exception\InvalidTaskException;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Yaml;

/**
 * Read-only task source backed by a YAML file with a top-level `tasks` map keyed by task id.
 * An unparsable file, a missing `tasks` map or a bad entry is reported as a TaskProblem; valid entries still load.
 */
final readonly class YamlTaskSource implements TaskSourceInterface
{
    /**
     * @param string $path absolute or relative path of the YAML file
     */
    public function __construct(
        private string $path,
        private TaskDefinitionMapper $mapper,
    ) {}

    /** {@inheritDoc} */
    public function load(): TaskSet
    {
        try {
            $document = Yaml::parseFile($this->path);
        } catch (ParseException $e) {
            return new TaskSet([], [new TaskProblem(null, 'yaml', $e->getMessage())]);
        }

        $rows = is_array($document) && array_key_exists('tasks', $document) ? ($document['tasks'] ?? []) : null;
        if (!is_array($rows)) {
            return new TaskSet([], [new TaskProblem(null, 'yaml', "{$this->path} must hold a tasks map")]);
        }

        $tasks = [];
        $problems = [];
        foreach ($rows as $id => $row) {
            $id = (string) $id;
            try {
                if (!is_array($row)) {
                    throw new InvalidTaskException('A task must be a map');
                }
                $tasks[$id] = $this->mapper->fromArray($id, $row);
            } catch (InvalidTaskException $e) {
                $problems[] = new TaskProblem($id, 'yaml', $e->getMessage());
            }
        }

        return new TaskSet($tasks, $problems);
    }
}
