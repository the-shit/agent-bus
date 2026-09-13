<?php

use App\Bus\NatsBus;
use App\Mcp\Server;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

describe('MCP protocol', function () {
    it('answers initialize with the agent-bus serverInfo', function () {
        config(['app.version' => '9.9.9']);

        [$reply] = mcpServe(Mockery::mock(NatsBus::class), [mcpRequest('initialize', [], id: 1)]);

        expect($reply)
            ->toHaveKey('id', 1)
            ->and($reply['result']['protocolVersion'])->toBe('2024-11-05')
            ->and($reply['result']['capabilities']['tools'])->toBe(['listChanged' => false])
            ->and($reply['result']['serverInfo']['name'])->toBe('agent-bus')
            ->and($reply['result']['serverInfo']['version'])->toBe('9.9.9');
    });

    it('does not reply to the notifications/initialized notification', function () {
        $replies = mcpServe(Mockery::mock(NatsBus::class), [
            mcpRequest('initialize', [], id: 1),
            mcpRequest('notifications/initialized'),
            mcpRequest('tools/list', [], id: 2),
        ]);

        expect($replies)->toHaveCount(2)
            ->and($replies[0]['id'])->toBe(1)
            ->and($replies[1]['id'])->toBe(2);
    });

    it('lists the three bus tools', function () {
        [$reply] = mcpServe(Mockery::mock(NatsBus::class), [mcpRequest('tools/list', [], id: 2)]);

        $tools = $reply['result']['tools'];

        expect($tools)->toHaveCount(3)
            ->and(array_column($tools, 'name'))->toBe(['list_sessions', 'get_session', 'send'])
            ->and($tools[1]['inputSchema']['required'])->toBe(['sessionId'])
            ->and($tools[2]['inputSchema']['required'])->toBe(['sessionId', 'payload'])
            ->and($tools[2]['inputSchema']['properties']['payload']['type'])->toBe('object');
    });

    it('returns a JSON-RPC error for an unknown tool', function () {
        [$reply] = mcpServe(Mockery::mock(NatsBus::class), [
            mcpRequest('tools/call', ['name' => 'nope', 'arguments' => []], id: 3),
        ]);

        expect($reply['error']['code'])->toBe(-32602);
    });

    it('errors when send payload is not an object', function () {
        [$reply] = mcpServe(Mockery::mock(NatsBus::class), [
            mcpRequest('tools/call', ['name' => 'send', 'arguments' => ['sessionId' => 'x', 'payload' => 'ping']], id: 4),
        ]);

        expect($reply['error']['code'])->toBe(-32602);
    });
});

