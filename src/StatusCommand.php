<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use Composer\Command\BaseCommand;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

final class StatusCommand extends BaseCommand
{
    private ProcessWireManager $manager;

    public function __construct(ProcessWireManager $manager)
    {
        $this->manager = $manager;
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setName('processwire:status')->setDescription('Compare webroot/wire with the locked ProcessWire package');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = $this->manager->installedPackage();
        if ($package === null) {
            $output->writeln('<error>processwire/processwire is not installed.</error>');
            return 1;
        }
        $reference = $package->getSourceReference() ?: $package->getDistReference();
        $output->writeln('Locked: ' . $package->getPrettyVersion() . ($reference ? ' (' . substr($reference, 0, 12) . ')' : ''));
        $drift = $this->manager->compare($package);
        $indexMatches = $this->manager->indexMatches($package);
        $output->writeln($indexMatches ? '<info>index.php: matches locked version</info>' : '<warning>index.php: differs from locked version</warning>');
        if (!ProcessWireManager::hasDrift($drift)) {
            $output->writeln('<info>wire/: matches locked version</info>');
            return $indexMatches ? 0 : 1;
        }
        $output->writeln('<warning>wire/: differs from locked version</warning>');
        $coreFile = $this->manager->target() . '/core/ProcessWire.php';
        if (is_file($coreFile)) {
            $core = file_get_contents($coreFile);
            if ($core !== false && preg_match_all('/\b(?:const|static\s+\$)\s*version(Major|Minor|Revision)\s*=\s*[\'\"]?(\d+)/', $core, $matches, PREG_SET_ORDER)) {
                $version = [];
                foreach ($matches as $match) {
                    $version[$match[1]] = $match[2];
                }
                if (isset($version['Major'], $version['Minor'], $version['Revision'])) {
                    $output->writeln('wire/ reports: ' . implode('.', [$version['Major'], $version['Minor'], $version['Revision']]) . ' (not a commit identifier)');
                }
            }
        }
        foreach ($drift as $kind => $paths) {
            $output->writeln(sprintf('  %s: %d', $kind, count($paths)));
            foreach (array_slice($paths, 0, 10) as $path) {
                $output->writeln('    ' . $path);
            }
            if (count($paths) > 10) {
                $output->writeln('    ...');
            }
        }
        return 1;
    }
}
