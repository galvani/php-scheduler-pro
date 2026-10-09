#!/usr/bin/env bash
# Shows the package in a Laravel 13 app: two servers run the schedule in the same minute against one Redis.
set -euo pipefail
cd "$(dirname "$0")"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-16379}"

if [ ! -f .env ]; then
    cp .env.example .env
    php artisan key:generate --quiet
fi

php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach ([...$redis->keys("scheduler:*hello*"), ...$redis->keys("scheduler:external:*")] as $key) {
    $redis->del($key);
}
'
rm -f storage/logs/hello.log storage/logs/legacy-report.log

echo "== schedule:list"
php artisan schedule:list

seconds=$((10#$(date +%S)))
if ((seconds > 45)); then
    sleep $((61 - seconds))
fi

echo "== schedule:run on server-a"
SCHEDULING_HOST=server-a php artisan schedule:run
echo "== schedule:run on server-b, same minute"
SCHEDULING_HOST=server-b php artisan schedule:run

echo "== storage/logs/hello.log (package task from scheduling.yaml)"
cat storage/logs/hello.log
echo "== storage/logs/legacy-report.log (the app's own entry in routes/console.php)"
cat storage/logs/legacy-report.log

echo "== Redis: run record of hello, oldest first"
php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach (array_reverse($redis->lRange("scheduler:runs:hello", 0, -1)) as $record) {
    $run = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
    printf("%-9s %-8s %s\n", $run["state"], $run["host"], $run["detail"] ?? "");
}
'
echo "== Redis: the app entry claimed its minute under our mutex"
php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach ($redis->keys("scheduler:external:*") as $key) {
    echo $key, PHP_EOL;
}
'

echo "== scheduling:export (the app's own entries as YAML for scheduling.yaml)"
php artisan scheduling:export
