<?php

use App\Bus\NatsBus;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

it('lists spotify.> among stream subjects so music envelopes board', function () {
    expect(app(NatsBus::class)->subjects())->toContain('spotify.>');
});

it('exits 0 with one stderr line when the music connector cannot reach the spotify broker', function () {
    $file = musicIsolatedJsonl();

    try {
        $result = musicConnectorCli(
            ['music', '--once', '--file='.$file],
            ['NATS_URL' => 'nats://127.0.0.1:1'],
        );
    } finally {
        musicCleanupJsonl($file);
    }

    expect($result->exitCode())->toBe(0)
        ->and($result->output())->toBe('')
        ->and(musicConnectorStderrLines($result))->toHaveCount(1)
        ->and($result->errorOutput())
        ->not->toContain('Stack trace')
        ->not->toContain('Illuminate\\')
        ->not->toContain('artisan');
});

it('boards one music JSONL line onto spotify.> as envelope v2', function () {
    $bus = app(NatsBus::class);
    $bus->provision();

    $file = musicIsolatedJsonl();

    try {
        $result = musicConnectorCli(['music', '--once', '--file='.$file]);
    } finally {
        musicCleanupJsonl($file);
    }

    expect($result->exitCode())->toBe(0)
        ->and($result->errorOutput())->toBe('');

    $envelope = $bus->lastEnvelope('spotify.track.changed');

    expect($envelope)
        ->toHaveKeys(['v', 'agentType', 'repo', 'type', 'timestamp', 'payload'])
        ->and($envelope['v'])->toBe(2)
        ->and($envelope['agentType'])->toBe('music')
        ->and($envelope['sessionId'])->toBe('')
        ->and($envelope['repo'])->toBe('the-shit/music')
        ->and($envelope['type'])->toBe('track.changed')
        ->and($envelope['payload']['track'])->toBe('Never Gonna Give You Up')
        ->and($envelope['payload']['artist'])->toBe('Rick Astley')
        ->and($envelope['payload']['uri'])->toBe('spotify:track:4PTG3Z6ehGkBFwjybzWkR8');
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function musicConnectorCli(array $arguments, array $environment = []): ProcessResult
{
    return Process::path(base_path())
        ->timeout(5)
        ->env($environment)
        ->run([
            PHP_BINARY,
            base_path('bin/agent-bus'),
            ...$arguments,
        ]);
}

function musicIsolatedJsonl(): string
{
    $dir = sys_get_temp_dir().'/agent-bus-music-'.bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $path = $dir.'/events.jsonl';
    copy(base_path('tests/Fixtures/music/spotify-track-changed.jsonl'), $path);

    return $path;
}

function musicCleanupJsonl(string $path): void
{
    @unlink($path);
    @unlink($path.'.offset');

    $dir = dirname($path);

    if (is_dir($dir)) {
        @rmdir($dir);
    }
}

function musicConnectorStderrLines(ProcessResult $result): array
{
    return array_values(array_filter(
        explode("\n", trim($result->errorOutput())),
        fn (string $line): bool => $line !== '',
    ));
}
