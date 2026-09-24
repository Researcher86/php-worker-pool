<?php

declare(strict_types=1);

namespace PhpWorkerPool\Tests\Bin;

use FilesystemIterator;
use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * bin/bootstrap.php's own walk-up-to-find-vendor/autoload.php logic, run as
 * a real subprocess against a constructed directory tree - not read, run:
 * a fatal error in the walk would otherwise take this test process down
 * with it, which is exactly why bootstrap.php itself is exercised through
 * proc_open rather than required directly.
 *
 * The real file under test, copied into each constructed tree so a change
 * to bootstrap.php's actual logic is what this test exercises - not a
 * second, hand-maintained copy of it that could quietly drift from the
 * real one.
 */
final class BootstrapTest extends TestCase
{
    private string $tmpDir = '';

    protected function setUp(): void
    {
        $this->tmpDir = sys_get_temp_dir() . '/pwp-bootstrap-test-' . getmypid() . '-' . uniqid();
        mkdir($this->tmpDir, 0o777, true);
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->tmpDir);
    }

    public function testFindsTheAutoloaderOneLevelUpWhenThisRepoIsRoot(): void
    {
        // bootstrap.php's own real position: bin/bootstrap.php with
        // vendor/autoload.php exactly one directory above bin/.
        $marker = $this->buildTree($this->tmpDir . '/root-case', bootstrapAt: 'bin', autoloadAt: '');

        $this->assertRequireSucceeds($this->tmpDir . '/root-case/bin/bootstrap.php');
        $this->assertFileExists($marker);
    }

    public function testFindsTheConsumersAutoloaderWhenInstalledAsADependency(): void
    {
        // vendor/tanat/php-worker-pool/bin/bootstrap.php, with no
        // vendor/autoload.php of its own anywhere under
        // vendor/tanat/php-worker-pool/ - only the CONSUMING project's own,
        // three levels further up.
        $marker = $this->buildTree(
            $this->tmpDir . '/consumer',
            bootstrapAt: 'vendor/tanat/php-worker-pool/bin',
            autoloadAt: '',
        );

        $this->assertRequireSucceeds($this->tmpDir . '/consumer/vendor/tanat/php-worker-pool/bin/bootstrap.php');
        $this->assertFileExists($marker);
    }

    public function testFailsWithAClearMessageWhenNoAutoloaderExistsAnywhereAbove(): void
    {
        $binDir = $this->tmpDir . '/orphan/bin';
        mkdir($binDir, 0o777, true);
        copy(__DIR__ . '/../../bin/bootstrap.php', $binDir . '/bootstrap.php');

        $process = proc_open(
            [PHP_BINARY, $binDir . '/bootstrap.php'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('Could not find vendor/autoload.php', (string) $errors);
    }

    public function testFailsWhenTheWalkFindsAnAutoloaderThatDoesNotMapThisPackage(): void
    {
        // The walk stops at the FIRST match - a layout where a higher
        // vendor/autoload.php exists but belongs to something else (and so
        // maps nothing of this package) must fail with the dedicated message
        // rather than a random "class not found" at first use.
        $this->buildTree($this->tmpDir . '/wrong-relative', bootstrapAt: 'bin', autoloadAt: '', mapPackage: false);

        [$exitCode, $errors] = $this->runBootstrap($this->tmpDir . '/wrong-relative/bin/bootstrap.php');

        $this->assertNotSame(0, $exitCode);
        $this->assertStringContainsString('does not autoload this package', (string) $errors);
    }

    /**
     * Builds $root/$bootstrapAt/bootstrap.php (the real file, copied) plus a
     * fake $root/vendor/autoload.php that writes a marker file - and, unless
     * $mapPackage is false, loads the package's real classes (by pulling in
     * this repo's own vendor/autoload.php) - proof of exactly which
     * autoloader the walk found, without needing a real Composer-generated
     * one to make the assertion.
     *
     * @return string the marker file's path
     */
    private function buildTree(string $root, string $bootstrapAt, string $autoloadAt, bool $mapPackage = true): string
    {
        $binDir = rtrim($root . '/' . $bootstrapAt, '/');
        mkdir($binDir, 0o777, true);
        copy(__DIR__ . '/../../bin/bootstrap.php', $binDir . '/bootstrap.php');

        $vendorDir = rtrim($root . '/' . $autoloadAt, '/') . '/vendor';

        if (!is_dir($vendorDir)) {
            mkdir($vendorDir, 0o777, true);
        }

        $marker = $root . '/found.marker';
        $autoload = $mapPackage
            ? 'require ' . var_export(dirname(__DIR__, 2) . '/vendor/autoload.php', true) . ";\n"
            : '';

        file_put_contents(
            $vendorDir . '/autoload.php',
            '<?php ' . $autoload . 'file_put_contents(' . var_export($marker, true) . ", 'loaded');\n",
        );

        return $marker;
    }

    private function assertRequireSucceeds(string $bootstrapPath): void
    {
        [$exitCode, $errors] = $this->runBootstrap($bootstrapPath);

        $this->assertSame(0, $exitCode, $errors);
    }

    /** @return array{int, string} */
    private function runBootstrap(string $bootstrapPath): array
    {
        $process = proc_open(
            [PHP_BINARY, $bootstrapPath],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        $this->assertIsResource($process);

        $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $exitCode = proc_close($process);

        return [$exitCode, (string) $errors];
    }

    private function removeTree(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        $items = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
            RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($items as $item) {
            $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
        }

        rmdir($dir);
    }
}
