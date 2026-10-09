<?php declare(strict_types=1);

namespace Scheduling\Tests\Bridge\Laravel;

use Illuminate\Console\Command;
use InvalidArgumentException;
use RedisException;
use Scheduling\Tests\RedisConnection;

final class MarkerCommand extends Command
{
    protected $signature = 'scheduler-test:mark {path} {--text=ran} {--lock-key=}';

    protected $description = 'Appends a line to the marker file';

    /** @throws RedisException */
    public function handle(): int
    {
        $path = $this->argument('path');
        $line = $this->option('text');
        $key = $this->option('lock-key');
        if (!is_string($path) || !is_string($line) || (null !== $key && !is_string($key))) {
            throw new InvalidArgumentException('The marker command takes string arguments');
        }
        if (null !== $key) {
            $line .= ' locked='.RedisConnection::open()->exists($key);
        }
        file_put_contents($path, $line.PHP_EOL, FILE_APPEND);

        return self::SUCCESS;
    }
}
