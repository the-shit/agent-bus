<?php

namespace App\Commands;

use App\Bus\NatsBus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('provision')]
#[Description('Create JetStream stream AGENT_BUS and KV bucket sessions')]
class ProvisionCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus): int
    {
        if (! $bus->isReachable()) {
            $this->error("NATS is not listening on {$bus->host()}:{$bus->port()}. Start the broker with docker compose up -d.");

            return self::FAILURE;
        }

        try {
            $bus->provision();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Stream {$bus->streamName()} and KV bucket {$bus->kvBucket()} are ready.");

        return self::SUCCESS;
    }
}
