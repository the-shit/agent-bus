<?php

namespace App\Mcp;

use App\Bus\Cli;
use App\Bus\NatsBus;
use InvalidArgumentException;
use JsonException;
use Throwable;

class Server
{
    public function __construct(private readonly NatsBus $bus) {}

    /**
     * Serve JSON-RPC 2.0 messages from the input stream until EOF (one JSON object per line).
     *
     * @param  resource|null  $input
     * @param  resource|null  $output
     */
    public function run($input = null, $output = null): int
    {
        $input = is_resource($input) ? $input : STDIN;
        $output = is_resource($output) ? $output : STDOUT;

        while (($line = fgets($input)) !== false) {
            $this->handle(rtrim($line, "\r\n"), $output);
        }

        return 0;
    }

    /**
     * @param  resource  $output
     */
    private function handle(string $line, $output): void
    {
        if ($line === '') {
            return;
        }

        try {
            $request = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->respond($output, null, error: [
                'code' => -32700,
                'message' => 'Parse error',
            ]);

            return;
        }

        if (! is_array($request) || ($request['jsonrpc'] ?? null) !== '2.0' || ! is_string($request['method'] ?? null)) {
            $this->respond($output, $request['id'] ?? null, error: [
                'code' => -32600,
                'message' => 'Invalid Request',
            ]);

            return;
        }

        $hasId = array_key_exists('id', $request);
        $id = $request['id'] ?? null;

        try {
            $result = $this->dispatch($request['method'], $request['params'] ?? []);
        } catch (InvalidArgumentException $exception) {
            if ($hasId) {
                $this->respond($output, $id, error: [
                    'code' => $exception->getCode() !== 0 ? $exception->getCode() : -32602,
                    'message' => $exception->getMessage(),
                ]);
            }

            return;
        } catch (Throwable $exception) {
            if ($hasId) {
                $this->respond($output, $id, error: [
                    'code' => -32603,
                    'message' => $exception->getMessage() !== '' ? $exception->getMessage() : 'Internal error',
                ]);
            }

            return;
        }

        if ($hasId) {
            $this->respond($output, $id, result: $result);
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    private function dispatch(string $method, mixed $params): ?array
    {
        return match ($method) {
            'initialize' => $this->initialize($params),
            'notifications/initialized' => null,
            'tools/list' => $this->toolsList(),
            'tools/call' => $this->toolsCall($params),
            default => throw new InvalidArgumentException('Method not found', -32601),
        };
    }

    /**
     * @return array<string, mixed>
     */
    private function initialize(mixed $params): array
    {
        return [
            'protocolVersion' => '2024-11-05',
            'capabilities' => [
                'tools' => [
                    'listChanged' => false,
                ],
            ],
            'serverInfo' => [
                'name' => 'agent-bus',
                'version' => (string) config('app.version', '0.1.0'),
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsList(): array
    {
        return [
            'tools' => [
                [
                    'name' => 'list_sessions',
                    'description' => 'List live sessions from the presence KV.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'repo' => ['type' => 'string'],
                        ],
                    ],
                ],
                [
                    'name' => 'get_session',
                    'description' => 'Read presence for one session.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'sessionId' => ['type' => 'string'],
                        ],
                        'required' => ['sessionId'],
                    ],
                ],
                [
                    'name' => 'send',
                    'description' => 'Send a JSON payload to a session inbox.',
                    'inputSchema' => [
                        'type' => 'object',
                        'properties' => [
                            'sessionId' => ['type' => 'string'],
                            'payload' => ['type' => 'object'],
                        ],
                        'required' => ['sessionId', 'payload'],
                    ],
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function toolsCall(mixed $params): array
    {
        $params = is_array($params) ? $params : [];
        $name = $params['name'] ?? null;

        if (! is_string($name) || $name === '') {
            throw new InvalidArgumentException('Missing tool name', -32602);
        }

        $arguments = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];

        return match ($name) {
            'list_sessions' => $this->listSessions($arguments),
            'get_session' => $this->getSession($arguments),
            'send' => $this->send($arguments),
            default => throw new InvalidArgumentException("Unknown tool {$name}", -32602),
        };
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function listSessions(array $arguments): array
    {
        $repo = is_string($arguments['repo'] ?? null) && $arguments['repo'] !== '' ? $arguments['repo'] : null;
        $presences = [];

        foreach ($this->bus->listSessionIds() as $id) {
            $value = $this->bus->getSession($id);

            if (! is_string($value) || $value === '') {
                continue;
            }

            $presence = json_decode($value, true);

            if (! is_array($presence)) {
                continue;
            }

            if ($repo !== null && ($presence['repo'] ?? null) !== $repo) {
                continue;
            }

            $presences[] = $presence;
        }

        return $this->textResult(json_encode($presences, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function getSession(array $arguments): array
    {
        $id = $this->requiredString($arguments, 'sessionId');
        $value = $this->bus->getSession($id);

        if (! is_string($value) || $value === '') {
            return $this->textResult("session {$id} is not on the bus", isSuccessful: false);
        }

        return $this->textResult($value);
    }

    /**
     * @param  array<string, mixed>  $arguments
     * @return array<string, mixed>
     */
    private function send(array $arguments): array
    {
        $id = $this->requiredString($arguments, 'sessionId');
        $payload = $arguments['payload'] ?? null;

        if (! is_array($payload)) {
            throw new InvalidArgumentException('payload must be a JSON object', -32602);
        }

        $value = $this->bus->getSession($id);

        if (! is_string($value) || $value === '') {
            return $this->textResult("session {$id} is not on the bus", isSuccessful: false);
        }

        $envelope = [
            'sessionId' => $id,
            'agentType' => $this->env('AGENT_BUS_AGENT_TYPE'),
            'model' => $this->env('AGENT_BUS_MODEL'),
            'repo' => $this->repo(),
            'type' => 'inbox',
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $payload,
        ];

        $this->bus->publishEnvelope('session.'.$id.'.inbox', $envelope);

        return $this->textResult(json_encode($envelope, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, mixed>  $arguments
     */
    private function requiredString(array $arguments, string $key): string
    {
        $value = $arguments[$key] ?? null;

        if (! is_string($value) || $value === '') {
            throw new InvalidArgumentException("Missing {$key}", -32602);
        }

        return $value;
    }

    private function env(string $name): string
    {
        $value = getenv($name);

        return is_string($value) && $value !== '' ? $value : '';
    }

    private function repo(): string
    {
        $proc = proc_open(
            ['git', 'remote', 'get-url', 'origin'],
            [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
            getcwd() ?: null,
        );

        if (! is_resource($proc)) {
            return 'unknown/unknown';
        }

        $stdout = stream_get_contents($pipes[1]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($proc);

        return is_string($stdout) ? Cli::repoFromRemote($stdout) : 'unknown/unknown';
    }

    /**
     * @param  array<string, mixed>  $result
     * @param  array<string, mixed>  $error
     * @param  resource  $output
     */
    private function respond($output, mixed $id, ?array $result = null, ?array $error = null): void
    {
        $message = ['jsonrpc' => '2.0', 'id' => $id];

        if ($error !== null) {
            $message['error'] = $error;
        } else {
            $message['result'] = $result;
        }

        fwrite($output, json_encode($message, JSON_THROW_ON_ERROR)."\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function textResult(string $text, bool $isSuccessful = true): array
    {
        return [
            'content' => [
                [
                    'type' => 'text',
                    'text' => $text,
                ],
            ],
            'isSuccessful' => $isSuccessful,
        ];
    }
}
