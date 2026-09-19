<?php

namespace App\Commands;

use App\Bus\MusicCapture;
use App\Bus\NatsBus;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Throwable;

#[Signature('music {--once : Publish pending JSONL lines once and exit} {--file= : Path to the music JSONL}')]
#[Description('Tail the-shit/music JSONL and publish envelope v2 onto spotify.>')]
class MusicCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(NatsBus $bus): int
    {
        $once = (bool) $this->option('once');
        $eventsPath = $this->eventsPath();
        $offsetPath = $eventsPath.'.offset';
        $running = true;
        $warnedBroker = false;

        if (defined('SIGTERM') && defined('SIGINT')) {
            $this->trap([SIGTERM, SIGINT], function () use (&$running): void {
                $running = false;
            });
        }

        do {
            if (! $bus->isReachable()) {
                if (! $warnedBroker) {
                    fwrite(STDERR, "NATS broker is down.\n");
                    $warnedBroker = true;
                }

                if ($once) {
                    return self::SUCCESS;
                }

                Sleep::sleep(1);

                continue;
            }

            $warnedBroker = false;

            try {
                $bus->provision();
            } catch (Throwable $exception) {
                fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

                if ($once) {
                    return self::SUCCESS;
                }

                Sleep::sleep(1);

                continue;
            }

            if (! is_file($eventsPath)) {
                if ($once) {
                    return self::SUCCESS;
                }

                Sleep::sleep(1);

                continue;
            }

            $status = $this->drain($bus, $eventsPath, $offsetPath);

            if ($once || $status !== self::SUCCESS) {
                return $status;
            }

            Sleep::sleep(1);
        } while ($running);

        return self::SUCCESS;
    }

    private function drain(NatsBus $bus, string $eventsPath, string $offsetPath): int
    {
        $handle = fopen($eventsPath, 'r');

        if ($handle === false) {
            return self::SUCCESS;
        }

        try {
            $size = filesize($eventsPath);
            $offset = $this->readOffset($offsetPath);

            if (! is_int($size) || $offset > $size) {
                $offset = 0;
            }

            fseek($handle, $offset);

            while (true) {
                $line = fgets($handle);

                if ($line === false) {
                    break;
                }

                $newOffset = ftell($handle);

                if (! is_int($newOffset)) {
                    $newOffset = $offset + strlen($line);
                }

                $mapped = MusicCapture::fromLine($line);

                if ($mapped === null) {
                    $this->writeOffset($offsetPath, $newOffset);
                    $offset = $newOffset;

                    continue;
                }

                try {
                    $bus->publishEnvelope($mapped['subject'], $mapped['envelope']);
                } catch (Throwable $exception) {
                    fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

                    return self::SUCCESS;
                }

                $this->writeOffset($offsetPath, $newOffset);
                $offset = $newOffset;
                $this->line('published '.$mapped['subject']);
            }
        } finally {
            fclose($handle);
        }

        return self::SUCCESS;
    }

    private function eventsPath(): string
    {
        $file = $this->option('file');

        if (is_string($file) && $file !== '') {
            return $file;
        }

        $home = $_SERVER['HOME'] ?? getenv('HOME');
        $home = is_string($home) && $home !== '' ? $home : '';

        return $home.'/.config/spotify-cli/events.jsonl';
    }

    private function readOffset(string $path): int
    {
        if (! is_file($path)) {
            return 0;
        }

        $raw = file_get_contents($path);

        return is_string($raw) ? max(0, (int) $raw) : 0;
    }

    private function writeOffset(string $path, int $offset): void
    {
        $dir = dirname($path);

        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            return;
        }

        file_put_contents($path, (string) $offset);
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'NATS broker is down.';
    }
}
