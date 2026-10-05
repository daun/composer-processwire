<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire;

use FilesystemIterator;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use RuntimeException;

final class CoreSynchronizer
{
    /** @return array{missing: list<string>, changed: list<string>, extra: list<string>} */
    public function compare(string $source, string $target): array
    {
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException("ProcessWire core source is missing or is a symlink: $source");
        }

        $sourceFiles = $this->entries($source);
        $targetFiles = is_dir($target) && !is_link($target) ? $this->entries($target) : [];
        $missing = $changed = $extra = [];
        foreach ($sourceFiles as $path => $type) {
            if (!isset($targetFiles[$path])) {
                $missing[] = $path;
            } elseif ($targetFiles[$path] !== $type || ($type === 'file' && hash_file('sha256', $source . '/' . $path) !== hash_file('sha256', $target . '/' . $path))) {
                $changed[] = $path;
            }
        }
        foreach ($targetFiles as $path => $type) {
            if (!isset($sourceFiles[$path])) {
                $extra[] = $path;
            }
        }

        return compact('missing', 'changed', 'extra');
    }

    public function sync(string $source, string $target): void
    {
        if (!is_dir($source) || is_link($source)) {
            throw new RuntimeException("ProcessWire core source is missing or is a symlink: $source");
        }
        if ((file_exists($target) || is_link($target)) && (!is_dir($target) || is_link($target))) {
            throw new RuntimeException("Refusing to replace non-directory or symlink: $target");
        }
        $sourceIndex = dirname($source) . '/index.php';
        $targetIndex = dirname($target) . '/index.php';
        if (!is_file($sourceIndex) || is_link($sourceIndex)) {
            throw new RuntimeException("ProcessWire index.php source is missing or is a symlink: $sourceIndex");
        }
        if ((file_exists($targetIndex) || is_link($targetIndex)) && (!is_file($targetIndex) || is_link($targetIndex))) {
            throw new RuntimeException("Refusing to replace non-file or symlink: $targetIndex");
        }

        $parent = dirname($target);
        if (!is_dir($parent)) {
            throw new RuntimeException("Webroot does not exist: $parent");
        }
        $token = bin2hex(random_bytes(8));
        $stage = $parent . '/.wire-stage-' . $token;
        $backup = $parent . '/.wire-backup-' . $token;
        $stageIndex = $parent . '/.index-stage-' . $token;
        $backupIndex = $parent . '/.index-backup-' . $token;
        if (!mkdir($stage)) {
            throw new RuntimeException("Cannot create staging directory: $stage");
        }

        try {
            foreach ($this->entries($source) as $path => $type) {
                $destination = $stage . '/' . $path;
                if ($type === 'dir') {
                    if (!mkdir($destination) && !is_dir($destination)) {
                        throw new RuntimeException("Cannot create directory: $destination");
                    }
                } elseif (!copy($source . '/' . $path, $destination)) {
                    throw new RuntimeException("Cannot copy core file: $path");
                }
            }
            if (!copy($sourceIndex, $stageIndex)) {
                throw new RuntimeException("Cannot stage ProcessWire index.php: $stageIndex");
            }
            $coreBackedUp = $indexBackedUp = $coreInstalled = $indexInstalled = false;
            try {
                if (is_dir($target)) {
                    if (!rename($target, $backup)) {
                        throw new RuntimeException("Cannot move old core aside: $target");
                    }
                    $coreBackedUp = true;
                }
                if (is_file($targetIndex)) {
                    if (!rename($targetIndex, $backupIndex)) {
                        throw new RuntimeException("Cannot move old index.php aside: $targetIndex");
                    }
                    $indexBackedUp = true;
                }
                if (!rename($stage, $target)) {
                    throw new RuntimeException("Cannot install new core: $target");
                }
                $coreInstalled = true;
                if (!rename($stageIndex, $targetIndex)) {
                    throw new RuntimeException("Cannot install new index.php: $targetIndex");
                }
                $indexInstalled = true;
            } catch (\Throwable $error) {
                if ($indexInstalled && !unlink($targetIndex)) {
                    throw new RuntimeException("Cannot roll back index.php; old file remains at $backupIndex", 0, $error);
                }
                if ($coreInstalled) {
                    $this->removeDirectory($target);
                }
                if ($indexBackedUp && !rename($backupIndex, $targetIndex)) {
                    throw new RuntimeException("Cannot restore index.php; old file remains at $backupIndex", 0, $error);
                }
                if ($coreBackedUp && !rename($backup, $target)) {
                    throw new RuntimeException("Cannot restore wire/; old core remains at $backup", 0, $error);
                }
                throw $error;
            }
            if ($coreBackedUp) {
                $this->removeDirectory($backup);
            }
            if ($indexBackedUp && !unlink($backupIndex)) {
                throw new RuntimeException("Cannot remove old index.php backup: $backupIndex");
            }
        } finally {
            if (is_dir($stage)) {
                $this->removeDirectory($stage);
            }
            if (is_file($stageIndex)) {
                unlink($stageIndex);
            }
        }
    }

    /** @return array<string, string> */
    private function entries(string $root): array
    {
        $entries = [];
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::SELF_FIRST
        );
        foreach ($iterator as $item) {
            $path = substr($item->getPathname(), strlen($root) + 1);
            if ($item->isLink()) {
                throw new RuntimeException("Refusing symlink in ProcessWire core: $path");
            }
            if (!$item->isDir() && !$item->isFile()) {
                throw new RuntimeException("Unsupported entry in ProcessWire core: $path");
            }
            $entries[$path] = $item->isDir() ? 'dir' : 'file';
        }
        return $entries;
    }

    private function removeDirectory(string $root): void
    {
        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST
        );
        foreach ($iterator as $item) {
            if ($item->isDir() && !$item->isLink()) {
                if (!rmdir($item->getPathname())) {
                    throw new RuntimeException('Cannot remove directory: ' . $item->getPathname());
                }
            } elseif (!unlink($item->getPathname())) {
                throw new RuntimeException('Cannot remove file: ' . $item->getPathname());
            }
        }
        if (!rmdir($root)) {
            throw new RuntimeException("Cannot remove directory: $root");
        }
    }
}
