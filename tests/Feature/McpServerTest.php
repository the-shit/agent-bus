<?php

use App\Bus\NatsBus;
use App\Mcp\Server;
use Illuminate\Support\Str;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

/**
 * Feed newline-delimited MCP/JSON-RPC requests to the server and collect the
 * decoded responses written to stdout.
 *
 * @return list<array<string, mixed>>
 */
function runMcp(string ...$lines): array
{
    $input = fopen('php://temp', 'w+');
    $output = fopen('php://temp', 'w+');

    foreach ($lines as $line) {
        fwrite($input, $line."\n");
    }

    rewind($input);
    app(Server::class)->run($input, $output, maxRequests: count($lines));
    rewind($output);

    $responses = [];

    while (($line = fgets($output)) !== false) {
        if (trim($line) !== '') {
            $responses[] = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        }
    }

    return $responses;
}

/**
 * @return array<string, mixed>|null
 */
function mcpSingle(string $json): ?array
{
    $responses = runMcp($json);

    return $responses[0] ?? null;
}

it('serves initialize with protocol version and tools capability', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":1,"method":"initialize","params":{}}');

    expect($response)
        ->toHaveKey('id', 1)
        ->and($response['result'])
        ->toHaveKey('protocolVersion', Server::PROTOCOL_VERSION)
        ->and($response['result']['capabilities']['tools'])
        ->toBe(['listChanged' => false])
        ->and($response['result']['serverInfo']['name'])
        ->toBe('agent-bus');
});

it('lists the three tools', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":1,"method":"tools/list"}');

    $tools = $response['result']['tools'] ?? [];

    expect(array_column($tools, 'name'))->toBe(['list_sessions', 'get_session', 'send']);
});

it('rejects method not found with a JSON-RPC error', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":7,"method":"bogus/method"}');

    expect($response)
        ->toHaveKey('id', 7)
        ->and($response['error']['code'])
        ->toBe(-32601)
        ->and($response)
        ->not->toHaveKey('result');
});

it('rejects malformed JSON with a parse error', function () {
    $response = mcpSingle('this is not json');

    expect($response['error']['code'])->toBe(-32700);
});

it('does not emit a response for notifications', function () {
    $responses = runMcp('{"jsonrpc":"2.0","method":"notifications/initialized"}');

    expect($responses)->toBeEmpty();
});

it('returns an error result for an unknown tool', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":3,"method":"tools/call","params":{"name":"nope","arguments":{}}}');

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])
        ->toContain('nope');
});

it('returns an error result when get_session misses', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":4,"method":"tools/call","params":{"name":"get_session","arguments":{"sessionId":"missing-'.Str::uuid().'"}}}');

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])
        ->toContain('not on the bus');
});

it('reads a session from KV through get_session', function () {
    $bus = app(NatsBus::class);
    $sessionId = 'mcp-'.Str::uuid();
    $json = json_encode(['sessionId' => $sessionId, 'repo' => 'the-shit/agent-bus'], JSON_THROW_ON_ERROR);
    $bus->putSession($sessionId, $json);

    $response = mcpSingle('{"jsonrpc":"2.0","id":5,"method":"tools/call","params":{"name":"get_session","arguments":{"sessionId":"'.$sessionId.'"}}}');

    expect($response['result']['isError'])->toBeFalse();

    $presence = json_decode($response['result']['content'][0]['text'], true, flags: JSON_THROW_ON_ERROR);

    expect($presence)
        ->toHaveKey('sessionId', $sessionId)
        ->toHaveKey('repo', 'the-shit/agent-bus');
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('fails closed when send targets a session that is not on the bus', function () {
    $response = mcpSingle('{"jsonrpc":"2.0","id":6,"method":"tools/call","params":{"name":"send","arguments":{"sessionId":"ghost-'.Str::uuid().'","payload":{"text":"ping"}}}}');

    expect($response['result']['isError'])->toBeTrue()
        ->and($response['result']['content'][0]['text'])
        ->toContain('not on the bus');
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('sends an inbox envelope to a live session and publishes it', function () {
    $bus = app(NatsBus::class);
    $sessionId = 'mcp-send-'.Str::uuid();
    $bus->putSession($sessionId, json_encode(['sessionId' => $sessionId, 'repo' => 'the-shit/agent-bus'], JSON_THROW_ON_ERROR));

    $response = mcpSingle('{"jsonrpc":"2.0","id":7,"method":"tools/call","params":{"name":"send","arguments":{"sessionId":"'.$sessionId.'","payload":{"tool":"prove","text":"ping"}}}}');

    expect($response['result']['isError'])->toBeFalse();

    $envelope = json_decode($response['result']['content'][0]['text'], true);

    expect($envelope)
        ->toHaveKey('type', 'inbox')
        ->toHaveKey('sessionId', $sessionId)
        ->and($envelope['payload'])
        ->toBe(['tool' => 'prove', 'text' => 'ping']);

    expect($bus->lastEnvelope('session.'.$sessionId.'.inbox'))->toBe($envelope);
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');
