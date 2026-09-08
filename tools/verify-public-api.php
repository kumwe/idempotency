<?php

/**
 * Verify the canonical public PHP surface against its reviewed manifest, or record it deliberately.
 *
 * The manifest is generated entirely from reflection over this package's PSR-4 source tree in the shape the
 * Kumwe package-public-api/v1 schema prescribes. It contains no commit, timestamp, platform path or other
 * environmental value, so the same source produces byte-identical JSON on every supported runtime. Its
 * `release` is the newest record in CHANGELOG.md, which binds the manifest to the version the merge releases.
 * Run with --write only when a reviewed compatibility change deliberately accepts a new surface.
 *
 * @since  0.1.0
 */

declare(strict_types=1);

const CONTEXT_API_ROOT = __DIR__ . '/..';
const CONTEXT_API_SOURCE = CONTEXT_API_ROOT . '/src';
const CONTEXT_API_PREFIX = 'Kumwe\\Idempotency\\';
const CONTEXT_API_PACKAGE = 'kumwe/idempotency';
const CONTEXT_API_MANIFEST = CONTEXT_API_ROOT . '/resources/public-api/v1.json';
const CONTEXT_API_CHANGELOG = CONTEXT_API_ROOT . '/CHANGELOG.md';

$scriptFilename = $_SERVER['SCRIPT_FILENAME'] ?? null;
if (is_string($scriptFilename) && realpath($scriptFilename) === __FILE__) {
    /** @var list<string> $arguments */
    $arguments = $_SERVER['argv'] ?? [];
    try {
        exit(contextApiMain($arguments));
    } catch (Throwable $error) {
        fwrite(STDERR, "Public API verification failed: {$error->getMessage()}\n");
        exit(1);
    }
}

/**
 * Generate the current surface and either verify or deliberately write its manifest.
 *
 * @param   list<string>  $arguments  Command name followed by no option or --write.
 *
 * @return  int  Process status.
 *
 * @since   0.1.0
 */
function contextApiMain(array $arguments): int
{
    $options = array_slice($arguments, 1);
    if ($options !== [] && $options !== ['--write']) {
        fwrite(STDERR, "Usage: php tools/verify-public-api.php [--write]\n");

        return 2;
    }

    require CONTEXT_API_ROOT . '/vendor/autoload.php';
    contextApiRegisterAutoloader();
    $manifest = contextApiManifest();
    $bytes = json_encode(
        $manifest,
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
    ) . "\n";

    if ($options === ['--write']) {
        contextApiWriteManifest($bytes);
        fwrite(STDOUT, sprintf(
            "Public API recorded: %d symbols for release %s written to resources/public-api/v1.json.\n",
            count($manifest['symbols']),
            $manifest['release'],
        ));

        return 0;
    }

    if (!is_file(CONTEXT_API_MANIFEST)) {
        fwrite(STDERR, "The public API has no manifest. Review the generated surface, then run with --write.\n");

        return 1;
    }

    $expectedBytes = file_get_contents(CONTEXT_API_MANIFEST);
    if ($expectedBytes === false) {
        throw new RuntimeException('Cannot read resources/public-api/v1.json.');
    }
    if ($expectedBytes !== $bytes) {
        contextApiReportDifference($expectedBytes, $manifest);

        return 1;
    }

    fwrite(STDOUT, sprintf(
        "Public API is current: %d symbols are recorded for release %s.\n",
        count($manifest['symbols']),
        $manifest['release'],
    ));

    return 0;
}

/**
 * Register the package's dependency-free PSR-4 loader.
 *
 * @return  void
 *
 * @since   0.1.0
 */
function contextApiRegisterAutoloader(): void
{
    spl_autoload_register(static function (string $class): void {
        if (!str_starts_with($class, CONTEXT_API_PREFIX)) {
            return;
        }
        $relative = substr($class, strlen(CONTEXT_API_PREFIX));
        $path = CONTEXT_API_SOURCE . '/' . str_replace('\\', '/', $relative) . '.php';
        if (is_file($path)) {
            require $path;
        }
    });
}

/**
 * Read the newest release version recorded in CHANGELOG.md.
 *
 * The newest record is the first second-level heading, exactly as the release-on-record workflow reads it, so
 * the manifest can never name a version the merge would not release.
 *
 * @return  string  Semantic version such as `0.1.0`.
 *
 * @since   0.1.0
 */
