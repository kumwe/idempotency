<?php

declare(strict_types=1);

/** Enforce an explicit, complete source/test inventory; execution remains PHPUnit's responsibility. */
$root = dirname(__DIR__);
require $root . '/vendor/autoload.php';
$composer = json_decode(file_get_contents($root . '/composer.json'), true, 512, JSON_THROW_ON_ERROR);
$manifest = json_decode(file_get_contents($root . '/tests/ownership.json'), true, 512, JSON_THROW_ON_ERROR);
if ($manifest['schema'] !== 'kumwe-test-ownership/v1' || $manifest['package'] !== $composer['name']) {
    throw new RuntimeException('Wrong ownership schema or package.');
}
$files = static function (string $directory) use ($root): array {
    $paths = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)) as $file) {
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
    sort($paths, SORT_STRING);
    return $paths;
};
$source = array_keys($manifest['source']);
$tests = array_keys($manifest['tests']);
sort($source, SORT_STRING);
sort($tests, SORT_STRING);
if ($source !== $files('src') || $tests !== $files('tests')) {
    throw new RuntimeException('Source or test ownership inventory drifted; review every added or removed file.');
}
foreach ($manifest['source'] as $path => $owners) {
    if ($owners === []) {
        throw new RuntimeException('No owned verification for ' . $path);
    }
    foreach ($owners as $owner) {
        if (!isset($manifest['tests'][$owner]) || $manifest['tests'][$owner]['role'] !== 'test') {
            throw new RuntimeException('Ownership must point to an executable test: ' . $owner);
        }
    }
}
foreach ($manifest['tests'] as $path => $entry) {
    if (!in_array($entry['role'], ['test', 'contract', 'fixture'], true) || trim($entry['responsibility']) === '') {
        throw new RuntimeException('Every test file needs a role and responsibility: ' . $path);
    }
    if ($entry['role'] !== 'test') {
        continue;
    }
    $source = file_get_contents($root . '/' . $path);
    preg_match('/namespace\s+([^;]+);/', $source, $namespace);
    preg_match('/^(?:final\s+)?class\s+(\w+)/m', $source, $class);
    $reflection = new ReflectionClass($namespace[1] . '\\' . $class[1]);
    if ($reflection->isAbstract() || !$reflection->isSubclassOf(PHPUnit\Framework\TestCase::class)) {
        throw new RuntimeException('An executable suite must be a concrete PHPUnit test: ' . $path);
    }
    $methods = array_filter($reflection->getMethods(ReflectionMethod::IS_PUBLIC),
        static fn (ReflectionMethod $method): bool => str_starts_with($method->name, 'test'));
    if ($methods === []) {
        throw new RuntimeException('Suite has no executable tests: ' . $path);
    }
}
foreach ($composer['autoload']['psr-4'] as $directory) {
    if (str_starts_with($directory, 'tests')) {
        throw new RuntimeException('Test fixtures must never enter runtime autoload.');
    }
}
echo "Complete source ownership and executable package test inventory verified.\n";
