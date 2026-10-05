<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire\Tests\Unit;

use Composer\Composer;
use Composer\IO\NullIO;
use Composer\Package\Package;
use Composer\Repository\InstalledRepositoryInterface;
use Composer\Repository\RepositoryManager;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;
use Daun\ComposerProcessWire\Commands;
use Daun\ComposerProcessWire\Plugin;
use PHPUnit\Framework\TestCase;

final class PluginTest extends TestCase
{
    public function testAutoloadListenerRemovesOnlyVendorCoreFile(): void
    {
        $core = new Package('processwire/processwire', '1.0.0.0', '1.0.0');
        $core->setAutoload([
            'files' => ['wire/core/ProcessWire.php', 'extra.php'],
            'psr-4' => ['Example\\' => 'src/'],
        ]);
        $other = new Package('example/other', '1.0.0.0', '1.0.0');
        $other->setAutoload(['files' => ['wire/core/ProcessWire.php']]);

        $installed = $this->createStub(InstalledRepositoryInterface::class);
        $installed->method('getCanonicalPackages')->willReturn([$other, $core]);
        $repositories = $this->createStub(RepositoryManager::class);
        $repositories->method('getLocalRepository')->willReturn($installed);
        $composer = new Composer();
        $composer->setRepositoryManager($repositories);
        $io = new NullIO();
        $plugin = new Plugin();
        $plugin->activate($composer, $io);
        $plugin->beforeAutoloadDump(new Event(ScriptEvents::PRE_AUTOLOAD_DUMP, $composer, $io));

        self::assertSame(['files' => ['extra.php'], 'psr-4' => ['Example\\' => 'src/']], $core->getAutoload());
        self::assertSame(['files' => ['wire/core/ProcessWire.php']], $other->getAutoload());
        self::assertSame(Commands::class, $plugin->getCapabilities()[\Composer\Plugin\Capability\CommandProvider::class]);
    }
}
