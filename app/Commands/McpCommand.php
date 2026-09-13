<?php

namespace App\Commands;

use App\Mcp\Server;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use LaravelZero\Framework\Commands\Command;

#[Signature('mcp')]
#[Description('Serve MCP stdio tools (list_sessions, get_session, send) on stdin/stdout')]
class McpCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(Server $server): int
    {
        $server->run();

        return self::SUCCESS;
    }
}
