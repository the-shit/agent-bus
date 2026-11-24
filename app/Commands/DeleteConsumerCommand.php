<?php

namespace App\Commands;

use App\Bus\NatsBus;
use LaravelZero\Framework\Commands\Command;

class DeleteConsumerCommand extends Command
{
    protected $signature = 'delete-consumer {--force : Required. Deletes the monitor consumer and its delivery position}';

    protected $description = 'Delete the monitor consumer to force recreation';

    public function handle(NatsBus $bus): int
    {
        $consumerName = $bus->monitorConsumerName();

        if (! $this->option('force')) {
            $this->error("Refusing to delete consumer {$consumerName} without --force.");

            return self::FAILURE;
        }
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
