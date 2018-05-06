<?php

require __DIR__.'/vendor/autoload.php';
require __DIR__.'/autoload.php';

use Symfony\Component\Console\Application;

$application = new Application();
$commandsDir = __DIR__ . '/Commands';
foreach (scandir($commandsDir) as $dir) {
    if ($dir === '.' || $dir === '..') {
        continue;
    }
    foreach (scandir("{$commandsDir}/{$dir}") as $command) {
        if ($command === '.' || $command === '..') {
            continue;
        }

        $class = '\Commands\\'. $dir . '\\' . substr($command, 0, -4);
        $application->add(new $class());
    }
}

$application->run();