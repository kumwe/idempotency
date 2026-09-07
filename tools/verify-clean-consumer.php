<?php

/**
 * Build the consumer archive from this checkout and prove it installs and runs with no development state.
 *
 * This is the release gate a package checkout cannot fake: the archive Composer would publish is built,
 * extracted, held to the reviewed file set, then installed as a dependency in a fresh consumer with
 * `--no-dev --classmap-authoritative`, and exercised using only that consumer's Composer autoloader —
 * so a consumer artifact that needs a test fixture, a path repository, a development package or an
 * undeclared dependency fails here rather than at adoption.
 *
 * Set KUMWE_CLEAN_CONSUMER_COMPOSER_ARGS to pass extra options to the archive's install; a local runtime
 * outside the declared PHP range passes `--ignore-platform-req=php` that way. CI never sets it.
 *
 * @since 0.1.0
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$workspace = rtrim(sys_get_temp_dir(), '/\\') . '/kumwe-idempotency-consumer-' . bin2hex(random_bytes(6));
$extraEnvironment = getenv('KUMWE_CLEAN_CONSUMER_COMPOSER_ARGS');
$extra = [];
if (is_string($extraEnvironment) && trim($extraEnvironment) !== '') {
    $split = preg_split('/\s+/', trim($extraEnvironment));
    $extra = $split === false ? [] : $split;
}

/**
 * Run one command, streaming its output, and stop the gate on the first failure.
 *
 * @param   list<string>  $arguments  Program followed by its arguments.
 * @param   string        $workspace  Temporary directory to remove on failure.
 *
 * @return  void
 *
 * @since   0.1.0
 */
function consumerRun(array $arguments, string $workspace): void
{
    echo '> ' . implode(' ', $arguments) . "\n";
    $status = 1;
    passthru(implode(' ', array_map(escapeshellarg(...), $arguments)) . ' 2>&1', $status);
    if ($status !== 0) {
        consumerFail('the command exited with status ' . $status . '.', $workspace);
    }
}

/**
 * Report one failure, remove the workspace and exit.
 *
 * @param   string  $message    What failed.
 * @param   string  $workspace  Temporary directory to remove.
 *
 * @return  never
 *
 * @since   0.1.0
 */
function consumerFail(string $message, string $workspace): never
{
    fwrite(STDERR, 'Clean consumer verification failed: ' . $message . "\n");
    consumerRemove($workspace);
    exit(1);
}

/**
 * Remove a directory tree created by this gate.
 *
 * @param   string  $path  Directory to remove; a missing path is ignored.
 *
 * @return  void
 *
 * @since   0.1.0
 */
