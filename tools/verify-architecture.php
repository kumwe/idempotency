<?php

declare(strict_types=1);

$source = dirname(__DIR__) . '/src';
$failures = [];
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS)) as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }
    foreach (token_get_all(file_get_contents($file->getPathname())) as $token) {
        if (!is_array($token) || !in_array($token[0], [T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }
        $name = ltrim($token[1], '\\');
        foreach (['Kumwe\\App\\', 'Kumwe\\Extension\\', 'Doctrine\\', 'Symfony\\', 'Illuminate\\'] as $prefix) {
            if (str_starts_with($name, $prefix)) {
                $failures[] = $file->getFilename() . ':' . $token[2] . ': forbidden dependency ' . $name;
            }
        }
    }
}
if ($failures !== []) {
    throw new RuntimeException(implode("\n", $failures));
}
echo "Portable dependency boundary verified.\n";
