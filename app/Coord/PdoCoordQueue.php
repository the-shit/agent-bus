<?php

namespace App\Coord;

use Closure;
use PDO;
use Pdo\Pgsql;
use PDOException;
use RuntimeException;

/**
 * coord over a direct Postgres connection through Supavisor session mode
 * (Odin 127.0.0.1:15442). Session mode is required: transaction mode cannot
 * hold a LISTEN. Every state change goes through the coord job functions.
 */
final class PdoCoordQueue implements CoordQueue
{
    private bool $listening = false;

    private ?PDO $connection = null;

    /** @var (Closure(): PDO)|null */
    private ?Closure $connect = null;

    /**
     * @param  PDO|(Closure(): PDO)  $connection  a live connection, or a
     *                                            connector the queue calls again after the connection drops
     */
    public function __construct(PDO|Closure $connection)
    {
        if ($connection instanceof PDO) {
            $this->connection = $connection;
            $connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        } else {
            $this->connect = $connection;
        }
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

        self::requirePrivate($passwordFile);

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;connect_timeout=5',
            (string) ($config['host'] ?? '127.0.0.1'),
            (int) ($config['port'] ?? 15442),
            (string) ($config['database'] ?? 'postgres'),
        );

        $queue = new self(static function () use ($dsn, $user, $passwordFile): PDO {
            // Read per connect so a rotated password is picked up on reconnect.
            $password = trim((string) file_get_contents($passwordFile));

            // PHP 8.4+: PDO::connect returns Pdo\Pgsql, which has getNotify().
            return method_exists(PDO::class, 'connect')
                ? PDO::connect($dsn, $user, $password)
                : new PDO($dsn, $user, $password);
        });
        $queue->pdo();

        return $queue;
    }

    /**
     * The coord password file must be private to its owner (0600 or 0400).
     */
    public static function requirePrivate(string $passwordFile): void
    {
        clearstatcache(true, $passwordFile);
        $mode = fileperms($passwordFile);

        if ($mode === false || ($mode & 0077) !== 0) {
            throw new RuntimeException(sprintf(
                'COORD_DB_PASSWORD_FILE must be mode 0600 (it is %s); run chmod 600 on it.',
                $mode === false ? 'unknown' : sprintf('%04o', $mode & 0777),
            ));
        }
    }

    /**
     * The live connection, reconnecting after a dropped one.
     */
    private function pdo(): PDO
    {
        if ($this->connection === null) {
            if ($this->connect === null) {
                throw new RuntimeException('coord connection was lost and cannot be reopened.');
            }

            $this->connection = ($this->connect)();
            $this->connection->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $this->listening = false;
        }

        return $this->connection;
    }

    /**
     * Run one coord call; on a database error drop the connection so the next
     * call reconnects (and re-LISTENs), then rethrow for the caller's retry.
     *
     * @template T
     *
     * @param  Closure(PDO): T  $call
     * @return T
     */
    private function call(Closure $call): mixed
    {
        try {
            return $call($this->pdo());
        } catch (PDOException $exception) {
            if ($this->connect !== null) {
                $this->connection = null;
                $this->listening = false;
            }

            throw $exception;
        }
    }

    public function claim(array $capabilities, int $leaseSeconds): ?array
    {
        $row = $this->call(function (PDO $pdo) use ($capabilities, $leaseSeconds): mixed {
            $statement = $pdo->prepare(
                'SELECT id::text AS id, capability, payload::text AS payload, request_id::text AS request_id, attempt
                   FROM coord.claim_job(CAST(:caps AS text[]), make_interval(secs => :lease))'
            );
            $statement->execute([
                'caps' => $capabilities === [] ? null : self::textArray($capabilities),
                'lease' => max(30, $leaseSeconds),
            ]);

            return $statement->fetch(PDO::FETCH_ASSOC);
        });

        if (! is_array($row) || ($row['id'] ?? null) === null) {
            return null;
        }

        return self::claimedJob($row);
    }

    /**
     * Map a claim_job row. A claimed job always has attempt >= 1 (claim_job
     * bumps it); anything else means the schema changed, so fail loudly.
     *
     * @param  array<string, mixed>  $row
     * @return array{id: string, capability: string, payload: array<string, mixed>, request_id: ?string, attempt: int}
     */
    public static function claimedJob(array $row): array
    {
        $attempt = $row['attempt'] ?? null;

        if (! is_int($attempt) && ! (is_string($attempt) && ctype_digit($attempt))) {
            throw new RuntimeException('coord.claim_job returned no attempt for job '.(string) ($row['id'] ?? '?').'; refusing to guess attempt 1.');
        }

        if ((int) $attempt < 1) {
            throw new RuntimeException('coord.claim_job returned attempt '.(int) $attempt.' for job '.(string) ($row['id'] ?? '?').'; a claimed job must have attempt >= 1.');
        }

        $payload = json_decode((string) ($row['payload'] ?? '{}'), true);

        return [
            'id' => (string) $row['id'],
            'capability' => (string) ($row['capability'] ?? ''),
            'payload' => is_array($payload) ? $payload : [],
            'request_id' => isset($row['request_id']) ? (string) $row['request_id'] : null,
            'attempt' => (int) $attempt,
        ];
    }

    public function heartbeat(string $jobId, int $leaseSeconds): void
    {
        $this->call(fn (PDO $pdo) => $pdo->prepare('SELECT coord.heartbeat_job(CAST(:job AS uuid), make_interval(secs => :lease))')
            ->execute(['job' => $jobId, 'lease' => max(30, $leaseSeconds)]));
    }

    public function complete(string $jobId, array $result): void
    {
        $json = json_encode($result === [] ? new \stdClass : $result, JSON_THROW_ON_ERROR);

        $this->call(fn (PDO $pdo) => $pdo->prepare('SELECT coord.complete_job(CAST(:job AS uuid), CAST(:result AS jsonb))')
            ->execute(['job' => $jobId, 'result' => $json]));
    }

    public function fail(string $jobId, string $error, bool $retry = true): string
    {
        return $this->call(function (PDO $pdo) use ($jobId, $error, $retry): string {
            $statement = $pdo->prepare('SELECT coord.fail_job(CAST(:job AS uuid), :error, :retry)');
            $statement->bindValue('job', $jobId);
            $statement->bindValue('error', mb_substr($error, 0, 500));
            $statement->bindValue('retry', $retry, PDO::PARAM_BOOL);
            $statement->execute();

            return (string) $statement->fetchColumn();
        });
    }

    public function waitForNotification(int $timeoutMs): ?array
    {
        $notify = $this->call(function (PDO $pdo) use ($timeoutMs): mixed {
            if (! $this->listening) {
                $pdo->exec('LISTEN coord');
                $this->listening = true;
            }

            /** @var Pgsql|PDO $pdo */
            return method_exists($pdo, 'getNotify')
                ? $pdo->getNotify(PDO::FETCH_ASSOC, max(0, $timeoutMs))
                : $pdo->pgsqlGetNotify(PDO::FETCH_ASSOC, max(0, $timeoutMs));
        });

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
