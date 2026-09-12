<?php

namespace App\Bus;

use Basis\Nats\Client;
use Basis\Nats\Configuration;
use InvalidArgumentException;
use JsonException;
use Throwable;

class Cli
{
    /**
     * Verbs that must never boot the framework. Hooks fire these on every tool
     * call, so bin/agent-bus dispatches them straight to this class.
     *
     * @var list<string>
     */
    public const HOT_VERBS = [
        'emit',
        'send',
        'heartbeat',
        'session-end',
        'sessions',
        'hook',
        'opencode',
    ];

    private ?Client $client = null;

    /**
     * @param  list<string>  $argv
     */
    public function run(array $argv): int
    {
        try {
            return $this->dispatch($argv);
        } catch (InvalidArgumentException $exception) {
            fwrite(STDERR, $exception->getMessage()."\n");

            return 1;
        }
    }

    public static function repoFromRemote(string $url): string
    {
        $url = trim($url);

        if ($url === '') {
            return 'unknown/unknown';
        }

        if (str_starts_with($url, 'git@')) {
            $url = preg_replace('#^git@([^:]+):#', 'https://$1/', $url) ?? $url;
        } elseif (str_starts_with($url, 'ssh://git@')) {
            $url = preg_replace('#^ssh://git@#', 'https://', $url) ?? $url;
        }

        $path = parse_url($url, PHP_URL_PATH);
        $path = is_string($path) ? $path : $url;
        $path = preg_replace('#\.git$#', '', $path) ?? $path;
        $parts = array_values(array_filter(explode('/', $path), fn (string $part): bool => $part !== ''));

        if (count($parts) < 2) {
            return 'unknown/unknown';
        }

        return $parts[count($parts) - 2].'/'.$parts[count($parts) - 1];
    }

    /**
     * @param  list<string>  $argv
     */
    private function dispatch(array $argv): int
    {
        [$positionals, $options] = $this->parse($argv);
        $command = $positionals[0] ?? '';

        return match ($command) {
            'emit' => $this->emit($options),
            'send' => $this->send($options),
            'heartbeat' => $this->heartbeat($options),
            'session-end' => $this->sessionEnd($options),
            'sessions' => $this->sessions($positionals),
            'hook' => $this->fromStdin(GrokHook::class),
            'opencode' => $this->fromStdin(OpenCodeCapture::class),
            default => $this->usage(),
        };
    }

