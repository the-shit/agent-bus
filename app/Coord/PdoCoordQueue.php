<?php

namespace App\Coord;

use PDO;
use Pdo\Pgsql;
use RuntimeException;

/**
 * coord over a direct Postgres connection through Supavisor session mode
 * (Odin 127.0.0.1:15442). Session mode is required: transaction mode cannot
 * hold a LISTEN. Every state change goes through the coord job functions.
 */
final class PdoCoordQueue implements CoordQueue
{
    private bool $listening = false;

    public function __construct(private readonly PDO $pdo)
    {
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }

    /**
     * Build from config('agent_bus.coord'). The password is read from a 0600
     * file and never logged.
     *
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(array $config): self
    {
        $user = (string) ($config['user'] ?? '');
        $passwordFile = (string) ($config['password_file'] ?? '');

        if ($user === '' || $passwordFile === '') {
            throw new RuntimeException('Set COORD_DB_USER and COORD_DB_PASSWORD_FILE to use coord.');
        }

        if (! is_readable($passwordFile)) {
            throw new RuntimeException('COORD_DB_PASSWORD_FILE is not readable.');
        }

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 15442),
            (string) ($config['database'] ?? 'postgres'),
        );

        $password = trim((string) file_get_contents($passwordFile));

        // PHP 8.4+: PDO::connect returns Pdo\Pgsql, which has getNotify().
        return new self(method_exists(PDO::class, 'connect')
            ? PDO::connect($dsn, $user, $password)
            : new PDO($dsn, $user, $password));
    }

    public function claim(array $capabilities, int $leaseSeconds): ?array
    {
        $statement = $this->pdo->prepare(
            'SELECT id::text AS id, capability, payload::text AS payload, request_id::text AS request_id
               FROM coord.claim_job(CAST(:caps AS text[]), make_interval(secs => :lease))'
        );
        $statement->execute([
            'caps' => $capabilities === [] ? null : self::textArray($capabilities),
            'lease' => max(30, $leaseSeconds),
        ]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        if (! is_array($row) || ($row['id'] ?? null) === null) {
            return null;
        }

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);

        return [
            'id' => (string) $row['id'],
            'capability' => (string) ($row['capability'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
            'request_id' => isset($row['request_id']) ? (string) $row['request_id'] : null,
        ];
    }

    public function heartbeat(string $jobId, int $leaseSeconds): void
    {
        $this->pdo->prepare('SELECT coord.heartbeat_job(CAST(:job AS uuid), make_interval(secs => :lease))')
            ->execute(['job' => $jobId, 'lease' => max(30, $leaseSeconds)]);
    }

    public function complete(string $jobId, array $result): void
    {
        $this->pdo->prepare('SELECT coord.complete_job(CAST(:job AS uuid), CAST(:result AS jsonb))')
            ->execute(['job' => $jobId, 'result' => json_encode($result === [] ? new \stdClass : $result, JSON_THROW_ON_ERROR)]);
    }

    public function fail(string $jobId, string $error, bool $retry = true): string
    {
        $statement = $this->pdo->prepare('SELECT coord.fail_job(CAST(:job AS uuid), :error, :retry)');
        $statement->bindValue('job', $jobId);
        $statement->bindValue('error', mb_substr($error, 0, 500));
        $statement->bindValue('retry', $retry, PDO::PARAM_BOOL);
        $statement->execute();

        return (string) $statement->fetchColumn();
    }

    public function waitForNotification(int $timeoutMs): ?array
    {
        if (! $this->listening) {
            $this->pdo->exec('LISTEN coord');
            $this->listening = true;
        }

        /** @var Pgsql|PDO $pdo */
        $pdo = $this->pdo;
        $notify = method_exists($pdo, 'getNotify')
            ? $pdo->getNotify(PDO::FETCH_ASSOC, max(0, $timeoutMs))
            : $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, max(0, $timeoutMs));

        if (! is_array($notify)) {
            return null;
        }

        $decoded = json_decode((string) ($notify['payload'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    /** @param  list<string>  $values */
    private static function textArray(array $values): string
    {
        return '{'.implode(',', array_map(
            fn (string $value): string => '"'.addcslashes($value, '"\\').'"',
            $values,
        )).'}';
    }
}
