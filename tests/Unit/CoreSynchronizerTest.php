<?php

declare(strict_types=1);

namespace Daun\ComposerProcessWire\Tests\Unit;

use Daun\ComposerProcessWire\CoreSynchronizer;
use Daun\ComposerProcessWire\Tests\Support\FixtureProject;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class CoreSynchronizerTest extends TestCase
{
    private FixtureProject $fixture;
    private CoreSynchronizer $sync;

    protected function setUp(): void
    {
        $this->fixture = new FixtureProject();
        $this->sync = new CoreSynchronizer();
    }

    protected function tearDown(): void
    {
        $this->fixture->cleanUp();
    }

    public function testCompareClassifiesMissingChangedAndExtraEntries(): void
    {
        $source = $this->fixture->path('fixtures/v1/wire');
        $target = $this->fixture->path('target/wire');
        $this->fixture->write('target/wire/core/ProcessWire.php', "modified\n");
        $this->fixture->write('target/wire/local/extra.txt', "extra\n");

        $diff = $this->sync->compare($source, $target);
        self::assertContains('core/ProcessWire.php', $diff['changed']);
        self::assertContains('obsolete.txt', $diff['missing']);
        self::assertContains('local', $diff['extra']);
        self::assertContains('local/extra.txt', $diff['extra']);
    }

    public function testSyncReplacesCoreAndIndexAndPreservesHtaccess(): void
    {
        $source = $this->fixture->path('fixtures/v2/wire');
        $target = $this->fixture->path('site/wire');
        $this->fixture->write('site/wire/obsolete.txt', 'old');
        $this->fixture->write('site/index.php', 'custom');
        $this->fixture->write('site/.htaccess', 'keep');
        $this->sync->sync($source, $target);

        self::assertSame("version 2\n", file_get_contents($target . '/old-or-new.txt'));
        self::assertFileDoesNotExist($target . '/obsolete.txt');
        self::assertSame("<?php // upstream index v2\n", file_get_contents($this->fixture->path('site/index.php')));
        self::assertSame('keep', file_get_contents($this->fixture->path('site/.htaccess')));
        self::assertSame(['missing' => [], 'changed' => [], 'extra' => []], $this->sync->compare($source, $target));
        self::assertSame([], glob($this->fixture->path('site/.wire-*')));
        self::assertSame([], glob($this->fixture->path('site/.index-*')));
    }

    public function testEmptyDirectoriesCountAsDriftAndAreMirrored(): void
    {
        $source = $this->fixture->path('fixtures/v1/wire');
        $target = $this->fixture->path('site/wire');
        mkdir($source . '/empty');
        $this->fixture->write('site/index.php', 'old');
        self::assertContains('empty', $this->sync->compare($source, $target)['missing']);
        $this->sync->sync($source, $target);
        self::assertDirectoryExists($target . '/empty');
        mkdir($target . '/local-empty');
        self::assertContains('local-empty', $this->sync->compare($source, $target)['extra']);
        $this->sync->sync($source, $target);
        self::assertDirectoryDoesNotExist($target . '/local-empty');
    }

    public function testBrokenSourceDoesNotTouchExistingSiteOrLeaveStaging(): void
    {
        $source = $this->fixture->path('fixtures/v1/wire');
        $target = $this->fixture->path('site/wire');
        $this->fixture->write('site/wire/current.txt', 'original');
        $this->fixture->write('site/index.php', 'original index');
        if (!@symlink($this->fixture->path('fixtures/v1/index.php'), $source . '/invalid-link')) {
            self::markTestSkipped('This platform does not permit symlinks');
        }

        try {
            $this->sync->sync($source, $target);
            self::fail('A symlink inside the source must abort the sync');
        } catch (RuntimeException $error) {
            self::assertStringContainsString('symlink', $error->getMessage());
        }
        self::assertSame('original', file_get_contents($target . '/current.txt'));
        self::assertSame('original index', file_get_contents($this->fixture->path('site/index.php')));
        self::assertSame([], glob($this->fixture->path('site/.wire-*')));
        self::assertSame([], glob($this->fixture->path('site/.index-*')));
    }

    public function testRejectsSymlinkedTargetWithoutFollowingIt(): void
    {
        $real = $this->fixture->path('elsewhere/wire');
        $this->fixture->write('elsewhere/wire/keep.txt', 'safe');
        $this->fixture->write('site/index.php', 'original');
        if (!@symlink($real, $this->fixture->path('site/wire'))) {
            self::markTestSkipped('This platform does not permit symlinks');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to replace');
        try {
            $this->sync->sync($this->fixture->path('fixtures/v1/wire'), $this->fixture->path('site/wire'));
        } finally {
            self::assertSame('safe', file_get_contents($real . '/keep.txt'));
            self::assertSame('original', file_get_contents($this->fixture->path('site/index.php')));
        }
    }

    public function testRejectsSymlinkedIndexWithoutTouchingWire(): void
    {
        $this->fixture->write('elsewhere/index.php', 'safe');
        $this->fixture->write('site/wire/keep.txt', 'original');
        if (!@symlink($this->fixture->path('elsewhere/index.php'), $this->fixture->path('site/index.php'))) {
            self::markTestSkipped('This platform does not permit symlinks');
        }
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Refusing to replace');
        try {
            $this->sync->sync($this->fixture->path('fixtures/v1/wire'), $this->fixture->path('site/wire'));
        } finally {
            self::assertSame('original', file_get_contents($this->fixture->path('site/wire/keep.txt')));
            self::assertSame('safe', file_get_contents($this->fixture->path('elsewhere/index.php')));
        }
    }

    public function testMissingUpstreamIndexDoesNotTouchExistingSite(): void
    {
        $this->fixture->delete($this->fixture->path('fixtures/v1/index.php'));
        $this->fixture->write('site/wire/keep.txt', 'original');
        $this->fixture->write('site/index.php', 'original index');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('index.php source is missing');
        try {
            $this->sync->sync($this->fixture->path('fixtures/v1/wire'), $this->fixture->path('site/wire'));
        } finally {
            self::assertSame('original', file_get_contents($this->fixture->path('site/wire/keep.txt')));
            self::assertSame('original index', file_get_contents($this->fixture->path('site/index.php')));
        }
    }
}
