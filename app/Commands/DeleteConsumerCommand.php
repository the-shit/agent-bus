<?php

namespace App\Commands;

use App\Bus\NatsBus;
use LaravelZero\Framework\Commands\Command;

class DeleteConsumerCommand extends Command
{
    protected $signature = 'delete-consumer';

    protected $description = 'Delete the monitor consumer to force recreation';

    public function handle(NatsBus $bus): int
    {
        $consumerName = $bus->monitorConsumerName();
        $stream = $bus->stream();
        $consumer = $stream->getConsumer($consumerName);

        if ($consumer->exists()) {
            $consumer->delete();
            $this->info("Deleted consumer $consumerName");
        } else {
            $this->warn("Consumer $consumerName does not exist");
        }

        return self::SUCCESS;
    }
}
