<?php

use Illuminate\Console\Scheduling\ScheduleFinishCommand;
use Illuminate\Console\Scheduling\ScheduleListCommand;
use Illuminate\Console\Scheduling\ScheduleRunCommand;
use Illuminate\Foundation\Console\VendorPublishCommand;
use LaravelZero\Framework\Commands\StubPublishCommand;
use NunoMaduro\LaravelConsoleSummary\SummaryCommand;
use Symfony\Component\Console\Command\DumpCompletionCommand;
use Symfony\Component\Console\Command\HelpCommand;

return [

    /*
    | Running `agent-bus` with no verb lists the cold-path commands. The hot
    | verbs (emit, send, heartbeat, session-end, sessions, hook, opencode)
    | never reach this kernel; bin/agent-bus dispatches them before boot.
    */

    'default' => SummaryCommand::class,

    'paths' => [app_path('Commands')],

    'add' => [
        //
    ],

    'hidden' => [
        SummaryCommand::class,
        DumpCompletionCommand::class,
        HelpCommand::class,
        ScheduleRunCommand::class,
        ScheduleListCommand::class,
        ScheduleFinishCommand::class,
        VendorPublishCommand::class,
        StubPublishCommand::class,
    ],

    'remove' => [
        //
    ],

];
