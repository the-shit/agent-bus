<?php

/**
 * Runs a hot verb through bin/agent-bus and records every framework class the
 * process loaded. bin/agent-bus exits, so the check runs at shutdown.
 *
 * AGENT_BUS_PROBE_BIN  path to bin/agent-bus
 * AGENT_BUS_PROBE_OUT  file to write the JSON list of framework classes to
 * AGENT_BUS_PROBE_ARGV space separated argv for the binary
 */
register_shutdown_function(static function (): void {
    $framework = array_values(array_filter(
        get_declared_classes(),
        static fn (string $class): bool => str_starts_with($class, 'Illuminate\\')
            || str_starts_with($class, 'LaravelZero\\')
            || str_starts_with($class, 'Symfony\\Component\\Console\\'),
    ));

    file_put_contents((string) getenv('AGENT_BUS_PROBE_OUT'), json_encode($framework));
});

$argv = ['bin/agent-bus', ...explode(' ', (string) getenv('AGENT_BUS_PROBE_ARGV'))];
$argc = count($argv);

require (string) getenv('AGENT_BUS_PROBE_BIN');
