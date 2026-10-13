#!/usr/bin/env bash
# Shows the package in a Laravel 13 app: two servers run the schedule in the same minute against one Redis.
# DEMO_SERVER names the simulated server in the lines the two commands write.
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
DEMO_SERVER=server-a php artisan schedule:run
echo "== schedule:run on server-b, same minute"
DEMO_SERVER=server-b php artisan schedule:run

echo "== storage/logs/hello.log (package task from scheduling.yaml)"
cat storage/logs/hello.log
echo "== storage/logs/legacy-report.log (the app's own entry in routes/console.php)"
cat storage/logs/legacy-report.log

echo "== Redis: the app entry claimed its minute under our mutex"
php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach ($redis->keys("scheduler:external:*") as $key) {
    echo $key, PHP_EOL;
}
'

test "$(wc -l < storage/logs/hello.log)" -eq 1
grep -q ' server-a$' storage/logs/hello.log
test "$(wc -l < storage/logs/legacy-report.log)" -eq 1
echo "== OK: each task ran once, on server-a; server-b skipped both"