describe('list_sessions', function () {
    it('returns the KV presences filtered by repo', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'mcp-list-'.bin2hex(random_bytes(4));
        $repo = 'mcp-test/'.bin2hex(random_bytes(4));
        $presence = [
            'sessionId' => $sessionId,
            'agentType' => 'grok',
            'model' => 'grok-4.6',
            'repo' => $repo,
            'lastSeen' => '2026-09-11T06:00:00Z',
        ];
        $bus->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));

        [$reply] = mcpServe($bus, [
            mcpRequest('tools/call', ['name' => 'list_sessions', 'arguments' => ['repo' => $repo]], id: 5),
        ]);

        $listed = json_decode($reply['result']['content'][0]['text'], true);

        expect($reply['result']['isSuccessful'])->toBeTrue()
            ->and($listed)->toContain($presence);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

describe('get_session', function () {
    it('returns the presence stored in KV for a known id', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'mcp-get-'.bin2hex(random_bytes(4));
        $presence = [
            'sessionId' => $sessionId,
            'agentType' => 'opencode',
            'model' => '',
            'repo' => 'the-shit/agent-bus',
            'lastSeen' => '2026-09-11T06:00:00Z',
        ];
        $bus->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));

        [$reply] = mcpServe($bus, [
            mcpRequest('tools/call', ['name' => 'get_session', 'arguments' => ['sessionId' => $sessionId]], id: 6),
        ]);

        expect($reply['result']['isSuccessful'])->toBeTrue()
            ->and(json_decode($reply['result']['content'][0]['text'], true))->toBe($presence);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

    it('returns an error result for an unknown id', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $unknown = 'mcp-unknown-'.bin2hex(random_bytes(4));

        [$reply] = mcpServe($bus, [
            mcpRequest('tools/call', ['name' => 'get_session', 'arguments' => ['sessionId' => $unknown]], id: 7),
        ]);

        expect($reply['result']['isSuccessful'])->toBeFalse()
            ->and($reply['result']['content'][0]['text'])->toBe("session {$unknown} is not on the bus");
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

describe('send', function () {
    it('publishes the envelope to session.{id}.inbox for a known session', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $sessionId = 'mcp-send-'.bin2hex(random_bytes(4));
        $bus->putSession($sessionId, json_encode(['sessionId' => $sessionId], JSON_THROW_ON_ERROR));

        [$reply] = mcpServe($bus, [
            mcpRequest('tools/call', [
                'name' => 'send',
                'arguments' => ['sessionId' => $sessionId, 'payload' => ['text' => 'ping']],
            ], id: 8),
        ]);

        $envelope = json_decode($reply['result']['content'][0]['text'], true);

        expect($reply['result']['isSuccessful'])->toBeTrue()
            ->and($envelope['sessionId'])->toBe($sessionId)
            ->and($envelope['type'])->toBe('inbox')
            ->and($envelope['payload'])->toBe(['text' => 'ping'])
            ->and($envelope['repo'])->toMatch('/^[^\/]+\/[^\/]+$/')
            ->and($bus->lastEnvelope('session.'.$sessionId.'.inbox'))->toBe($envelope);
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');

    it('fails closed without publishing for an unknown session', function () {
        $bus = app(NatsBus::class);
        $bus->provision();

        $unknown = 'mcp-send-unknown-'.bin2hex(random_bytes(4));

        [$reply] = mcpServe($bus, [
            mcpRequest('tools/call', [
                'name' => 'send',
                'arguments' => ['sessionId' => $unknown, 'payload' => ['text' => 'ping']],
            ], id: 9),
        ]);

        expect($reply['result']['isSuccessful'])->toBeFalse()
            ->and($reply['result']['content'][0]['text'])->toBe("session {$unknown} is not on the bus");

        $published = true;

        try {
            $bus->lastEnvelope('session.'.$unknown.'.inbox');
        } catch (Throwable) {
            $published = false;
        }

        expect($published)->toBeFalse();
    })->skip(fn () => ! app(NatsBus::class)->isReachable(), 'NATS broker is not running');
});

it('boots Laravel from bin/agent-bus-mcp', function () {
    $bin = file_get_contents(base_path('bin/agent-bus-mcp'));

    expect($bin)
        ->toContain("require __DIR__.'/../vendor/autoload.php'")
        ->toContain('bootstrap/app.php')
        ->toContain('Server::class');
});

/**
 * @param  array<string, mixed>  $params
 * @return array<string, mixed>
 */
function mcpRequest(string $method, array $params = [], ?int $id = null): array
{
    $request = ['jsonrpc' => '2.0', 'method' => $method, 'params' => $params];

    if ($id !== null) {
        $request['id'] = $id;
    }

    return $request;
}

/**
 * @param  list<array<string, mixed>>  $requests
 * @return list<array<string, mixed>>
 */
function mcpServe(NatsBus $bus, array $requests): array
{
    $input = fopen('php://temp', 'r+');

    if (! is_resource($input)) {
        throw new RuntimeException('Failed to open the MCP input stream.');
    }

    foreach ($requests as $request) {
        fwrite($input, json_encode($request, JSON_THROW_ON_ERROR)."\n");
    }

    rewind($input);
    $output = fopen('php://temp', 'r+');

    if (! is_resource($output)) {
        throw new RuntimeException('Failed to open the MCP output stream.');
    }

    (new Server($bus))->run($input, $output);
    rewind($output);

    $replies = [];

    while (($line = fgets($output)) !== false) {
        $reply = json_decode(trim($line), true);

        if (is_array($reply)) {
            $replies[] = $reply;
        }
    }

    fclose($input);
    fclose($output);

    return $replies;
}
