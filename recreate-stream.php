<?php

require __DIR__.'/vendor/autoload.php';

$app = new Application(__DIR__);
$app->useStoragePath(__DIR__.'/storage');
$app->configure('agent_bus');

$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use Basis\Nats\Stream\Configuration;
use Illuminate\Contracts\Console\Kernel;
use LaravelNats\Connection\ConnectionManager;
use LaravelNats\Laravel\NatsV2Gateway;
use LaravelZero\Framework\Application;

$manager = new ConnectionManager(app('config'));
$gateway = new NatsV2Gateway($manager);
$js = $gateway->jetstream();

$stream = $js->stream('AGENT_BUS');
if ($stream->exists()) {
    echo "Deleting stream AGENT_BUS...\n";
    $js->deleteStream('AGENT_BUS');
    echo "Stream deleted.\n";
} else {
    echo "Stream does not exist.\n";
}

echo "Recreating stream...\n";
$config = new Configuration;
$config->setSubjects(['repo.>', 'session.>', 'spotify.>']);
$config->setDuplicateWindow(2.0);
$js->createStream('AGENT_BUS', $config);
echo "Stream recreated.\n";
