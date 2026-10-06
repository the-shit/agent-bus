<?php

require __DIR__.'/vendor/autoload.php';

$app = new Application(__DIR__);
$app->useStoragePath(__DIR__.'/storage');
$app->configure('agent_bus');

require_once __DIR__.'/app/Bus/NatsBus.php';

use App\Bus\NatsBus;
use LaravelNats\Connection\ConnectionManager;
use LaravelNats\Laravel\NatsV2Gateway;
use LaravelZero\Framework\Application;

$manager = new ConnectionManager(app('config'));
$gateway = new NatsV2Gateway($manager);
$natsBus = new NatsBus($gateway);

// Delete the monitor consumer
$consumerName = $natsBus->monitorConsumerName();
$stream = $natsBus->stream();
$consumer = $stream->getConsumer($consumerName);

if ($consumer->exists()) {
    echo "Deleting consumer $consumerName...\n";
    $consumer->delete();
    echo "Consumer deleted.\n";
} else {
    echo "Consumer does not exist.\n";
}

echo 'Consumer names: '.implode(', ', $stream->consumerNames())."\n";
