<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use Composer\Composer;
use Composer\IO\IOInterface;
use Composer\Plugin\Capability\CommandProvider;

final class Commands implements CommandProvider
{
    private Composer $composer;
    private IOInterface $io;

    /** @param array{composer: Composer, io: IOInterface} $args */
    public function __construct(array $args)
    {
        $this->composer = $args['composer'];
        $this->io = $args['io'];
    }

    public function getCommands(): array
    {
        $manager = new ProcessWireManager($this->composer, $this->io);
        return [new SyncCommand($manager), new StatusCommand($manager)];
    }
}