function consumerRemove(string $path): void
{
    if (!is_dir($path)) {
        return;
    }
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($iterator as $entry) {
        if (!$entry instanceof SplFileInfo) {
            continue;
        }
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($path);
}

if (!mkdir($workspace, 0777, true) || !is_dir($workspace)) {
    fwrite(STDERR, "Clean consumer verification failed: cannot create a temporary workspace.\n");
    exit(1);
}

$distDirectory = $workspace . '/dist';
consumerRun(
    [
        'composer',
        '--working-dir=' . $root,
        'archive',
        '--format=zip',
        '--dir=' . $distDirectory,
        '--file=kumwe-idempotency',
    ],
    $workspace,
);
$archive = $distDirectory . '/kumwe-idempotency.zip';
if (!is_file($archive)) {
    consumerFail('Composer did not create the expected archive.', $workspace);
}

$zip = new ZipArchive();
if ($zip->open($archive) !== true) {
    consumerFail('the archive cannot be opened.', $workspace);
}
$packageRoot = $workspace . '/package';
if (!mkdir($packageRoot) || !$zip->extractTo($packageRoot)) {
    consumerFail('the archive cannot be extracted.', $workspace);
}
$zip->close();

$package = $packageRoot;
if (!is_file($package . '/composer.json')) {
    $directories = glob($packageRoot . '/*', GLOB_ONLYDIR);
    $directories = $directories === false ? [] : $directories;
    if (count($directories) !== 1 || !is_file($directories[0] . '/composer.json')) {
        consumerFail('the archive does not contain one package root.', $workspace);
    }
    $package = $directories[0];
}

consumerRun([PHP_BINARY, $root . '/tools/verify-archive.php', $package], $workspace);
consumerRun(['composer', '--working-dir=' . $package, 'validate', '--strict'], $workspace);

$metadataBytes = file_get_contents($package . '/composer.json');
$manifestBytes = file_get_contents($package . '/resources/public-api/v1.json');
$metadata = is_string($metadataBytes) ? json_decode($metadataBytes, true) : null;
$manifest = is_string($manifestBytes) ? json_decode($manifestBytes, true) : null;
$release = is_array($manifest) ? ($manifest['release'] ?? null) : null;
if (
    !is_array($metadata)
    || !is_array($manifest)
    || !is_string($release)
    || preg_match('/^[0-9]+\.[0-9]+\.[0-9]+$/D', $release) !== 1
) {
    consumerFail('the archived package metadata or release record cannot be read.', $workspace);
}

// The package repository points at the exact built ZIP; no source checkout or path repository is reachable.
// Version metadata describes this local candidate and does not mutate the archive or publish a release.
$metadata['version'] = $release;
$metadata['dist'] = ['type' => 'zip', 'url' => 'file://' . $archive, 'shasum' => sha1_file($archive)];
$consumer = $workspace . '/consumer';
if (!mkdir($consumer)) {
    consumerFail('the fresh consumer directory cannot be created.', $workspace);
}
// Snapshot the declared Kumwe dependencies into isolated ZIP artifacts. This verifies the
// installed integration candidate, not release provenance; immutable release admission is separate.
$dependencyRepositories = [];
$installedPackages = json_decode(file_get_contents($root . '/vendor/composer/installed.json'), true, 512, JSON_THROW_ON_ERROR);
foreach ($installedPackages['packages'] as $dependency) {
    $name = $dependency['name'];
    if (!str_starts_with($name, 'kumwe/')) {
        continue;
    }
    $dependencyRoot = realpath($root . '/vendor/' . $name);
    if ($dependencyRoot === false) {
        consumerFail('installed declared dependency is missing: ' . $name, $workspace);
    }
    $filename = str_replace('/', '-', $name);
    consumerRun(['composer', '--working-dir=' . $dependencyRoot, 'archive', '--format=zip', '--dir=' . $distDirectory, '--file=' . $filename], $workspace);
    $dependencyMetadata = json_decode(file_get_contents($dependencyRoot . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
    $dependencyMetadata['version'] = $dependency['version'];
    $dependencyArchive = $distDirectory . '/' . $filename . '.zip';
    $dependencyMetadata['dist'] = ['type' => 'zip', 'url' => 'file://' . $dependencyArchive, 'shasum' => sha1_file($dependencyArchive)];
    $dependencyRepositories[] = ['type' => 'package', 'package' => $dependencyMetadata];
}
$consumerMetadata = [
    'name' => 'kumwe/clean-consumer',
    'description' => 'Isolated verification of the built package archive.',
    'license' => 'proprietary',
    'require' => ['kumwe/idempotency' => $release, ...array_filter($metadata['require'], static fn (string $constraint, string $name): bool => str_starts_with($name, 'kumwe/'), ARRAY_FILTER_USE_BOTH)],
    'repositories' => [
        'packagist.org' => false,
        ['type' => 'package', 'package' => $metadata],
        ...$dependencyRepositories,
    ],
    'config' => ['allow-plugins' => false],
    'prefer-stable' => true,
];
if (
    file_put_contents(
        $consumer . '/composer.json',
        json_encode($consumerMetadata, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n",
    ) === false
) {
    consumerFail('the fresh consumer manifest cannot be written.', $workspace);
}

consumerRun(
    [
        'composer',
        '--working-dir=' . $consumer,
        'install',
        '--no-interaction',
        '--no-progress',
        '--no-dev',
        '--no-plugins',
        '--no-scripts',
        '--classmap-authoritative',
        ...$extra,
    ],
    $workspace,
);
$installed = $consumer . '/vendor/kumwe/idempotency';
consumerRun([PHP_BINARY, $root . '/tools/verify-archive.php', $installed], $workspace);
foreach (['phpstan', 'squizlabs'] as $developmentVendor) {
    if (is_dir($consumer . '/vendor/' . $developmentVendor)) {
        consumerFail('the no-dev install still contains vendor/' . $developmentVendor . '.', $workspace);
    }
}
if (is_dir($installed . '/vendor')) {
    consumerFail('the dependency must use the consumer autoloader, not its own vendor tree.', $workspace);
}
$autoload = $consumer . '/vendor/autoload.php';
consumerRun([PHP_BINARY, $installed . '/resources/toolchain/autoload-smoke.php', $autoload], $workspace);
consumerRun([PHP_BINARY, $installed . '/examples/typed-consumer.php', $autoload], $workspace);

$classmap = require $consumer . '/vendor/composer/autoload_classmap.php';
$symbols = is_array($manifest['symbols'] ?? null) ? $manifest['symbols'] : [];
if (!is_array($classmap) || $symbols === []) {
    consumerFail('the installed classmap or the shipped manifest cannot be read.', $workspace);
}
foreach (array_keys($symbols) as $symbol) {
    if (!array_key_exists($symbol, $classmap)) {
        consumerFail('the authoritative classmap does not list ' . $symbol . '.', $workspace);
    }
}
foreach (array_keys($classmap) as $symbol) {
    if (
        !is_string($symbol)
        || str_starts_with($symbol, 'Kumwe\\App\\')
        || str_starts_with($symbol, 'Kumwe\\Idempotency\\Tests\\')
    ) {
        consumerFail('the consumer classmap contains application or development state.', $workspace);
    }
}

$fileCount = 0;
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($installed, FilesystemIterator::SKIP_DOTS),
);
foreach ($iterator as $entry) {
    if ($entry instanceof SplFileInfo && $entry->isFile()) {
        $fileCount++;
    }
}
consumerRemove($workspace);

echo sprintf(
    "Clean consumer verified: a %d-file archive installed as a dependency without development dependencies, "
        . "its %d public symbols in the authoritative classmap, smoke and example green.\n",
    $fileCount,
    count($symbols),
);
