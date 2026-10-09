<?php declare(strict_types=1);

namespace App\Command;

use Scheduling\Bridge\Crunz\CrunzEngine;
use Scheduling\Capability\Capability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:capabilities', description: 'Prints what the crunz engine does natively, what the package adds, and what is unavailable')]
final class CapabilitiesCommand extends Command
{
    public function __construct(private readonly CrunzEngine $engine)
    {
        parent::__construct();
    }

    /** {@inheritDoc} */
    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $report = $this->engine->capabilities();
        $table = new Table($output);
        $table->setHeaders(['capability', 'fulfilment']);
        foreach (Capability::cases() as $capability) {
            $table->addRow([$capability->value, $report->of($capability)->value]);
        }
        $table->render();

        return Command::SUCCESS;
    }
}
