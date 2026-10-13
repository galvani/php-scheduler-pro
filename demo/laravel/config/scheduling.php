<?php declare(strict_types=1);

return [
    // The YAML file the package's tasks are read from.
    'tasks_file' => env('SCHEDULING_TASKS_FILE', base_path('scheduling.yaml')),

    // The Laravel Redis connection for the locks (phpredis, no key prefix). `scheduler` is
    // added for you as a copy of the `default` connection without its prefix.
    'redis' => env('SCHEDULING_REDIS_CONNECTION', 'scheduler'),
];
