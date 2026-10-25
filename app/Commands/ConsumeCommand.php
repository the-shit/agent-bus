<?php

namespace App\Commands;

use App\Bus\NatsBus;
use App\Bus\InboxDelivery;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('consume {--session=* : Session ID(s) to consume from (can be repeated) } {--max=1 : Maximum messages to consume}')]
#[Description('Pull pending inbox messages for specified sessions without a sidecar')]
class ConsumeCommand extends Command
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

        $sessions = $this->option('session');
        if ($sessions === []) {
            $this->error('At least one --session is required. Use --session=<id> to specify session IDs.');

            return self::FAILURE;
        }

        $max = (int) $this->option('max');
        if ($max < 1) {
            $this->error('--max must be at least 1');

            return self::FAILURE;
        }

        $resolvedSessions = [];
        foreach ($sessions as $session) {
            $resolved = $bus->resolveSessionId($session);
            if ($resolved === null) {
                $this->warn("Session {$session} is not on the bus, skipping");
                continue;
            }
            $resolvedSessions[] = $resolved;
        }

        if ($resolvedSessions === []) {
            $this->warn('No valid sessions provided');

            return self::SUCCESS;
        }

        $consumingFrom = implode(', ', $resolvedSessions);
        $this->line("Consuming up to {$max} message(s) from: {$consumingFrom}");

        $delivery = new InboxDelivery($bus);
        $totalConsumed = 0;

        foreach ($resolvedSessions as $sessionId) {
            $this->line("Checking inbox for session: {$sessionId}");
            
            $messages = $bus->pullInbox($sessionId, $max - $totalConsumed);
            
            foreach ($messages as $msgData) {
                $subject = $msgData['subject'];
                $body = $msgData['body'];
                $message = $msgData['message'];
                
                // Process the message using the same handler as sidecar
                $delivery->handle($message, function($subject, $body) use ($sessionId, &$totalConsumed) {
                    $this->line("Consumed message from {$sessionId}");
                    $this->line("Subject: {$subject}");
                    $this->line("Body: {$body}");
                    $totalConsumed++;
                });
            }
        }

        $this->info("Consumed {$totalConsumed} message(s) total");

        return self::SUCCESS;
    }
}
