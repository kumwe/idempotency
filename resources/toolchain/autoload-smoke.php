<?php

declare(strict_types=1);

require $argv[1] ?? dirname(__DIR__, 2) . '/vendor/autoload.php';
$manifest = json_decode(file_get_contents(dirname(__DIR__) . '/public-api/v1.json'), true, 512, JSON_THROW_ON_ERROR);
foreach (array_keys($manifest['symbols']) as $symbol) {
    if (!class_exists($symbol) && !interface_exists($symbol) && !enum_exists($symbol)) {
        throw new RuntimeException('Public symbol is not autoloadable: ' . $symbol);
    }
}
echo 'Autoloaded ' . count($manifest['symbols']) . " public symbols.\n";
