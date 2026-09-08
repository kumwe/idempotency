<?php

/** Generate reviewable public documentation from the same reflected source as the API manifest. */

declare(strict_types=1);

require_once __DIR__ . '/verify-public-api.php';
require_once dirname(__DIR__) . '/vendor/autoload.php';

/** Remove only PHPDoc delimiters and indentation, preserving the actual contract wording. */
$publicContractDoc = static function (string|false $comment): string {
    if ($comment === false) {
        return '';
    }
    $lines = explode("\n", $comment);
    $lines = array_map(static function (string $line): string {
        $line = trim(str_replace(['/**', '*/'], '', trim($line)));
        return preg_replace('/^\* ?/', '', $line) ?? '';
    }, $lines);
    $text = implode("\n", $lines);
    return trim(str_replace(['/**', '*/'], '', $text));
};

$options = array_slice($argv, 1);
if (!in_array($options, [[], ['--write']], true)) {
    throw new InvalidArgumentException('Usage: php tools/render-public-api.php [--write]');
}
$document = "# Public API\n\n"
    . "Generated from package source. The contracts below retain source PHPDoc, complete callable signatures, "
    . "public properties and constants. The canonical manifest is `resources/public-api/v1.json`. "
    . "Run `composer docs` to reject drift. Host adapters retain persistence, authorization and transactions.\n\n";
foreach (contextApiTypeNames() as $name) {
    $type = new ReflectionClass($name);
    $manifest = contextApiSymbol($type);
    $document .= '## ' . $name . "\n\nSource: [" . $manifest['file'] . '](../' . $manifest['file'] . ").\n\n";
    $comment = $publicContractDoc($type->getDocComment());
    if ($comment !== '') {
        $document .= "```text\n" . $comment . "\n```\n\n";
    }
    foreach ($type->getReflectionConstants(ReflectionClassConstant::IS_PUBLIC) as $constant) {
        if ($constant->getDeclaringClass()->getName() !== $name) {
            continue;
        }
        $value = $constant->getValue();
        $rendered = $value instanceof BackedEnum ? var_export($value->value, true) : var_export($value, true);
        $document .= '### `' . $constant->getName() . "`\n\n"
            . ($constant->isEnumCase() ? 'Enum case backed value: ' : 'Constant value: ') . '`' . $rendered . "`.\n\n";
    }
    foreach ($type->getProperties(ReflectionProperty::IS_PUBLIC) as $property) {
        if ($property->getDeclaringClass()->getName() !== $name) {
            continue;
        }
        $propertyType = $property->getType();
        $document .= '### `$' . $property->getName() . "`\n\n```php\npublic "
            . ($property->isStatic() ? 'static ' : '') . ($property->isReadOnly() ? 'readonly ' : '')
            . ($propertyType === null ? '' : contextApiReflectionType($propertyType, $name) . ' ')
            . '$' . $property->getName() . ";\n```\n\n";
    }
    foreach ($type->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
        if ($method->getDeclaringClass()->getName() !== $name) {
            continue;
        }
        $parameters = [];
        foreach ($method->getParameters() as $parameter) {
            $parameterType = $parameter->getType();
            $rendered = ($parameterType === null ? '' : contextApiReflectionType($parameterType, $name) . ' ')
                . ($parameter->isPassedByReference() ? '&' : '') . ($parameter->isVariadic() ? '...' : '')
                . '$' . $parameter->getName();
            if ($parameter->isDefaultValueAvailable()) {
                $rendered .= ' = ' . ($parameter->isDefaultValueConstant()
                    ? $parameter->getDefaultValueConstantName() : var_export($parameter->getDefaultValue(), true));
            }
            $parameters[] = $rendered;
        }
        $return = $method->getReturnType();
        $document .= '### `' . $method->getName() . "`\n\n```php\npublic "
            . ($method->isStatic() ? 'static ' : '') . 'function ' . $method->getName()
            . '(' . implode(', ', $parameters) . ')'
            . ($return === null ? '' : ': ' . contextApiReflectionType($return, $name)) . "\n```\n\n";
        $comment = $publicContractDoc($method->getDocComment());
        if ($comment !== '') {
            $document .= "```text\n" . $comment . "\n```\n\n";
        }
    }
}
$path = dirname(__DIR__) . '/docs/public-api.md';
if ($options === ['--write']) {
    file_put_contents($path, $document);
} elseif (!is_file($path) || file_get_contents($path) !== $document) {
    throw new RuntimeException('Public API documentation differs from source; review and regenerate it.');
}
echo "Complete public documentation agrees with source signatures and PHPDoc.\n";
