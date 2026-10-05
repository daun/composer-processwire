<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use Composer\Composer;
use Composer\EventDispatcher\EventSubscriberInterface;
use Composer\Installer\PackageEvent;
use Composer\Installer\PackageEvents;
use Composer\DependencyResolver\Operation\InstallOperation;
use Composer\DependencyResolver\Operation\UpdateOperation;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use Composer\Plugin\Capable;
use Composer\Plugin\PluginInterface;
use Composer\Script\Event;
use Composer\Script\ScriptEvents;

final class Plugin implements PluginInterface, EventSubscriberInterface, Capable
{
    private ?ProcessWireManager $manager = null;
    private ?string $previousHtaccessHash = null;

    public function activate(Composer $composer, IOInterface $io): void
    {
        $this->manager = new ProcessWireManager($composer, $io);
    }

    public function deactivate(Composer $composer, IOInterface $io): void
    {
        $this->manager = null;
    }

    public function uninstall(Composer $composer, IOInterface $io): void
    {
        // The project's wire/ is intentionally left in place.
    }

    public static function getSubscribedEvents(): array
    {
        return [
            PackageEvents::PRE_PACKAGE_UPDATE => 'beforeUpdate',
            PackageEvents::POST_PACKAGE_INSTALL => 'afterInstall',
            PackageEvents::POST_PACKAGE_UPDATE => 'afterUpdate',
            ScriptEvents::PRE_AUTOLOAD_DUMP => 'beforeAutoloadDump',
            ScriptEvents::POST_INSTALL_CMD => 'afterCommand',
            ScriptEvents::POST_UPDATE_CMD => 'afterCommand',
        ];
    }

    public function getCapabilities(): array
    {
        return [\Composer\Plugin\Capability\CommandProvider::class => Commands::class];
    }

    public function beforeUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof UpdateOperation || $operation->getInitialPackage()->getName() !== 'processwire/processwire') {
            return;
        }
        $oldHtaccess = dirname($this->manager()->source($operation->getInitialPackage())) . '/htaccess.txt';
        $this->previousHtaccessHash = is_file($oldHtaccess) ? hash_file('sha256', $oldHtaccess) : null;
    }

    public function afterInstall(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if ($operation instanceof InstallOperation && $operation->getPackage()->getName() === 'processwire/processwire') {
            $this->manager()->sync($operation->getPackage());
        }
    }

    public function afterUpdate(PackageEvent $event): void
    {
        $operation = $event->getOperation();
        if (!$operation instanceof UpdateOperation || $operation->getTargetPackage()->getName() !== 'processwire/processwire') {
            return;
        }
        $package = $operation->getTargetPackage();
        $this->manager()->sync($package);
        $newHtaccess = dirname($this->manager()->source($package)) . '/htaccess.txt';
        $changed = $this->previousHtaccessHash !== null && is_file($newHtaccess)
            && $this->previousHtaccessHash !== hash_file('sha256', $newHtaccess);
        $this->manager()->warnAboutHtaccess($package, $changed);
    }

    public function beforeAutoloadDump(Event $event): void
    {
        $package = $this->manager()->installedPackage();
        if ($package === null) {
            return;
        }
        $autoload = $package->getAutoload();
        if (!isset($autoload['files'])) {
            return;
        }
        $autoload['files'] = array_values(array_filter($autoload['files'], static function ($file): bool {
            return ltrim(str_replace('\\', '/', $file), './') !== 'wire/core/ProcessWire.php';
        }));
        $package->setAutoload($autoload);
    }

    public function afterCommand(Event $event): void
    {
        $package = $this->manager()->installedPackage();
        if ($package !== null) {
            $this->manager()->sync($package);
        }
    }

    private function manager(): ProcessWireManager
    {
        if ($this->manager === null) {
            throw new \LogicException('ProcessWire plugin has not been activated');
        }
        return $this->manager;
    }
}
