<?php

namespace App\Commands;

use App\Bus\Cli;
use App\Bus\NatsBus;
use App\Coord\CoordBridge;
use App\Coord\PdoCoordQueue;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('coord-bridge
    {--session= : Bus session that does the work (it runs `listen`)}
    {--capabilities= : Comma-separated coord capabilities to claim; empty claims any you may run}
    {--lease=300 : coord lease in seconds}
    {--wait=600 : Seconds to wait for a done/failed receipt before failing the job; keep it above the listener exec timeout (default 540) + 10}
    {--max=0 : Stop after this many jobs (0 = no limit)}
    {--for=0 : Stop after this many seconds (0 = run forever)}')]
#[Description('Claim sbprod coord jobs and deliver them to one bus session by job id (LISTEN coord wakes it)')]
class CoordBridgeCommand extends Command
{
    public function handle(NatsBus $bus): int
    {
        $session = (string) $this->option('session');

        if ($session === '') {
            $this->error('Missing --session.');

            return self::FAILURE;
        }

        if (! $bus->isReachable()) {
            $this->error("NATS is not reachable at {$bus->host()}:{$bus->port()}. Check NATS_URL and the broker.");

            return self::FAILURE;
        }

        try {
            $coord = PdoCoordQueue::fromConfig((array) config('agent_bus.coord', []));
        } catch (Throwable $exception) {
            // PDO messages can echo the DSN; keep it to one generic line.
            $this->error('coord connection failed: '.($exception instanceof \PDOException ? 'check COORD_DB_* settings' : $exception->getMessage()));

            return self::FAILURE;
        }

        $warning = CoordBridge::misconfiguration((float) $this->option('wait'), Cli::execTimeout());

        if ($warning !== null) {
            $this->warn($warning);
        }

        $capabilities = array_values(array_filter(array_map('trim', explode(',', (string) $this->option('capabilities')))));

        $bridge = new CoordBridge(
            $coord,
            $bus,
            $session,
            $capabilities,
            (int) $this->option('lease'),
            (float) $this->option('wait'),
            fn (string $line) => $this->line($line),
        );

        $handled = $bridge->run((int) $this->option('max'), (float) $this->option('for'));
        $this->info("handled {$handled} job(s)");

        return self::SUCCESS;
    }
}
