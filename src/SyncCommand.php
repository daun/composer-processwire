<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class SyncCommand extends BaseCommand
{
    private ProcessWireManager $manager;

    public function __construct(ProcessWireManager $manager)
    {
        $this->manager = $manager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('processwire:sync')->setDescription('Replace webroot/wire with the locked ProcessWire core');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $this->manager->installedPackage();
        if ($package === null) {
            $output->writeln('<error>processwire/processwire is not installed.</error>');
            return 1;
        }
        $this->manager->sync($package, true);
        return 0;
    }
}
