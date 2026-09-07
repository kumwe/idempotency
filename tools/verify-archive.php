<?php

/**
 * Prove an extracted Composer archive ships exactly the consumer surface and nothing from the development lane.
 *
 * The expected set is derived from the checkout the archive was built from: the six root records, and every
 * file under docs/, examples/, resources/ and src/. Anything else in the archive is a leak and anything
 * missing is a broken consumer. The archive must carry MIGRATION-HANDOFF.md because the App adoption gate
 * reads it from the release, and every src/ file must be an exported symbol of the shipped public API manifest.
 *
 * @since  0.1.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$candidate = $argv[1] ?? null;
if (!is_string($candidate)) {
    fwrite(STDERR, "Usage: php tools/verify-archive.php EXTRACTED_ARCHIVE_ROOT\n");
    exit(1);
}
$archive = realpath($candidate);
if ($archive === false || !is_dir($archive)) {
    fwrite(STDERR, "The extracted archive root does not exist: {$candidate}\n");
    exit(1);
}

/**
 * List every regular file below a directory as slash-separated paths relative to it.
 *
 * @param   string        $base      Directory to walk.
 * @param   list<string>  $failures  Findings collected so far.
 * @param   string        $prefix    Repository-relative prefix reported for findings.
 *
 * @return  array<string, true>  Relative paths.
 *
 * @since   0.1.0
 */
function contextArchiveFiles(string $base, array &$failures, string $prefix = ''): array
{
    $files = [];
    if (!is_dir($base)) {
        return $files;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($base, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            continue;
        }
        $relative = $prefix . str_replace('\\', '/', substr($file->getPathname(), strlen($base) + 1));
        if ($file->isLink()) {
            $failures[] = 'Symbolic link present: ' . $relative;
            continue;
        }
        if ($file->isFile()) {
            $files[$relative] = true;
        }
    }

    return $files;
}

$failures = [];
$expected = [];
foreach (['CHANGELOG.md', 'CHARTER.md', 'LICENSE', 'MIGRATION-HANDOFF.md', 'README.md', 'composer.json'] as $file) {
    if (!is_file($root . '/' . $file)) {
        $failures[] = "The checkout lacks {$file}, which every release must ship.";
    }
    $expected[$file] = true;
}
foreach (['docs', 'examples', 'resources', 'src'] as $directory) {
    $files = contextArchiveFiles($root . '/' . $directory, $failures, $directory . '/');
    if ($files === []) {
        $failures[] = "The checkout ships nothing under {$directory}/.";
    }
    $expected += $files;
}

$actual = contextArchiveFiles($archive, $failures);
foreach (array_diff_key($expected, $actual) as $relative => $_) {
    $failures[] = 'Required archive file is missing: ' . $relative;
}
foreach (array_diff_key($actual, $expected) as $relative => $_) {
    $failures[] = 'Unexpected archive file: ' . $relative;
}
foreach (array_intersect_key($expected, $actual) as $relative => $_) {
    if (hash_file('sha256', $root . '/' . $relative) !== hash_file('sha256', $archive . '/' . $relative)) {
        $failures[] = 'Archive file differs from the checkout: ' . $relative;
    }
}

foreach (
    ['.github', '.phpstan.cache', 'tests', 'tools', 'vendor', 'dist', '.editorconfig', '.gitattributes',
    '.gitignore', 'composer.lock', 'phpcs.xml', 'phpstan.neon'] as $forbidden
) {
    if (file_exists($archive . '/' . $forbidden)) {
        $failures[] = 'Development-lane path shipped: ' . $forbidden;
    }
}

$manifestBytes = is_file($archive . '/resources/public-api/v1.json')
    ? file_get_contents($archive . '/resources/public-api/v1.json')
    : false;
$manifest = $manifestBytes === false ? null : json_decode($manifestBytes, true);
$symbols = is_array($manifest) && is_array($manifest['symbols'] ?? null) ? $manifest['symbols'] : [];
$shippedSources = [];
foreach (array_keys($actual) as $relative) {
    if (str_starts_with($relative, 'src/')) {
        $shippedSources[$relative] = true;
    }
}
foreach ($symbols as $fqcn => $entry) {
    $file = is_array($entry) ? ($entry['file'] ?? null) : null;
    if (!is_string($file) || !isset($shippedSources[$file])) {
        $failures[] = "The shipped manifest exports {$fqcn} but its file is absent from the archive.";
        continue;
    }
    unset($shippedSources[$file]);
}
foreach (array_keys($shippedSources) as $orphan) {
    $failures[] = "The archive ships {$orphan}, which the public API manifest does not export.";
}

if ($failures !== []) {
    fwrite(STDERR, "Archive verification failed:\n - " . implode("\n - ", array_unique($failures)) . "\n");
    exit(1);
}

printf("Archive verified: %d files, %d exported symbols, no development-lane path.\n", count($actual), count($symbols));