function contextApiRelease(): string
{
    $changelog = is_file(CONTEXT_API_CHANGELOG) ? file_get_contents(CONTEXT_API_CHANGELOG) : false;
    if ($changelog === false) {
        throw new RuntimeException('CHANGELOG.md is missing; the manifest release comes from its newest record.');
    }
    foreach (explode("\n", $changelog) as $line) {
        if (!str_starts_with($line, '## ')) {
            continue;
        }
        if (in_array(trim($line), ['## Unreleased', '## [Unreleased]'], true)) {
            continue;
        }
        if (preg_match('/^## \[?([0-9]+\.[0-9]+\.[0-9]+)\]?( .*)?$/', trim($line), $match) !== 1) {
            throw new RuntimeException('The newest changelog heading does not parse as a release record: ' . $line);
        }

        return $match[1];
    }

    throw new RuntimeException('CHANGELOG.md records no release; add the `## X.Y.Z` heading first.');
}

/**
 * Render the complete canonical public surface in the package-public-api/v1 shape.
 *
 * @return  array{
 *              schema: string,
 *              package: string,
 *              release: string,
 *              namespace: string,
 *              symbols: array<string, array<string, mixed>>,
 *              extension_points: list<string>,
 *              digest_of: string
 *          }  Deterministic manifest document.
 *
 * @since   0.1.0
 */
function contextApiManifest(): array
{
    $symbols = [];
    $extensionPoints = [];
    foreach (contextApiTypeNames() as $name) {
        $type = new ReflectionClass($name);
        $symbols[$name] = contextApiSymbol($type);
        if ($type->isInterface()) {
            $extensionPoints[] = $name;
        }
    }
    ksort($symbols, SORT_STRING);
    sort($extensionPoints, SORT_STRING);

    return [
        'schema' => 'kumwe-package-public-api/v1',
        'package' => CONTEXT_API_PACKAGE,
        'release' => contextApiRelease(),
        'namespace' => CONTEXT_API_PREFIX,
        'symbols' => $symbols,
        'extension_points' => $extensionPoints,
        'digest_of' => 'src',
    ];
}

/**
 * Discover each PSR-4 type declared by the package source tree.
 *
 * @return  list<class-string>  Sorted canonical names.
 *
 * @since   0.1.0
 */
function contextApiTypeNames(): array
{
    if (!is_dir(CONTEXT_API_SOURCE)) {
        throw new RuntimeException('The source directory is missing.');
    }

    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(CONTEXT_API_SOURCE, FilesystemIterator::SKIP_DOTS),
    );
    foreach ($iterator as $file) {
        if (!$file instanceof SplFileInfo) {
            throw new RuntimeException('Source discovery returned an invalid filesystem entry.');
        }
        if ($file->isFile() && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }
    sort($paths, SORT_STRING);

    $names = [];
    foreach ($paths as $path) {
        $relative = substr($path, strlen(CONTEXT_API_SOURCE) + 1, -4);
        $name = CONTEXT_API_PREFIX . str_replace('/', '\\', $relative);
        if (!class_exists($name) && !interface_exists($name) && !enum_exists($name)) {
            throw new RuntimeException(sprintf('src/%s.php does not declare the PSR-4 type %s.', $relative, $name));
        }
        $names[] = $name;
    }
    if ($names === []) {
        throw new RuntimeException('The public API cannot be empty.');
    }

    return $names;
}

/**
 * Render one class-like declaration and only the public members it declares.
 *
 * @param   ReflectionClass<object>  $type  Reflected canonical declaration.
 *
 * @return  array<string, mixed>  Schema-shaped symbol entry.
 *
 * @since   0.1.0
 */
function contextApiSymbol(ReflectionClass $type): array
{
    $name = $type->getName();
    $parent = $type->getParentClass();
    $interfaces = array_values(array_filter(
        $type->getInterfaceNames(),
        static fn (string $interface): bool => !in_array($interface, ['UnitEnum', 'BackedEnum'], true),
    ));
    sort($interfaces, SORT_STRING);
    $kind = 'class';
    if ($type->isEnum()) {
        $kind = 'enum';
    } elseif ($type->isInterface()) {
        $kind = 'interface';
    }

    return [
        'kind' => $kind,
        'stability' => 'stable',
        'file' => 'src/' . str_replace('\\', '/', substr($name, strlen(CONTEXT_API_PREFIX))) . '.php',
        'abstract' => $type->isInterface() ? false : $type->isAbstract(),
        'final' => $type->isFinal(),
        'readonly' => $type->isReadOnly(),
        'parent' => $parent === false ? null : $parent->getName(),
        'interfaces' => $interfaces,
        'constants' => contextApiMap(contextApiConstants($type, $name)),
        'properties' => contextApiMap(contextApiProperties($type, $name)),
        'methods' => contextApiMap(contextApiMethods($type, $name)),
        'deprecated' => null,
    ];
}

