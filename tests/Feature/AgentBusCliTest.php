<?php

use App\Bus\NatsBus;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

describe('emit', function () {
    it('exits 1 when --type is missing', function () {
        $result = agentBusCli(
            ['emit', '--payload={"tool":"composer"}'],
            ['NATS_URL' => 'nats://127.0.0.1:1'],
        );

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain('Missing --type.');
    });

    it('exits 0 with one stderr line when the broker is down', function () {
        $result = agentBusCli(
            ['emit', '--type=toolCall', '--payload={"tool":"composer"}', '--session=cli-emit'],
            ['NATS_URL' => 'nats://127.0.0.1:1'],
        );

        expect($result->exitCode())->toBe(0)
            ->and($result->output())->toBe('')
            ->and(stderrLines($result))->toHaveCount(1)
            ->and($result->errorOutput())
            ->not->toContain('Stack trace')
            ->not->toContain('Illuminate\\')
            ->not->toContain('artisan');
    });

    it('publishes a JSON envelope to repo.{owner}.{name}.{type}', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $type = 'cliProve';
        $result = agentBusCli([
            'emit',
            '--type='.$type,
            '--payload={"tool":"composer"}',
            '--session=01a08ef9-2515-7460-89bd-5efc21f28642',
            '--agent-type=grok',
            '--model=grok-4.6',
        ]);

        expect($result->exitCode())->toBe(0)
            ->and($result->errorOutput())->toBe('');

        $printed = json_decode($result->output(), true);
        $subject = 'repo.the-shit.agent-bus.'.$type;

        expect($printed)
            ->toHaveKeys(['sessionId', 'agentType', 'model', 'repo', 'type', 'timestamp', 'payload'])
            ->and($printed['sessionId'])->toBe('01a08ef9-2515-7460-89bd-5efc21f28642')
            ->and($printed['agentType'])->toBe('grok')
            ->and($printed['model'])->toBe('grok-4.6')
            ->and($printed['repo'])->toBe('the-shit/agent-bus')
            ->and($printed['type'])->toBe($type)
            ->and($printed['timestamp'])->toMatch('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}Z$/')
            ->and($printed['payload'])->toBe(['tool' => 'composer'])
            ->and($bus->lastEnvelope($subject))->toBe($printed);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

describe('send', function () {
    it('exits 1 when the broker is down', function () {
        $result = agentBusCli(
            ['send', '--session=missing', '--payload={"text":"ping"}'],
            ['NATS_URL' => 'nats://127.0.0.1:1'],
        );

        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toBe('')
            ->and(stderrLines($result))->toHaveCount(1)
            ->and($result->errorOutput())
            ->not->toContain('Stack trace')
            ->not->toContain('Illuminate\\')
            ->not->toContain('artisan');
    });

    it('exits 1 when the session is missing from KV', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'cli-missing-'.bin2hex(random_bytes(4));
        $result = agentBusCli([
            'send',
            '--session='.$sessionId,
            '--payload={"text":"ping"}',
        ]);

        expect($result->exitCode())->toBe(1)
            ->and($result->errorOutput())->toContain("session {$sessionId} is not on the bus");

        $published = true;
        try {
            $bus->lastEnvelope('session.'.$sessionId.'.inbox');
        } catch (Throwable) {
            $published = false;
        }

        expect($published)->toBeFalse();
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

    it('publishes to session.{id}.inbox when KV has the session', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'cli-send-session';
        $bus->putSession($sessionId, json_encode(['sessionId' => $sessionId], JSON_THROW_ON_ERROR));

        $result = agentBusCli([
            'send',
            '--session='.$sessionId,
            '--payload={"text":"ping"}',
        ]);

        expect($result->exitCode())->toBe(0)
            ->and($result->errorOutput())->toBe('');

        $printed = json_decode($result->output(), true);

        expect($printed['sessionId'])->toBe($sessionId)
            ->and($printed['payload'])->toBe(['text' => 'ping'])
            ->and($bus->lastEnvelope('session.'.$sessionId.'.inbox'))->toBe($printed);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

describe('heartbeat and sessions', function () {
    it('puts presence JSON in KV', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'cli-heartbeat';
        $result = agentBusCli([
            'heartbeat',
            '--session='.$sessionId,
            '--agent-type=grok',
            '--model=grok-4.6',
        ]);

        expect($result->exitCode())->toBe(0);

        $printed = json_decode($result->output(), true);
        $stored = json_decode((string) $bus->getSession($sessionId), true);

        expect($printed['sessionId'])->toBe($sessionId)
            ->and($printed['repo'])->toBe('the-shit/agent-bus')
            ->and($stored)->toBe($printed);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

    it('lists session ids and prints one session from KV', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'cli-sessions-get';
        $json = json_encode(['sessionId' => $sessionId], JSON_THROW_ON_ERROR);
        $bus->putSession($sessionId, $json);

        $list = agentBusCli(['sessions']);
        $ids = json_decode($list->output(), true);

        expect($list->exitCode())->toBe(0)
            ->and($ids)->toContain($sessionId);

        $get = agentBusCli(['sessions', 'get', $sessionId]);

        expect($get->exitCode())->toBe(0)
            ->and(trim($get->output()))->toBe($json);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

it('loads composer autoload and does not boot artisan', function () {
    $bin = file_get_contents(base_path('bin/agent-bus'));

    expect($bin)
        ->toContain("require __DIR__.'/../vendor/autoload.php'")
        ->not->toContain('bootstrap/app.php')
        ->not->toContain('artisan');
});

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function agentBusCli(array $arguments, array $environment = []): ProcessResult
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

function stderrLines(ProcessResult $result): array
{
    return array_values(array_filter(
        explode("\n", trim($result->errorOutput())),
        fn (string $line): bool => $line !== '',
    ));
}
