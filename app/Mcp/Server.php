<?php

namespace App\Mcp;

use App\Bus\NatsBus;
use JsonException;
use Throwable;

class Server
{
    public const PROTOCOL_VERSION = '2024-11-05';

    /**
     * @var array<string, array{name: string, inputSchema: array<string, mixed>}>
     */
    private const TOOLS = [
        'list_sessions' => [
            'name' => 'list_sessions',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['repo' => ['type' => 'string']],
            ],
        ],
        'get_session' => [
            'name' => 'get_session',
            'inputSchema' => [
                'type' => 'object',
                'properties' => ['sessionId' => ['type' => 'string']],
                'required' => ['sessionId'],
            ],
        ],
        'send' => [
            'name' => 'send',
            'inputSchema' => [
                'type' => 'object',
                'properties' => [
                    'sessionId' => ['type' => 'string'],
                    'payload' => ['type' => 'object'],
                ],
                'required' => ['sessionId', 'payload'],
            ],
        ],
    ];

    public function __construct(private readonly NatsBus $bus) {}

    /**
     * Read newline-delimited JSON-RPC 2.0 requests from stdin and write one
     * JSON response per line to stdout. Returns the count of requests handled so
     * tests can run a bounded number of exchanges.
     *
     * @param  resource|null  $input
     * @param  resource|null  $output
     */
    public function run($input = null, $output = null, int $maxRequests = 0): int
    {
        $input ??= STDIN;
        $output ??= STDOUT;
        $handled = 0;

        while (($line = fgets($input)) !== false) {
            if (trim($line) === '') {
                continue;
            }

            $this->handleLine($line, $output);
            $handled++;

            if ($maxRequests > 0 && $handled >= $maxRequests) {
                break;
            }
        }

        return $handled;
    }

    /**
     * @param  resource  $output
     */
    private function handleLine(string $line, $output): void
    {
        $request = json_decode($line, true);

        if (! is_array($request)) {
            $this->sendError($output, -32700, 'Parse error', null);

            return;
        }

        $id = $request['id'] ?? null;
        $method = $request['method'] ?? '';

        if (is_string($method) && str_starts_with($method, 'notifications/')) {
            return;
        }

        if (! is_string($method) || $method === '') {
            $this->sendError($output, -32600, 'Invalid Request', null);

            return;
        }

        if ($id === null) {
            return;
        }

        if (! in_array($method, ['initialize', 'ping', 'tools/list', 'tools/call'], true)) {
            $this->sendError($output, -32601, 'Method not found', $id);

            return;
        }

        $params = is_array($request['params'] ?? null) ? $request['params'] : [];

        try {
            $result = $this->route($method, $params);
        } catch (Throwable $exception) {
            $this->sendError($output, -32603, 'Internal error', $id);

            return;
        }

        $this->respond($output, $id, $result);
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function route(string $method, array $params): array
    {
        return match ($method) {
            'initialize' => [
                'protocolVersion' => self::PROTOCOL_VERSION,
                'capabilities' => ['tools' => ['listChanged' => false]],
                'serverInfo' => ['name' => 'agent-bus', 'version' => (string) config('app.version', '0.1.0')],
            ],
            'ping' => [],
            'tools/list' => ['tools' => array_values(self::TOOLS)],
            'tools/call' => $this->callTool($params),
            default => $this->errorResult(-32602, 'Unknown method '.$method),
        };
    }

    /**
     * @param  array<string, mixed>  $params
     * @return array<string, mixed>
     */
    private function callTool(array $params): array
    {
        $name = $params['name'] ?? '';
        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        if (! is_string($name) || ! array_key_exists($name, self::TOOLS)) {
            return $this->errorResult(-32602, 'Unknown tool '.$name);
        }

        return match ($name) {
            'list_sessions' => $this->listSessions($arguments),
            'get_session' => $this->getSession($arguments),
            'send' => $this->send($arguments),
            default => $this->errorResult(-32602, 'Unknown tool '.$name),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listSessions(array $arguments): array
    {
        $repo = is_string($arguments['repo'] ?? null) ? $arguments['repo'] : null;
        $sessions = [];

        foreach ($this->bus->listSessionIds() as $sessionId) {
            $presence = $this->decodePresence($this->bus->getSession($sessionId));

            if ($presence === null) {
                continue;
            }

            if ($repo !== null && ($presence['repo'] ?? '') !== $repo) {
                continue;
            }

            $sessions[] = $presence;
        }

        return $this->textResult($this->encode($sessions));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getSession(array $arguments): array
    {
        $sessionId = is_string($arguments['sessionId'] ?? null) ? $arguments['sessionId'] : '';

        if ($sessionId === '') {
            return $this->errorResult(-32602, 'sessionId is required');
        }

        $presence = $this->decodePresence($this->bus->getSession($sessionId));

        if ($presence === null) {
            return $this->textResult("session {$sessionId} is not on the bus", is_error: true);
        }

        return $this->textResult($this->encode($presence));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function send(array $arguments): array
    {
        $sessionId = is_string($arguments['sessionId'] ?? null) ? $arguments['sessionId'] : '';
        $payload = $arguments['payload'] ?? null;

        if ($sessionId === '') {
            return $this->errorResult(-32602, 'sessionId is required');
        }

        if (! is_array($payload)) {
            return $this->errorResult(-32602, 'payload must be a JSON object');
        }

        $presence = $this->decodePresence($this->bus->getSession($sessionId));

        if ($presence === null) {
            return $this->textResult("session {$sessionId} is not on the bus", is_error: true);
        }

        $envelope = [
            'sessionId' => $sessionId,
            'agentType' => $this->env('AGENT_BUS_AGENT_TYPE', ''),
            'model' => $this->env('AGENT_BUS_MODEL', ''),
            'repo' => is_string($presence['repo'] ?? null) ? $presence['repo'] : 'unknown/unknown',
            'type' => 'inbox',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $payload,
        ];

        $this->bus->publishEnvelope('session.'.$sessionId.'.inbox', $envelope);

        return $this->textResult($this->encode($envelope));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodePresence(?string $raw): ?array
    {
        if ($raw === null) {
            return null;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            return null;
        }

        return $decoded;
    }

    /**
     * @return array<string, mixed>
     */
    private function textResult(string $text, bool $is_error = false): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $text]],
            'isError' => $is_error,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function errorResult(int $code, string $message): array
    {
        return [
            'content' => [['type' => 'text', 'text' => $message]],
            'isError' => true,
        ];
    }

    private function env(string $key, string $default): string
    {
        $value = getenv($key);

        return $value === false || $value === '' ? $default : $value;
    }

    /**
     * @param  array<string, mixed>  $value
     */
    private function encode(array $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
    }

    /**
     * @param  resource  $output
     * @param  int|string|null  $id
     * @param  array<string, mixed>  $result
     */
    private function respond($output, $id, array $result): void
    {
        $this->write($output, ['jsonrpc' => '2.0', 'id' => $id, 'result' => $result]);
    }

    /**
     * @param  resource  $output
     * @param  int|string|null  $id
     */
    private function sendError($output, int $code, string $message, $id): void
    {
        $this->write($output, ['jsonrpc' => '2.0', 'id' => $id, 'error' => ['code' => $code, 'message' => $message]]);
    }

    /**
     * @param  resource  $output
     * @param  array<string, mixed>  $payload
     */
    private function write($output, array $payload): void
    {
        try {
            $line = json_encode($payload, JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE)."\n";
        } catch (JsonException $exception) {
            $line = '{"jsonrpc":"2.0","id":null,"error":{"code":-32603,"message":"Internal error"}}'."\n";
        }

        fwrite($output, $line);
    }
}