/**
 * Keep a JSON map a map: an empty PHP array would otherwise encode as a list.
 *
 * @param   array<string, mixed>  $map  Keyed entries.
 *
 * @return  array<string, mixed>|stdClass  The entries, or an empty object.
 *
 * @since   0.1.0
 */
function contextApiMap(array $map): array|stdClass
{
    return $map === [] ? new stdClass() : $map;
}

/**
 * Render declared public constants, enum cases included, with their declared or backing type.
 *
 * @param   ReflectionClass<object>  $type   Reflected declaration.
 * @param   string                   $owner  Canonical declaring name.
 *
 * @return  array<string, array{type: ?string}>  Constants keyed by name.
 *
 * @since   0.1.0
 */
function contextApiConstants(ReflectionClass $type, string $owner): array
{
    $constants = [];
    $backing = null;
    if (enum_exists($owner)) {
        $backingType = (new ReflectionEnum($owner))->getBackingType();
        $backing = $backingType === null ? null : contextApiReflectionType($backingType, $owner);
    }
    foreach ($type->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $owner) {
            continue;
        }
        if ($constant->isEnumCase()) {
            $constants[$constant->getName()] = ['type' => $backing];
            continue;
        }
        $constantType = $constant->getType();
        $constants[$constant->getName()] = [
            'type' => $constantType === null
                ? get_debug_type($constant->getValue())
                : contextApiReflectionType($constantType, $owner),
        ];
    }
    ksort($constants, SORT_STRING);

    return $constants;
}

/**
 * Render declared public properties.
 *
 * @param   ReflectionClass<object>  $type   Reflected declaration.
 * @param   string                   $owner  Canonical declaring name.
 *
 * @return  array<string, array{type: ?string, static: bool, readonly: bool}>  Properties keyed by name.
 *
 * @since   0.1.0
 */
function contextApiProperties(ReflectionClass $type, string $owner): array
{
    $properties = [];
    foreach ($type->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $owner) {
            continue;
        }
        $propertyType = $property->getType();
        $properties[$property->getName()] = [
            'type' => $propertyType === null ? null : contextApiReflectionType($propertyType, $owner),
            'static' => $property->isStatic(),
            'readonly' => $property->isReadOnly(),
        ];
    }
    ksort($properties, SORT_STRING);

    return $properties;
}

/**
 * Render every declared public method, including the public enum methods.
 *
 * @param   ReflectionClass<object>  $type   Reflected declaration.
 * @param   string                   $owner  Canonical declaring name.
 *
 * @return  array<string, array<string, mixed>>  Methods keyed by name.
 *
 * @since   0.1.0
 */
function contextApiMethods(ReflectionClass $type, string $owner): array
{
    $methods = [];
    foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $owner) {
            continue;
        }
        $returnType = $method->getReturnType();
        $methods[$method->getName()] = [
            'visibility' => 'public',
            'static' => $method->isStatic(),
            'parameters' => array_map(
                static fn (ReflectionParameter $parameter): array => contextApiParameter($parameter, $owner),
                $method->getParameters(),
            ),
            'return' => $returnType === null ? null : contextApiReflectionType($returnType, $owner),
        ];
    }
    ksort($methods, SORT_STRING);

    return $methods;
}

/**
 * Render one ordered method parameter.
 *
 * @param   ReflectionParameter  $parameter  Reflected parameter.
 * @param   string               $owner      Canonical declaring type name.
 *
 * @return  array{name: string, type: ?string, optional: bool, variadic: bool, by_reference: bool}  Parameter.
 *
 * @since   0.1.0
 */
function contextApiParameter(ReflectionParameter $parameter, string $owner): array
{
    $type = $parameter->getType();

    return [
        'name' => $parameter->getName(),
        'type' => $type === null ? null : contextApiReflectionType($type, $owner),
        'optional' => $parameter->isOptional(),
        'variadic' => $parameter->isVariadic(),
        'by_reference' => $parameter->isPassedByReference(),
    ];
}

/**
 * Render a named, union or intersection type without import abbreviations.
 *
 * @param   ReflectionType  $type   Reflected declaration type.
 * @param   string          $owner  Canonical declaring type name used to resolve `self`.
 *
 * @return  string  Canonical type expression.
 *
 * @since   0.1.0
 */
