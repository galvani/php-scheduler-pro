<?php declare(strict_types=1);

namespace App\Command;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\DependencyInjection\Attribute\Autowire;

#[AsCommand(name: 'app:hello', description: 'Appends a timestamped line with the host name to var/hello.log')]
final class HelloCommand extends Command
{
    public function __construct(
        #[Autowire('%kernel.project_dir%/var/hello.log')]
        private readonly string $log,
        #[Autowire('%env(default:default_host:SCHEDULING_HOST)%')]
        private readonly string $host,
    ) {
        parent::__construct();
    }

    /**
     * Executes the current command.
     *
     * Appends one line, the current time and the host name, to the log file; the demo reads that file to see which
     * server ran the task.
     *
     * @return int 0 if everything went fine, or an exit code: 1 when the line could not be written
     */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $written = file_put_contents($this->log, date(DATE_ATOM)." {$this->host}\n", FILE_APPEND | LOCK_EX);

        return false === $written ? Command::FAILURE : Command::SUCCESS;
    }
}
