<?php

/**
 * Syntax-check every PHP file in the library, its tests, examples and tooling.
 *
 * Dependency-free so the lane runs before any composer install. A directory that does not exist is skipped,
 * so the founding state is a passing state; an empty result is not.
 *
 * @since  0.1.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$failures = [];
$count = 0;

foreach (['src', 'tests', 'tools', 'examples', 'resources/toolchain'] as $directory) {
    $path = $root . '/' . $directory;
    if (!is_dir($path)) {
        continue;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo || $file->getExtension() !== 'php') {
            continue;
        }
        $count++;
        $output = [];
        exec('php -l ' . escapeshellarg($file->getPathname()) . ' 2>&1', $output, $status);
        if ($status !== 0) {
            $failures[] = implode("\n", $output);
        }
    }
}

if ($count === 0) {
    $failures[] = 'No PHP files were found to lint.';
}

if ($failures !== []) {
    fwrite(STDERR, "Lint failed:\n" . implode("\n", $failures) . "\n");
    exit(1);
}

echo "Lint passed: {$count} PHP files.\n";
