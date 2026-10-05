<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use Composer\Composer;
use Composer\Factory;
use Composer\IO\IOInterface;
use Composer\Package\PackageInterface;
use RuntimeException;

final class ProcessWireManager
{
    private Composer $composer;
    private IOInterface $io;

    public function __construct(Composer $composer, IOInterface $io)
    {
        $this->composer = $composer;
        $this->io = $io;
    }

    public function installedPackage(): ?PackageInterface
    {
        foreach ($this->composer->getRepositoryManager()->getLocalRepository()->getCanonicalPackages() as $package) {
            if ($package->getName() === 'processwire/processwire') {
                return $package;
            }
        }
        return null;
    }

    public function webroot(): string
    {
        $extra = $this->composer->getPackage()->getExtra();
        $path = $extra['processwire']['webroot'] ?? '.';
        if (!is_string($path) || $path === '' || preg_match('~(^[/\\\\]|^[A-Za-z]:|(^|[/\\\\])\.\.([/\\\\]|$))~', $path)) {
            throw new RuntimeException('extra.processwire.webroot must be a relative path inside the project');
        }
        $root = realpath(dirname(Factory::getComposerFile()));
        if ($root === false) {
            throw new RuntimeException('Cannot find the project root');
        }
        $webroot = realpath($root . '/' . $path);
        if ($webroot === false || ($webroot !== $root && strpos($webroot, $root . DIRECTORY_SEPARATOR) !== 0)) {
            throw new RuntimeException('ProcessWire webroot must exist inside the project: ' . $path);
        }
        return $webroot;
    }

    public function source(PackageInterface $package): string
    {
        $path = $this->composer->getInstallationManager()->getInstallPath($package);
        if ($path === null) {
            throw new RuntimeException('ProcessWire package has no installation path');
        }
        return rtrim($path, '/\\') . '/wire';
    }

    public function target(): string
    {
        return $this->webroot() . '/wire';
    }

    /** @return array{missing: list<string>, changed: list<string>, extra: list<string>} */
    public function compare(PackageInterface $package): array
    {
        return (new CoreSynchronizer())->compare($this->source($package), $this->target());
    }

    public function indexMatches(PackageInterface $package): bool
    {
        $source = dirname($this->source($package)) . '/index.php';
        $target = $this->webroot() . '/index.php';
        return is_file($source) && !is_link($source) && is_file($target) && !is_link($target)
            && hash_file('sha256', $source) === hash_file('sha256', $target);
    }

    /** @param array{missing: list<string>, changed: list<string>, extra: list<string>} $drift */
    public static function hasDrift(array $drift): bool
    {
        return $drift['missing'] !== [] || $drift['changed'] !== [] || $drift['extra'] !== [];
    }

    public function sync(PackageInterface $package, bool $force = false): bool
    {
        if (!isset($this->composer->getPackage()->getRequires()['processwire/processwire'])) {
            throw new RuntimeException('processwire/processwire must be in root require (not require-dev) for production installs');
        }
        $source = $this->source($package);
        $target = $this->target();
        $synchronizer = new CoreSynchronizer();
        $drift = $synchronizer->compare($source, $target);
        if (!$force && !self::hasDrift($drift) && $this->indexMatches($package)) {
            return false;
        }
        if (is_dir($target) && self::hasDrift($drift)) {
            $this->io->writeError('<warning>Existing wire/ differs from the installed package; local changes will be replaced.</warning>');
        }
        $synchronizer->sync($source, $target);
        $this->io->writeError('<info>Synced ProcessWire ' . $package->getPrettyVersion() . ' to ' . $target . ' and ' . $this->webroot() . '/index.php</info>');
        return true;
    }

    public function warnAboutHtaccess(PackageInterface $package, bool $htaccessChanged): void
    {
        if ($htaccessChanged) {
            $source = dirname($this->source($package)) . '/htaccess.txt';
            $project = $this->webroot() . '/.htaccess';
            $this->io->writeError('<warning>Upstream htaccess.txt changed; review rules for your .htaccess: diff -u ' . escapeshellarg($project) . ' ' . escapeshellarg($source) . '</warning>');
        }
    }
}