    /**
     * @param  array<string, string>  $options
     */
    private function emit(array $options): int
    {
        $envelope = $this->envelope($options, requireSession: false);

        try {
            $this->publish($this->repoSubject($envelope), $envelope);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 0;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function send(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $payload = $this->jsonObject($options['payload'] ?? '', 'payload');
        $envelope = $this->envelope([
            ...$options,
            'type' => $options['type'] ?? 'inbox',
            'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
        ], requireSession: true);

        try {
            if ($this->session($sessionId) === null) {
                fwrite(STDERR, "session {$sessionId} is not on the bus\n");

                return 1;
            }

            $this->publish('session.'.$sessionId.'.inbox', $envelope);
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function heartbeat(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $presence = [
            'sessionId' => $sessionId,
            'agentType' => $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            'model' => $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            'repo' => $this->repo(),
            'timestamp' => $now,
            'lastSeen' => $now,
        ];

        try {
            $this->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($presence, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @param  list<string>  $positionals
     */
    private function sessions(array $positionals): int
    {
        $subcommand = $positionals[1] ?? 'list';

        try {
            return match ($subcommand) {
                'list' => $this->listSessions(),
                'get' => $this->getSession($positionals[2] ?? ''),
                default => $this->usage(),
            };
        } catch (InvalidArgumentException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }
    }

    private function listSessions(): int
    {
        $ids = [];

        foreach ($this->client()->getApi()->getBucket($this->kvBucket())->getAll() as $entry) {
            if ($entry->key !== '' && is_string($entry->value) && $entry->value !== '') {
                $ids[$entry->key] = $entry->key;
            }
        }

        echo json_encode(array_values($ids), JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    private function getSession(string $id): int
    {
        if ($id === '') {
            throw new InvalidArgumentException('Missing session id.');
        }

        $value = $this->session($id);

        if ($value === null) {
            fwrite(STDERR, "session {$id} is not on the bus\n");

            return 1;
        }

        echo $value."\n";

        return 0;
    }

    /**
     * @param  array<string, mixed>  $envelope
     */
    private function publish(string $subject, array $envelope): void
    {
        $body = json_encode($envelope, JSON_THROW_ON_ERROR);

        $this->client()->getApi()->getStream($this->streamName())->publish($subject, $body);
    }

    private function session(string $id): ?string
    {
        $value = $this->client()->getApi()->getBucket($this->kvBucket())->get($id);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function putSession(string $id, string $json): void
    {
        $this->client()->getApi()->getBucket($this->kvBucket())->put($id, $json);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function sessionEnd(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);

        try {
            $this->deleteSession($sessionId);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        return 0;
    }

    private function deleteSession(string $id): void
    {
        $this->client()->getApi()->getBucket($this->kvBucket())->delete($id);
    }

    /**
     * @param  class-string<GrokHook|OpenCodeCapture>  $mapper
     */
    private function fromStdin(string $mapper): int
    {
        try {
            $raw = stream_get_contents(STDIN);

            if (! is_string($raw) || trim($raw) === '') {
                return 0;
            }

            try {
                $event = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                return 0;
            }

            if (! is_array($event)) {
                return 0;
            }

            foreach ($mapper::actions($event) as $action) {
                $this->perform($action);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");
        }

        return 0;
    }

    private function perform(CaptureAction $action): void
    {
        $options = [
            'session' => $action->sessionId,
            'agent-type' => $action->agentType,
            'type' => $action->type !== '' ? $action->type : 'capture',
            'payload' => json_encode($action->payload, JSON_THROW_ON_ERROR),
        ];

        if ($action->model !== '') {
            $options['model'] = $action->model;
        }

        try {
            match ($action->verb) {
                'emit' => $this->publishQuiet($options),
                'heartbeat' => $this->heartbeatQuiet($options),
                'sessionEnd' => $this->deleteSession($action->sessionId),
                default => null,
            };
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");
        }
    }

    /**
     * @param  array<string, string>  $options
     */
    private function publishQuiet(array $options): void
    {
        $envelope = $this->envelope($options, requireSession: false);
        $this->publish($this->repoSubject($envelope), $envelope);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function heartbeatQuiet(array $options): void
    {
        $sessionId = $this->sessionId($options, required: true);
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $presence = [
            'sessionId' => $sessionId,
            'agentType' => $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            'model' => $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            'repo' => $this->repo(),
            'timestamp' => $now,
            'lastSeen' => $now,
        ];

        $this->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $options
     * @return array{sessionId: string, agentType: string, model: string, repo: string, type: string, timestamp: string, payload: array<string, mixed>}
     */
    private function envelope(array $options, bool $requireSession): array
    {
        $type = $options['type'] ?? '';

        if ($type === '') {
            throw new InvalidArgumentException('Missing --type.');
        }

        $payloadJson = $options['payload'] ?? '{}';
        if ($payloadJson === '') {
            $payloadJson = '{}';
        }

        return [
            'sessionId' => $this->sessionId($options, $requireSession),
            'agentType' => $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            'model' => $this->option($options, 'model', 'AGENT_BUS_MODEL'),
            'repo' => $this->repo(),
            'type' => $type,
            'timestamp' => gmdate('Y-m-d\TH:i:s\Z'),
            'payload' => $this->jsonObject($payloadJson, 'payload'),
        ];
    }

    /**
     * @param  array{repo: string, type: string}  $envelope
     */
    private function repoSubject(array $envelope): string
    {
        [$owner, $name] = explode('/', $envelope['repo'], 2) + [1 => 'unknown'];

        return 'repo.'.$owner.'.'.$name.'.'.$envelope['type'];
    }

    /**
     * @param  array<string, string>  $options
     */
    private function sessionId(array $options, bool $required): string
    {
        $sessionId = $this->option($options, 'session', 'AGENT_BUS_SESSION_ID');

        if ($required && $sessionId === '') {
            throw new InvalidArgumentException('Missing --session (or AGENT_BUS_SESSION_ID).');
        }

        return $sessionId;
    }

    /**
     * @param  array<string, string>  $options
     */
    private function option(array $options, string $name, string $envName, string $default = ''): string
    {
        if (isset($options[$name]) && $options[$name] !== '') {
            return $options[$name];
        }

        $fromEnv = getenv($envName);

        return is_string($fromEnv) && $fromEnv !== '' ? $fromEnv : $default;
    }

    private function jsonObject(string $json, string $label): array
    {
        try {
            $decoded = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw new InvalidArgumentException("{$label} is not JSON.");
        }

        if (! is_array($decoded)) {
            throw new InvalidArgumentException("{$label} must be a JSON object.");
        }

        return $decoded;
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

        if (! is_string($stdout)) {
            return 'unknown/unknown';
        }

        return self::repoFromRemote($stdout);
    }

    private function client(): Client
    {
        return $this->client ??= new Client($this->configuration());
    }

    private function configuration(): Configuration
    {
        $parts = NatsUrl::parse(NatsUrl::fromEnvironment());

        return new Configuration(
            host: $parts['host'],
            port: $parts['port'],
            user: $parts['user'],
            pass: $parts['pass'],
            reconnect: false,
            timeout: $this->connectTimeout(),
            maxReconnectAttempts: 0,
        );
    }

    /**
     * Loopback needs milliseconds; a tailnet broker needs room. AGENT_BUS_CONNECT_TIMEOUT overrides.
     */
    private function connectTimeout(): float
    {
        $raw = getenv('AGENT_BUS_CONNECT_TIMEOUT');
        $timeout = is_string($raw) ? (float) $raw : 0.0;

        return $timeout > 0 ? $timeout : 0.25;
    }

    private function streamName(): string
    {
        $stream = getenv('AGENT_BUS_STREAM');

        return is_string($stream) && $stream !== '' ? $stream : 'AGENT_BUS';
    }

    private function kvBucket(): string
    {
        $bucket = getenv('AGENT_BUS_KV_BUCKET');

        return is_string($bucket) && $bucket !== '' ? $bucket : 'sessions';
    }

    /**
     * @param  list<string>  $argv
     * @return array{0: list<string>, 1: array<string, string>}
     */
    private function parse(array $argv): array
    {
        array_shift($argv);

        $positionals = [];
        $options = [];
        $count = count($argv);

        for ($i = 0; $i < $count; $i++) {
            $arg = $argv[$i];

            if (! str_starts_with($arg, '--')) {
                $positionals[] = $arg;

                continue;
            }

            $body = substr($arg, 2);

            if (str_contains($body, '=')) {
                [$key, $value] = explode('=', $body, 2);
                $options[$key] = $value;

                continue;
            }

            $next = $argv[$i + 1] ?? null;

            if (is_string($next) && ! str_starts_with($next, '--')) {
                $options[$body] = $next;
                $i++;

                continue;
            }

            $options[$body] = '1';
        }

        return [$positionals, $options];
    }

    private function usage(): int
    {
        fwrite(STDERR, <<<'TXT'
Usage:
  bin/agent-bus emit --type=<type> [--payload=<json>] [--session=<id>] [--agent-type=<name>] [--model=<name>]
  bin/agent-bus send --session=<id> --payload=<json>
  bin/agent-bus heartbeat --session=<id>
  bin/agent-bus session-end --session=<id>
  bin/agent-bus sessions
  bin/agent-bus sessions get <id>
  bin/agent-bus hook
  bin/agent-bus opencode

Cold path (boots the framework):
  bin/agent-bus provision
  bin/agent-bus sidecar [--once]
TXT."\n");

        return 1;
    }

    private function oneLine(string $message): string
    {
        $line = trim(preg_replace('/\s+/', ' ', $message) ?? $message);

        return $line !== '' ? $line : 'NATS broker is down.';
    }
}