function contextApiReflectionType(ReflectionType $type, string $owner): string
{
    if ($type instanceof ReflectionNamedType) {
        $name = $type->getName();
        if ($name === 'self' || $name === 'static') {
            $name = $owner;
        }

        return $type->allowsNull() && !in_array($name, ['mixed', 'null'], true) ? '?' . $name : $name;
    }
    if ($type instanceof ReflectionUnionType) {
        return implode('|', array_map(
            static fn (ReflectionType $member): string => contextApiReflectionType($member, $owner),
            $type->getTypes(),
        ));
    }
    if ($type instanceof ReflectionIntersectionType) {
        return implode('&', array_map(
            static fn (ReflectionType $member): string => contextApiReflectionType($member, $owner),
            $type->getTypes(),
        ));
    }

    throw new RuntimeException('Unknown reflection type kind ' . $type::class . '.');
}

/**
 * Atomically replace the reviewed manifest.
 *
 * @param   string  $bytes  Canonical manifest JSON.
 *
 * @return  void
 *
 * @since   0.1.0
 */
function contextApiWriteManifest(string $bytes): void
{
    $directory = dirname(CONTEXT_API_MANIFEST);
    if (!is_dir($directory) && !mkdir($directory, 0777, true) && !is_dir($directory)) {
        throw new RuntimeException('Cannot create resources/public-api.');
    }
    $temporary = tempnam($directory, '.public-api-');
    if ($temporary === false) {
        throw new RuntimeException('Cannot create a temporary API manifest.');
    }
    try {
        if (file_put_contents($temporary, $bytes, LOCK_EX) !== strlen($bytes)) {
            throw new RuntimeException('Cannot write the complete API manifest.');
        }
        if (!rename($temporary, CONTEXT_API_MANIFEST)) {
            throw new RuntimeException('Cannot replace the API manifest atomically.');
        }
    } finally {
        if (is_file($temporary)) {
            unlink($temporary);
        }
    }
}

/**
 * Report the first semantic difference or non-canonical JSON formatting.
 *
 * @param   string                $expectedBytes  Checked-in manifest bytes.
 * @param   array<string, mixed>  $actual         Generated surface.
 *
 * @return  void
 *
 * @since   0.1.0
 */
function contextApiReportDifference(string $expectedBytes, array $actual): void
{
    try {
        $expected = json_decode($expectedBytes, true, 512, JSON_THROW_ON_ERROR);
    } catch (JsonException $error) {
        fwrite(STDERR, "The public API manifest is not valid JSON: {$error->getMessage()}\n");

        return;
    }

    $difference = contextApiFirstDifference($expected, $actual);
    if ($difference === null) {
        fwrite(STDERR, "The public API manifest is semantically current but not canonical JSON; run --write.\n");

        return;
    }

    fwrite(STDERR, sprintf(
        "The public API drifted at %s.\nExpected: %s\nActual:   %s\n"
            . "Treat an incompatible change as a new major; use --write only after compatibility review.\n",
        $difference['path'],
        contextApiDisplayValue($difference['expected']),
        contextApiDisplayValue($difference['actual']),
    ));
}

/**
 * Find the first semantic difference between two decoded manifest values.
 *
 * @param   mixed   $expected  Recorded value.
 * @param   mixed   $actual    Generated value.
 * @param   string  $path      JSONPath-like location.
 *
 * @return  ?array{path: string, expected: mixed, actual: mixed}  First difference, or null when equal.
 *
 * @since   0.1.0
 */
function contextApiFirstDifference(mixed $expected, mixed $actual, string $path = '$'): ?array
{
    if (!is_array($expected) || !is_array($actual)) {
        return $expected === $actual ? null : ['path' => $path, 'expected' => $expected, 'actual' => $actual];
    }
    $keys = array_values(array_unique([...array_keys($expected), ...array_keys($actual)], SORT_REGULAR));
    foreach ($keys as $key) {
        $memberPath = is_int($key) ? $path . '[' . $key . ']' : $path . '.' . $key;
        if (!array_key_exists($key, $expected)) {
            return ['path' => $memberPath, 'expected' => '<absent>', 'actual' => $actual[$key]];
        }
        if (!array_key_exists($key, $actual)) {
            return ['path' => $memberPath, 'expected' => $expected[$key], 'actual' => '<absent>'];
        }
        $difference = contextApiFirstDifference($expected[$key], $actual[$key], $memberPath);
        if ($difference !== null) {
            return $difference;
        }
    }

    return null;
}

/**
 * Render a concise JSON-compatible difference value.
 *
 * @param   mixed  $value  Difference member.
 *
 * @return  string  Single-line diagnostic representation.
 *
 * @since   0.1.0
 */
function contextApiDisplayValue(mixed $value): string
{
    $encoded = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

    return $encoded === false ? var_export($value, true) : $encoded;
}
