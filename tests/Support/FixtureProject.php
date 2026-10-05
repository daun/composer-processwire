<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire\Tests\Support;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;
use Symfony\Component\Process\Process;

final class FixtureProject
{
    private string $directory;

    public function __construct()
    {
        $this->directory = sys_get_temp_dir() . '/composer-processwire-' . bin2hex(random_bytes(8));
        mkdir($this->directory, 0777, true);
        mkdir($this->directory . '/home');
        $this->createFixtures();
    }

    public function path(string $relative = ''): string
    {
        return $this->directory . ($relative === '' ? '' : '/' . $relative);
    }

    public function write(string $relative, string $contents): void
    {
        $file = $this->path($relative);
        if (!is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }
        file_put_contents($file, $contents);
    }

    /** @param array<string, mixed> $options */
    public function project(string $name, string $version = '1.0.0', array $options = []): string
    {
        $root = $this->path($name);
        $requires = [];
        if (!($options['noCore'] ?? false) && !($options['coreInDev'] ?? false)) {
            $requires['processwire/processwire'] = $version;
        }
        if (!($options['noPlugin'] ?? false)) {
            $requires['daun/composer-processwire'] = '1.0.0';
        }
        $config = [
            'name' => 'example/' . $name,
            'require' => $requires,
            'repositories' => [
                ['type' => 'path', 'url' => $this->path('fixtures/*'), 'options' => ['symlink' => false]],
                ['type' => 'path', 'url' => dirname(__DIR__, 2), 'options' => [
                    'symlink' => false,
                    'versions' => ['daun/composer-processwire' => '1.0.0'],
                ]],
                ['packagist.org' => false],
            ],
            'config' => ['allow-plugins' => ['daun/composer-processwire' => true]],
        ];
        if ($options['coreInDev'] ?? false) {
            $config['require-dev'] = ['processwire/processwire' => $version];
        }
        if (!($options['defaultWebroot'] ?? false)) {
            $config['extra'] = ['processwire' => ['webroot' => $options['webroot'] ?? 'public']];
        }
        $this->write($name . '/composer.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
        $webroot = ($options['defaultWebroot'] ?? false) ? $root : $root . '/public';
        if (!is_dir($webroot)) {
            mkdir($webroot, 0777, true);
        }
        file_put_contents($webroot . '/index.php', "<?php // project index\n");
        file_put_contents($webroot . '/.htaccess', "# custom rules\n");
        return $root;
    }

    public function updateVersion(string $root, string $version): void
    {
        $config = $this->config($root);
        $config['require']['processwire/processwire'] = $version;
        $this->writeConfig($root, $config);
    }

    /** @return array<string, mixed> */
    public function config(string $root): array
    {
        return json_decode(file_get_contents($root . '/composer.json'), true);
    }

    /** @param array<string, mixed> $config */
    public function writeConfig(string $root, array $config): void
    {
        file_put_contents($root . '/composer.json', json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
    }

    /** @param list<string> $arguments */
    /** @param array<string, string> $env */
    public function composer(string $root, array $arguments, int $expected = 0, array $env = []): string
    {
        $binary = getenv('COMPOSER_BIN') ?: 'composer';
        $process = new Process(array_merge([$binary, '--no-interaction'], $arguments), $root, array_merge([
            'COMPOSER_HOME' => $this->path('home'),
            'COMPOSER_DISABLE_NETWORK' => '1',
            'COMPOSER_NO_INTERACTION' => '1',
            'COMPOSER_ROOT_VERSION' => '1.0.0',
        ], $env));
        $process->setTimeout(120);
        $process->run();
        $output = $process->getOutput() . $process->getErrorOutput();
        if ($process->getExitCode() !== $expected) {
            throw new RuntimeException(implode(' ', $arguments) . " exited " . $process->getExitCode() . " (expected $expected):\n" . $output);
        }
        return $output;
    }

    public function autoloadContainsCore(string $root): bool
    {
        $file = $root . '/vendor/composer/autoload_files.php';
        return is_file($file) && strpos(file_get_contents($file), 'ProcessWire.php') !== false;
    }

    public function loadedCore(string $root): string
    {
        $script = <<<'PHP'
require 'vendor/autoload.php';
require 'public/wire/core/ProcessWire.php';
echo (new ReflectionClass('ProcessWire\Wire'))->getFileName();
PHP;
        $process = new Process([PHP_BINARY, '-r', $script], $root);
        $process->run();
        if (!$process->isSuccessful()) {
            throw new RuntimeException('Boot failed: ' . $process->getErrorOutput());
        }
        return realpath(trim($process->getOutput())) ?: trim($process->getOutput());
    }

    public function delete(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            unlink($path);
            return;
        }
        if (!is_dir($path)) {
            return;
        }
        $children = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($children as $child) {
            $child->isDir() && !$child->isLink() ? rmdir($child->getPathname()) : unlink($child->getPathname());
        }
        rmdir($path);
    }

    public function cleanUp(): void
    {
        $this->delete($this->directory);
    }

    private function createFixtures(): void
    {
        for ($version = 1; $version <= 3; $version++) {
            $path = 'fixtures/v' . $version;
            $this->write($path . '/composer.json', json_encode([
                'name' => 'processwire/processwire',
                'version' => $version . '.0.0',
                'type' => 'library',
                'autoload' => ['files' => ['wire/core/ProcessWire.php', 'extra.php']],
            ]));
            $this->write($path . '/wire/core/ProcessWire.php', '<?php namespace ProcessWire; class ProcessWire { const versionMajor = 3; const versionMinor = 0; const versionRevision = ' . $version . '; } class Wire {}' . "\n");
            $this->write($path . '/extra.php', "<?php // another autoload.files entry\n");
            $this->write($path . '/wire/old-or-new.txt', "version $version\n");
            if ($version === 1) {
                $this->write($path . '/wire/obsolete.txt', "obsolete\n");
            }
            $this->write($path . '/index.php', '<?php // upstream index v' . $version . "\n");
            $this->write($path . '/htaccess.txt', '# upstream htaccess v' . min($version, 2) . "\n");
        }
        $this->write('fixtures/dev/composer.json', json_encode([
            'name' => 'processwire/processwire',
            'version' => 'dev-dev',
            'autoload' => ['files' => ['wire/core/ProcessWire.php']],
        ]));
        $this->write('fixtures/dev/wire/core/ProcessWire.php', '<?php namespace ProcessWire; class ProcessWire { const versionMajor = 3; const versionMinor = 0; const versionRevision = 274; } class Wire {}' . "\n");
        $this->write('fixtures/dev/index.php', "<?php // dev index\n");
    }
}
