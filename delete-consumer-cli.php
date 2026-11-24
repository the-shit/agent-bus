#!/usr/bin/env php
<?php

use Illuminate\Contracts\Console\Kernel;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;

require __DIR__.'/vendor/autoload.php';

define('LARAVEL_START', microtime(true));

define('ARTISAN_BINARY', 'bin/agent-bus');

require file_exists(__DIR__.'/vendor/autoload.php')
    ? __DIR__.'/vendor/autoload.php'
    : __DIR__.'/../../autoload.php';

$app = require_once __DIR__.'/bootstrap/app.php';

$kernel = $app->make(Kernel::class);
$status = $kernel->handle(
    $input = new ArgvInput(['agent-bus', 'delete-consumer']),
    new ConsoleOutput
);

exit($status);
