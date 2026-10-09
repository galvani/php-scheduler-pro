#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")"
export REDIS_HOST="${REDIS_HOST:-127.0.0.1}"
export REDIS_PORT="${REDIS_PORT:-16379}"

php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach ($redis->keys("scheduler:*hello*") as $key) {
    $redis->del($key);
}
'
mkdir -p var
rm -f var/hello.log

echo "== app:capabilities"
php bin/console app:capabilities

seconds=$((10#$(date +%S)))
if ((seconds > 50)); then
    sleep $((61 - seconds))
fi

echo "== schedule:run on server-a"
SCHEDULING_HOST=server-a vendor/bin/crunz schedule:run
echo "== schedule:run on server-b, same minute"
SCHEDULING_HOST=server-b vendor/bin/crunz schedule:run

echo "== var/hello.log"
cat var/hello.log

echo "== scheduler:runs:hello, oldest first"
php -r '
$redis = new Redis();
$redis->connect(getenv("REDIS_HOST"), (int) getenv("REDIS_PORT"));
foreach (array_reverse($redis->lRange("scheduler:runs:hello", 0, -1)) as $record) {
    $run = json_decode($record, true, flags: JSON_THROW_ON_ERROR);
    printf("%-9s %-8s %s\n", $run["state"], $run["host"], $run["detail"] ?? "");
}
'
