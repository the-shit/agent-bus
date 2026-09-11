<?php

namespace App\Bus;

use Illuminate\Support\Facades\Process;
use RuntimeException;

class Herdr
{
    /**
     * @return list<array<string, mixed>>
     */
    public function listAgents(): array
    {
        $result = Process::timeout(5)->run([$this->binary(), 'agent', 'list']);

        if ($result->failed()) {
            throw new RuntimeException('herdr agent list failed: '.$this->oneLine($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()));
        }

        return (new SessionPaneMap)->agentsFromListJson($result->output());
    }

    public function prompt(string $paneId, string $json): void
    {
        $result = Process::timeout(30)->run([
            $this->binary(),
            'agent',
            'prompt',
            $paneId,
            '--',
            $json,
        ]);

        if ($result->failed()) {
            throw new RuntimeException('herdr agent prompt failed: '.$this->oneLine($result->errorOutput() !== '' ? $result->errorOutput() : $result->output()));
        }
    }

    private function binary(): string
    {
        $binary = config('agent_bus.herdr.binary', 'herdr');

        return is_string($binary) && $binary !== '' ? $binary : 'herdr';
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'herdr command failed.';
    }
}
