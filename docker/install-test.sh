#!/usr/bin/env bash
# Installs the package into a fresh application with `composer require`, the way a user would, and runs a task.
# Usage: install-test.sh laravel [version constraint]
set -euo pipefail

framework="${1:?laravel}"
work="$(mktemp -d)"
redis_host="${REDIS_HOST:-redis}"
redis_port="${REDIS_PORT:-6379}"
export COMPOSER_NO_INTERACTION=1

git config --global --add safe.directory /app
package_repository='{"type": "path", "url": "/app", "options": {"symlink": false}}'

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
    file_put_contents($path, getenv('TEST_SERVER').PHP_EOL, FILE_APPEND);
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
    TEST_SERVER=server-a php artisan schedule:run
    echo "== schedule:run on server-b, same minute"
    TEST_SERVER=server-b php artisan schedule:run
    ;;

*)
    echo "unknown framework: $framework" >&2
    exit 2
    ;;
esac

echo "== marker"
cat "$work/marker.log"

test "$(cat "$work/marker.log")" = server-a
echo "== OK: installed with composer require, ran once, skipped on the second server"
