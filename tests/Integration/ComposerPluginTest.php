<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire\Tests\Integration;

use Daun\ComposerProcessWire\Tests\Support\FixtureProject;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ComposerPluginTest extends TestCase
{
    private FixtureProject $fixture;

    protected function setUp(): void
    {
        $this->fixture = new FixtureProject();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
    }

    public function testFreshInstallCopiesCoreAndIndexWithoutChangingVendorOrHtaccess(): void
    {
        $root = $this->fixture->project('install');
        $this->fixture->composer($root, ['install']);

        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
        self::assertSame("# custom rules\n", file_get_contents($root . '/public/.htaccess'));
        self::assertSame("version 1\n", file_get_contents($root . '/public/wire/old-or-new.txt'));
        self::assertFileExists($root . '/vendor/processwire/processwire/wire/core/ProcessWire.php');
        self::assertFalse(is_link($root . '/vendor/processwire/processwire'));
        self::assertFalse($this->fixture->autoloadContainsCore($root));
        self::assertStringContainsString('extra.php', file_get_contents($root . '/vendor/composer/autoload_files.php'));
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
        self::assertStringContainsString('wire/: matches locked version', $this->fixture->composer($root, ['processwire:status']));
        self::assertFileDoesNotExist($root . '/public/install.php');
    }

    public function testUpdateRemovesStaleFilesAndWarnsOnlyWhenUpstreamHtaccessChanged(): void
    {
        $root = $this->fixture->project('update');
        $this->fixture->composer($root, ['install']);
        $this->fixture->write('update/public/wire/extra.txt', 'local');
        $this->fixture->write('update/public/wire/obsolete.txt', 'edited');
        $this->fixture->updateVersion($root, '2.0.0');
        $this->fixture->write('update/public/.htaccess', "# upstream htaccess v2\n");

        $output = $this->fixture->composer($root, ['update', 'processwire/processwire']);
        self::assertStringContainsString('Upstream htaccess.txt changed', $output);
        self::assertStringContainsString('local changes will be replaced', $output);
        self::assertFileDoesNotExist($root . '/public/wire/extra.txt');
        self::assertFileDoesNotExist($root . '/public/wire/obsolete.txt');
        self::assertSame("version 2\n", file_get_contents($root . '/public/wire/old-or-new.txt'));
        self::assertSame("<?php // upstream index v2\n", file_get_contents($root . '/public/index.php'));
        self::assertSame("# upstream htaccess v2\n", file_get_contents($root . '/public/.htaccess'));
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
        self::assertFalse($this->fixture->autoloadContainsCore($root));

        $this->fixture->updateVersion($root, '3.0.0');
        $this->fixture->write('update/public/.htaccess', "# entirely custom\n");
        $output = $this->fixture->composer($root, ['update', 'processwire/processwire']);
        self::assertStringNotContainsString('Upstream htaccess.txt changed', $output);
        self::assertSame("# entirely custom\n", file_get_contents($root . '/public/.htaccess'));
        self::assertSame("<?php // upstream index v3\n", file_get_contents($root . '/public/index.php'));
    }

    public function testReinstallRepairsMissingAndModifiedFilesWithoutReinstallingPackages(): void
    {
        $root = $this->fixture->project('repair');
        $this->fixture->composer($root, ['install']);
        $this->fixture->delete($root . '/public/wire');
        $this->fixture->write('repair/public/index.php', 'edited index');
        $this->fixture->composer($root, ['install']);
        self::assertSame("version 1\n", file_get_contents($root . '/public/wire/old-or-new.txt'));
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
        $this->fixture->write('repair/public/index.php', 'index drift only');
        $indexStatus = $this->fixture->composer($root, ['processwire:status'], 1);
        self::assertStringContainsString('index.php: differs', $indexStatus);
        self::assertStringContainsString('wire/: matches', $indexStatus);

        $this->fixture->write('repair/public/wire/old-or-new.txt', 'edited core');
        $this->fixture->write('repair/public/wire/local.txt', 'extra');
        $this->fixture->write('repair/public/index.php', 'edited again');
        $status = $this->fixture->composer($root, ['processwire:status'], 1);
        self::assertStringContainsString('index.php: differs', $status);
        self::assertStringContainsString('wire/: differs', $status);
        self::assertStringContainsString('extra: 1', $status);
        $this->fixture->composer($root, ['processwire:sync']);
        self::assertFileDoesNotExist($root . '/public/wire/local.txt');
        self::assertStringContainsString('wire/: matches', $this->fixture->composer($root, ['processwire:status']));
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
    }

    public function testNoScriptsStillRunsPluginListeners(): void
    {
        $root = $this->fixture->project('no-scripts');
        $this->fixture->composer($root, ['install', '--no-scripts']);
        self::assertFileExists($root . '/public/wire/core/ProcessWire.php');
        self::assertFalse($this->fixture->autoloadContainsCore($root));
        $this->fixture->delete($root . '/public/wire');
        $this->fixture->composer($root, ['install', '--no-scripts']);
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
        $this->fixture->composer($root, ['dump-autoload', '--no-scripts']);
        self::assertFalse($this->fixture->autoloadContainsCore($root));
    }

    public function testPluginInstalledAfterCoreRepairsAutoloadAndUninstallLeavesCore(): void
    {
        $root = $this->fixture->project('late-plugin', '1.0.0', ['noPlugin' => true]);
        $this->fixture->composer($root, ['install']);
        self::assertTrue($this->fixture->autoloadContainsCore($root));
        self::assertFileDoesNotExist($root . '/public/wire/core/ProcessWire.php');

        $config = $this->fixture->config($root);
        $config['require']['daun/composer-processwire'] = '1.0.0';
        $this->fixture->writeConfig($root, $config);
        $this->fixture->composer($root, ['update', 'daun/composer-processwire']);
        self::assertFalse($this->fixture->autoloadContainsCore($root));
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));

        unset($config['require']['daun/composer-processwire']);
        $this->fixture->writeConfig($root, $config);
        $this->fixture->composer($root, ['update', 'daun/composer-processwire']);
        self::assertFileExists($root . '/public/wire/core/ProcessWire.php');
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
        self::assertTrue($this->fixture->autoloadContainsCore($root));
    }

    public function testDisablingPluginsBreaksAutoloadUntilReenabled(): void
    {
        $root = $this->fixture->project('no-plugins');
        $this->fixture->composer($root, ['install']);
        $this->fixture->composer($root, ['dump-autoload', '--no-plugins']);
        self::assertTrue($this->fixture->autoloadContainsCore($root));
        $this->fixture->composer($root, ['dump-autoload']);
        self::assertFalse($this->fixture->autoloadContainsCore($root));
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
    }

    public function testDefaultWebrootAndDevCommitAlias(): void
    {
        $root = $this->fixture->project('default-root', '1.0.0', ['defaultWebroot' => true]);
        $this->fixture->composer($root, ['install']);
        self::assertFileExists($root . '/wire/core/ProcessWire.php');
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/index.php'));

        $dev = $this->fixture->project('dev-pin', 'dev-dev#8ae804a2c6fd25611e4854adbfcd5d896243cf4b as 3.0.274');
        $this->fixture->composer($dev, ['install']);
        self::assertFalse($this->fixture->autoloadContainsCore($dev));
        self::assertSame(realpath($dev . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($dev));
        self::assertStringContainsString('matches locked version', $this->fixture->composer($dev, ['processwire:status']));
    }

    public function testInstallWithoutDevDependenciesStillIncludesCore(): void
    {
        $root = $this->fixture->project('production');
        $this->fixture->composer($root, ['install', '--no-dev']);
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
    }

    public function testCustomComposerFilenameResolvesProjectRoot(): void
    {
        $root = $this->fixture->project('custom-composer-file');
        rename($root . '/composer.json', $root . '/project.composer.json');
        $this->fixture->composer($root, ['install'], 0, ['COMPOSER' => 'project.composer.json']);
        self::assertFileExists($root . '/project.composer.lock');
        self::assertSame(realpath($root . '/public/wire/core/ProcessWire.php'), $this->fixture->loadedCore($root));
        self::assertSame("<?php // upstream index v1\n", file_get_contents($root . '/public/index.php'));
    }

    public function testSymlinkedWebrootOutsideProjectIsRejected(): void
    {
        $root = $this->fixture->project('linked-webroot');
        $this->fixture->delete($root . '/public');
        $this->fixture->write('outside/index.php', 'safe');
        if (!@symlink($this->fixture->path('outside'), $root . '/public')) {
            self::markTestSkipped('This platform does not permit symlinks');
        }
        $output = $this->fixture->composer($root, ['install'], 1);
        self::assertStringContainsString('must exist inside the project', $output);
        self::assertSame('safe', file_get_contents($this->fixture->path('outside/index.php')));
        self::assertFileDoesNotExist($this->fixture->path('outside/wire'));
    }

    public function testSymlinkedTargetsCannotOverwriteFilesOutsideWebroot(): void
    {
        $root = $this->fixture->project('linked-targets');
        $this->fixture->composer($root, ['install']);
        $this->fixture->write('outside/keep.txt', 'safe');
        $this->fixture->delete($root . '/public/wire');
        if (!@symlink($this->fixture->path('outside'), $root . '/public/wire')) {
            self::markTestSkipped('This platform does not permit symlinks');
        }
        self::assertStringContainsString('Refusing to replace', $this->fixture->composer($root, ['processwire:sync'], 1));
        self::assertSame('safe', file_get_contents($this->fixture->path('outside/keep.txt')));
        $this->fixture->delete($root . '/public/wire');

        $this->fixture->write('outside/index.php', 'safe index');
        $this->fixture->delete($root . '/public/index.php');
        if (!@symlink($this->fixture->path('outside/index.php'), $root . '/public/index.php')) {
            self::markTestSkipped('This platform does not permit symlinks');
        }
        self::assertStringContainsString('Refusing to replace', $this->fixture->composer($root, ['processwire:sync'], 1));
        self::assertSame('safe index', file_get_contents($this->fixture->path('outside/index.php')));
        self::assertFileDoesNotExist($root . '/public/wire');
    }

    public function testMissingCoreLeavesPluginInert(): void
    {
        $root = $this->fixture->project('no-core', '1.0.0', ['noCore' => true]);
        $this->fixture->composer($root, ['install']);
        self::assertFileDoesNotExist($root . '/public/wire');
        self::assertStringContainsString('is not installed', $this->fixture->composer($root, ['processwire:status'], 1));
    }

    public function testRequireDevCoreIsRejected(): void
    {
        $root = $this->fixture->project('dev-only', '1.0.0', ['coreInDev' => true]);
        $output = $this->fixture->composer($root, ['install'], 1);
        self::assertStringContainsString('must be in root require', $output);
        self::assertFileDoesNotExist($root . '/public/wire');
    }

    #[DataProvider('invalidWebroots')]
    public function testInvalidWebrootCannotWriteOutsideProject(string $webroot): void
    {
        $root = $this->fixture->project('invalid-root', '1.0.0', ['webroot' => $webroot]);
        $output = $this->fixture->composer($root, ['install'], 1);
        self::assertStringContainsString('webroot', strtolower($output));
        self::assertFileDoesNotExist($root . '/public/wire');
    }

    /** @return array<string, array{string}> */
    public static function invalidWebroots(): array
    {
        return [
            'parent traversal' => ['../escape'],
            'absolute path' => ['/tmp'],
            'nonexistent webroot' => ['missing'],
        ];
    }
}
