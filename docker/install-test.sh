#!/usr/bin/env bash
# Installs the package into a fresh application with `composer require`, the way a user would, and runs a task.
# Usage: install-test.sh laravel [version constraint] | symfony
set -euo pipefail

framework="${1:?laravel or symfony}"
work="$(mktemp -d)"
redis_host="${REDIS_HOST:-redis}"
redis_port="${REDIS_PORT:-6379}"
export COMPOSER_NO_INTERACTION=1

git config --global --add safe.directory /app
package_repository='{"type": "path", "url": "/app", "options": {"symlink": false}}'

redis_runs() {
    php -r '
        $redis = new Redis();
        $redis->connect($argv[1], (int) $argv[2]);
        foreach (array_reverse($redis->lRange("scheduler:runs:install-test", 0, -1)) as $record) {
            $run = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
            printf("%-9s %-8s %s\n", $run["state"], $run["host"], $run["detail"] ?? "");
        }
    ' "$redis_host" "$redis_port"
}

redis_clear() {
    php -r '
        $redis = new Redis();
        $redis->connect($argv[1], (int) $argv[2]);
        foreach ($redis->keys("scheduler:*install-test*") as $key) {
            $redis->del($key);
        }
    ' "$redis_host" "$redis_port"
}

wait_for_fresh_minute() {
    local seconds=$((10#$(date +%S)))
    if ((seconds > 45)); then sleep $((61 - seconds)); fi
}

tasks_yaml() {
    cat <<YAML
tasks:
  install-test:
    schedule: "* * * * *"
    command: $1
    arguments: ["$2"]
    max_runtime: 60
YAML
}

case "$framework" in
laravel)
    constraint="${2:-}"
    echo "== composer create-project laravel/laravel ${constraint}"
    composer create-project --quiet laravel/laravel "$work/app" ${constraint:+"$constraint"}
    cd "$work/app"
    echo "== composer require galvani/php-scheduler-pro"
    composer config repositories.scheduler "$package_repository"
    composer require galvani/php-scheduler-pro:@dev
    php artisan --version

    echo "== auto-discovery and publishing"
    php artisan package:discover | grep php-scheduler-pro
    php artisan vendor:publish --tag=scheduling-config
    test -f config/scheduling.php

    cat >> routes/console.php <<'PHP'

Artisan::command('install-test:mark {path}', function (string $path): void {
    file_put_contents($path, gethostname().PHP_EOL, FILE_APPEND);
});
PHP
    tasks_yaml install-test:mark "$work/marker.log" > scheduling.yaml
    {
        echo "REDIS_CLIENT=phpredis"
        echo "REDIS_HOST=$redis_host"
        echo "REDIS_PORT=$redis_port"
    } >> .env
    redis_clear

    echo "== schedule:list"
    php artisan schedule:list | grep install-test:mark

    wait_for_fresh_minute
    echo "== schedule:run on server-a"
    SCHEDULING_HOST=server-a php artisan schedule:run
    echo "== schedule:run on server-b, same minute"
    SCHEDULING_HOST=server-b php artisan schedule:run
    ;;

symfony)
    echo "== composer create-project symfony/skeleton"
    composer create-project --quiet symfony/skeleton "$work/app"
    cd "$work/app"
    echo "== composer require galvani/php-scheduler-pro crunzphp/crunz"
    composer config repositories.scheduler "$package_repository"
    composer config extra.symfony.allow-contrib true
    composer require galvani/php-scheduler-pro:@dev crunzphp/crunz
    php bin/console --version

    echo "== register and configure the bundle"
    sed -i 's/^];/    Scheduling\\Bridge\\Symfony\\SchedulingBundle::class => ['"'"'all'"'"' => true],\n];/' config/bundles.php
    cat > config/packages/scheduling.yaml <<YAML
scheduling:
    tasks_file: '%kernel.project_dir%/config/scheduling.yaml'
    console_binary: 'php %kernel.project_dir%/bin/console'
    host: '%env(SCHEDULING_HOST)%'
    redis: {host: '$redis_host', port: $redis_port}
YAML
    mkdir -p src/Command tasks
    cat > src/Command/MarkCommand.php <<'PHP'
<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\Argument;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand('install-test:mark')]
final class MarkCommand
{
    public function __invoke(#[Argument] string $path): int
    {
        file_put_contents($path, gethostname().PHP_EOL, FILE_APPEND);

        return 0;
    }
}
PHP
    tasks_yaml install-test:mark "$work/marker.log" > config/scheduling.yaml
    printf 'source: tasks\nsuffix: Tasks.php\ntimezone: UTC\n' > crunz.yml
    cat > tasks/SchedulingTasks.php <<'PHP'
<?php declare(strict_types=1);

use App\Kernel;
use Scheduling\Bridge\Symfony\CrunzTasks;
use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';
new Dotenv()->bootEnv(dirname(__DIR__).'/.env');
$kernel = new Kernel($_SERVER['APP_ENV'], (bool) $_SERVER['APP_DEBUG']);
$kernel->boot();

return $kernel->getContainer()->get(CrunzTasks::class)->schedule();
PHP
    redis_clear

    wait_for_fresh_minute
    echo "== crunz schedule:run on server-a"
    SCHEDULING_HOST=server-a vendor/bin/crunz schedule:run
    echo "== crunz schedule:run on server-b, same minute"
    SCHEDULING_HOST=server-b vendor/bin/crunz schedule:run
    ;;

*)
    echo "unknown framework: $framework" >&2
    exit 2
    ;;
esac

echo "== marker"
cat "$work/marker.log"
echo "== run record"
runs="$(redis_runs)"
echo "$runs"

test "$(wc -l < "$work/marker.log")" -eq 1
grep -q '^started .*server-a' <<<"$runs"
grep -q '^finished .*server-a' <<<"$runs"
grep -q '^skipped .*server-b ran_on_another_server' <<<"$runs"
echo "== OK: installed with composer require, ran once, skipped on the second server"
