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
            'sessions' => $this->sessions($positionals, $options),
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
        $this->requireAgentType($envelope);
        [$envelope['sessionId'], $aliases] = $this->canonicalize($envelope['sessionId'], $envelope['agentType']);
        $this->requireSubjectSafe($envelope['sessionId']);

        try {
            $this->registerPresence($envelope);
            $this->putAliases($aliases, $envelope['sessionId']);
            $this->publish($this->repoSubject($envelope), $envelope);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 0;
        }

        echo json_encode($envelope, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * Envelope v2: presence-bearing events must say who they are.
     *
     * @param  array{agentType: string}  $envelope
     */
    private function requireAgentType(array $envelope): void
    {
        if ($envelope['agentType'] === '') {
            throw new InvalidArgumentException('Missing --agent-type (or AGENT_BUS_AGENT_TYPE); envelope v2 requires agentType.');
        }
    }

    /**
     * Canonical ids are dot-free by design (issue #30): the KV backing
     * streams and the sidecar inbox filter are single-token, so a dotted id
     * can neither persist nor receive. Alternates are free-form — they reach
     * the bus only as hashed alias keys — but a resolved id that is not one
     * clean NATS token is a caller bug and dies loudly here.
     */
    private function requireSubjectSafe(string $sessionId): void
    {
        if ($sessionId !== '' && preg_match('/^[^\s.*>]+$/', $sessionId) !== 1) {
            throw new InvalidArgumentException(
                "session id [{$sessionId}] is not a single NATS token (dots, wildcards, whitespace) — see issue #30."
            );
        }
    }

    /**
     * Normalize a reporter-supplied id onto the canonical `{kind}:{id}` form.
     * Explicit operator input that the resolver cannot place is kept verbatim.
     *
     * @return array{0: string, 1: list<string>}
     */
    private function canonicalize(string $sessionId, string $agentType): array
    {
        if ($sessionId === '' || BusIdentity::isCanonical($sessionId)) {
            return [$sessionId, []];
        }

        $resolved = $agentType !== ''
            ? BusIdentity::resolve($agentType, ['sessionId' => $sessionId])
            : null;

        if ($resolved === null) {
            return [$sessionId, []];
        }

        return [$resolved, [$sessionId]];
    }

    /**
     * Auto-register presence for unknown ids before the first publish.
     * Implicit records are visibly marked; sessionStart upgrades to explicit.
     *
     * @param  array{sessionId: string, agentType: string, model: string, repo: string, type: string}  $envelope
     */
    private function registerPresence(array $envelope): void
    {
        if ($envelope['sessionId'] === '') {
            return;
        }

        $now = gmdate('Y-m-d\TH:i:s\Z');
        $explicit = $envelope['type'] === 'sessionStart';
        $existing = $this->session($envelope['sessionId']);
        $record = null;

        if (is_string($existing) && $existing !== '') {
            $decoded = json_decode($existing, true);
            $record = is_array($decoded) ? $decoded : null;
        }

        if ($record !== null) {
            // v1 rows have no `registered` marker. Treating that as explicit
            // froze degraded records forever — emit must repair them.
            $registered = $record['registered'] ?? null;
            $registered = is_string($registered) ? $registered : 'explicit';
            $degraded = (int) ($record['v'] ?? 1) !== 2
                || ! is_string($record['agentType'] ?? null)
                || $record['agentType'] === ''
                || ! is_string($record['sessionId'] ?? null)
                || $record['sessionId'] === '';

            if ($registered === 'explicit' && ! $degraded && ! $explicit) {
                return; // healthy explicit presence is managed by heartbeat
            }

            $record['v'] = 2;
            $record['sessionId'] = $envelope['sessionId'];
            $record['lastSeen'] = $now;
            $record['identity_quality'] = BusIdentity::quality($envelope['sessionId']);

            if ($explicit) {
                $record['registered'] = 'explicit';
            } elseif (! is_string($record['registered'] ?? null)) {
                $record['registered'] = 'implicit';
            }

            if (($record['agentType'] ?? '') === '' && $envelope['agentType'] !== '') {
                $record['agentType'] = $envelope['agentType'];
            }

            if (($record['model'] ?? '') === '' && $envelope['model'] !== '') {
                $record['model'] = $envelope['model'];
            }

            if (($record['repo'] ?? '') === '' && $envelope['repo'] !== '') {
                $record['repo'] = $envelope['repo'];
            }

            if (($record['host'] ?? '') === '') {
                $record['host'] = gethostname() ?: '';
            }

            if (! is_string($record['firstSeen'] ?? null) || $record['firstSeen'] === '') {
                $record['firstSeen'] = $now;
            }

            $this->putSession($envelope['sessionId'], json_encode($record, JSON_THROW_ON_ERROR));

            return;
        }

        $this->putSession($envelope['sessionId'], json_encode([
            'v' => 2,
            'sessionId' => $envelope['sessionId'],
            'agentType' => $envelope['agentType'],
            'model' => $envelope['model'],
            'host' => gethostname() ?: '',
            'repo' => $envelope['repo'],
            'registered' => $explicit ? 'explicit' : 'implicit',
            'identity_quality' => BusIdentity::quality($envelope['sessionId']),
            'firstSeen' => $now,
            'lastSeen' => $now,
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<string>  $aliases
     */
    private function putAliases(array $aliases, string $canonicalId): void
    {
        if ($aliases === [] || $canonicalId === '') {
            return;
        }

        $bucket = $this->client()->getApi()->getBucket($this->aliasBucketName());
        $now = gmdate('Y-m-d\TH:i:s\Z');

        foreach ($aliases as $alias) {
            $bucket->put($this->aliasKey($alias), json_encode([
                'alternate' => $alias,
                'canonical' => $canonicalId,
                'createdAt' => $now,
            ], JSON_THROW_ON_ERROR));
        }
    }

    /**
     * Canonical id whose presence is on the bus, resolving through the alias
     * map. Null when neither the id nor any alias of it is registered.
     */
    private function resolveOnBus(string $id): ?string
    {
        if ($this->session($id) !== null) {
            return $id;
        }

        $canonical = $this->aliasTarget($id);

        if ($canonical !== null && $this->session($canonical) !== null) {
            return $canonical;
        }

        return null;
    }

    private function aliasTarget(string $id): ?string
    {
        $value = $this->client()->getApi()->getBucket($this->aliasBucketName())->get($this->aliasKey($id));

        if (! is_string($value) || $value === '') {
            return null;
        }

        $decoded = json_decode($value, true);
        $canonical = is_array($decoded) ? ($decoded['canonical'] ?? null) : null;

        return is_string($canonical) && $canonical !== '' ? $canonical : null;
    }

    /**
     * Alternate ids contain dots and slashes that the KV backing stream's
     * single-token subject cannot hold (issue #30, bug a) — hash the key.
     */
    private function aliasKey(string $alternate): string
    {
        return hash('sha256', $alternate);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function send(array $options): int
    {
        $sessionId = $this->sessionId($options, required: true);
        $payload = $this->jsonObject($options['payload'] ?? '', 'payload');
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        [$canonical] = $this->canonicalize($sessionId, $agentType);

        try {
            $resolved = $this->resolveOnBus($canonical);

            if ($resolved === null) {
                fwrite(STDERR, "session {$sessionId} is not on the bus\n");

                return 1;
            }

            $this->requireSubjectSafe($resolved);

            $envelope = $this->envelope([
                ...$options,
                'session' => $resolved,
                'type' => $options['type'] ?? 'inbox',
                'payload' => json_encode($payload, JSON_THROW_ON_ERROR),
            ], requireSession: true);

            $this->publish('session.'.$resolved.'.inbox', $envelope);
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
        $agentType = $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE');
        [$canonical, $aliases] = $this->canonicalize($sessionId, $agentType);
        $this->requireSubjectSafe($canonical);

        try {
            $presence = $this->explicitPresence($canonical, $agentType, $this->option($options, 'model', 'AGENT_BUS_MODEL'));
            $this->putSession($canonical, json_encode($presence, JSON_THROW_ON_ERROR));
            $this->putAliases($aliases, $canonical);
        } catch (Throwable $exception) {
            fwrite(STDERR, $this->oneLine($exception->getMessage())."\n");

            return 1;
        }

        echo json_encode($presence, JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @return array<string, string|int>
     */
    private function explicitPresence(string $sessionId, string $agentType, string $model): array
    {
        $now = gmdate('Y-m-d\TH:i:s\Z');
        $firstSeen = $now;
        $existing = $this->session($sessionId);

        if (is_string($existing) && $existing !== '') {
            $record = json_decode($existing, true);
            $seen = is_array($record) ? ($record['firstSeen'] ?? null) : null;
            $firstSeen = is_string($seen) && $seen !== '' ? $seen : $now;
        }

        return [
            'v' => 2,
            'sessionId' => $sessionId,
            'agentType' => $agentType,
            'model' => $model,
            'host' => gethostname() ?: '',
            'repo' => $this->repo(),
            'registered' => 'explicit',
            'identity_quality' => BusIdentity::quality($sessionId),
            'timestamp' => $now,
            'firstSeen' => $firstSeen,
            'lastSeen' => $now,
        ];
    }

    /**
     * @param  list<string>  $positionals
     * @param  array<string, string>  $options
     */
    private function sessions(array $positionals, array $options): int
    {
        $subcommand = $positionals[1] ?? 'list';

        try {
            return match ($subcommand) {
                'list' => $this->listSessions($options),
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

    /**
     * Default: one KV scan of full presence records. `--ids` keeps the old
     * string-array shape for scripts that only want keys.
     *
     * @param  array<string, string>  $options
     */
    private function listSessions(array $options): int
    {
        $idsOnly = ($options['ids'] ?? '') === '1';
        $sessions = [];

        foreach ($this->client()->getApi()->getBucket($this->kvBucket())->getAll() as $entry) {
            if ($entry->key === '' || ! is_string($entry->value) || $entry->value === '') {
                continue;
            }

            if ($idsOnly) {
                $sessions[] = $entry->key;

                continue;
            }

            $sessions[] = $this->decodePresence($entry->key, $entry->value);
        }

        echo json_encode(array_values($sessions), JSON_THROW_ON_ERROR)."\n";

        return 0;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodePresence(string $key, string $json): array
    {
        $decoded = json_decode($json, true);

        if (! is_array($decoded)) {
            return ['sessionId' => $key];
        }

        if (! isset($decoded['sessionId']) || ! is_string($decoded['sessionId']) || $decoded['sessionId'] === '') {
            $decoded['sessionId'] = $key;
        }

        return $decoded;
    }

    private function getSession(string $id): int
    {
        if ($id === '') {
            throw new InvalidArgumentException('Missing session id.');
        }

        $resolved = $this->resolveOnBus($id);

        if ($resolved === null) {
            fwrite(STDERR, "session {$id} is not on the bus\n");

            return 1;
        }

        echo $this->session($resolved)."\n";

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
        [$canonical] = $this->canonicalize($sessionId, $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'));

        try {
            $resolved = $this->resolveOnBus($canonical) ?? $canonical;
            $this->requireSubjectSafe($resolved);
            $this->deleteSession($resolved);
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

            if ($action->verb !== 'sessionEnd') {
                $this->putAliases($action->aliases, $action->sessionId);
            }
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
        $this->requireAgentType($envelope);
        $this->requireSubjectSafe($envelope['sessionId']);
        $this->registerPresence($envelope);
        $this->publish($this->repoSubject($envelope), $envelope);
    }

    /**
     * @param  array<string, string>  $options
     */
    private function heartbeatQuiet(array $options): void
    {
        $sessionId = $this->sessionId($options, required: true);
        $presence = $this->explicitPresence(
            $sessionId,
            $this->option($options, 'agent-type', 'AGENT_BUS_AGENT_TYPE'),
            $this->option($options, 'model', 'AGENT_BUS_MODEL'),
        );

        $this->putSession($sessionId, json_encode($presence, JSON_THROW_ON_ERROR));
    }

    /**
     * @param  array<string, string>  $options
     * @return array{v: int, sessionId: string, agentType: string, model: string, repo: string, type: string, timestamp: string, payload: array<string, mixed>}
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
            'v' => 2,
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
        if ($this->client === null) {
            $client = new Client($this->configuration());

            // basis-nats has one timeout knob for connect AND request
            // dispatch. Connect inside the tight fail-open budget, then give
            // JetStream round trips (KV get/put acks) room — issue #30, bug c.
            $client->ping();
            $client->configuration->timeout = $this->dispatchTimeout();

            $this->client = $client;
        }

        return $this->client;
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

    /**
     * Request/response budget after the connection is up. AGENT_BUS_DISPATCH_TIMEOUT overrides.
     */
    private function dispatchTimeout(): float
    {
        $raw = getenv('AGENT_BUS_DISPATCH_TIMEOUT');
        $timeout = is_string($raw) ? (float) $raw : 0.0;

        return $timeout > 0 ? $timeout : 1.0;
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

    private function aliasBucketName(): string
    {
        $bucket = getenv('AGENT_BUS_ALIAS_BUCKET');

        return is_string($bucket) && $bucket !== '' ? $bucket : 'session_aliases';
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
  bin/agent-bus emit --type=<type> --agent-type=<name> [--payload=<json>] [--session=<id>] [--model=<name>]
  bin/agent-bus send --session=<id-or-alias> --payload=<json>
  bin/agent-bus heartbeat --session=<id> [--agent-type=<name>] [--model=<name>]
  bin/agent-bus session-end --session=<id-or-alias>
  bin/agent-bus sessions [--ids]
  bin/agent-bus sessions get <id-or-alias>
  bin/agent-bus hook
  bin/agent-bus opencode

Ids normalize to {kind}:{provider_id}; alternates resolve via the session_aliases KV bucket.

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
