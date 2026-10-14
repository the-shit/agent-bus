<?php

use App\Bus\NatsBus;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Process\InvokedProcess;
use Illuminate\Support\Facades\Process;
use LaravelNats\Laravel\Facades\NatsV2;

afterEach(function () {
    NatsV2::disconnectAll();
});

it('fails closed when the broker is down instead of skipping', function () {
    expect(brokerIsDown())->toBeFalse();
});

it('delivers one addressed inbox payload only to the recipient session', function () {
    requireBroker();

    $sender = proveGrokSession();
    $recipient = proveGrokSession();
    $payload = ['text' => 'prove-addressed', 'marker' => bin2hex(random_bytes(8))];

    try {
        expect(proveSessionIds())->not->toContain($sender)->not->toContain($recipient);
        expect(proveHeartbeat($sender)->exitCode())->toBe(0)
            ->and(proveHeartbeat($recipient)->exitCode())->toBe(0);

        $sent = proveBus([
            'send',
            '--session='.$recipient,
            '--agent-type=grok',
            '--payload='.json_encode($payload, JSON_THROW_ON_ERROR),
        ]);

        expect($sent->exitCode())->toBe(0)->and($sent->errorOutput())->toBe('');

        $got = proveBus([
            'inbox',
            '--session='.$recipient,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '1']);
        $envelope = json_decode($got->output(), true);

        expect($got->exitCode())->toBe(0)
            ->and($envelope['payload'])->toBe($payload);

        $again = proveBus([
            'inbox',
            '--session='.$recipient,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '0.3']);
        $senderInbox = proveBus([
            'inbox',
            '--session='.$sender,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '0.3']);

        expect($again->exitCode())->toBe(2)
            ->and($again->output())->toBe('')
            ->and($senderInbox->exitCode())->toBe(2)
            ->and($senderInbox->output())->toBe('');
    } finally {
        proveEnd($sender, $recipient);
    }
});

it('delivers overlapping addressed sends only to each target once', function () {
    requireBroker();

    $left = proveGrokSession();
    $right = proveGrokSession();
    $toRight = ['text' => 'prove-to-right', 'marker' => bin2hex(random_bytes(8))];
    $toLeft = ['text' => 'prove-to-left', 'marker' => bin2hex(random_bytes(8))];

    try {
        expect(proveSessionIds())->not->toContain($left)->not->toContain($right);
        expect(proveHeartbeat($left)->exitCode())->toBe(0)
            ->and(proveHeartbeat($right)->exitCode())->toBe(0);

        $sendRight = proveStart([
            'send',
            '--session='.$right,
            '--agent-type=grok',
            '--payload='.json_encode($toRight, JSON_THROW_ON_ERROR),
        ]);
        $sendLeft = proveStart([
            'send',
            '--session='.$left,
            '--agent-type=grok',
            '--payload='.json_encode($toLeft, JSON_THROW_ON_ERROR),
        ]);
        $sentRight = $sendRight->wait();
        $sentLeft = $sendLeft->wait();

        expect($sentRight->exitCode())->toBe(0)
            ->and($sentLeft->exitCode())->toBe(0);

        $pullRight = proveStart([
            'inbox',
            '--session='.$right,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '1']);
        $pullLeft = proveStart([
            'inbox',
            '--session='.$left,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '1']);
        $gotRight = $pullRight->wait();
        $gotLeft = $pullLeft->wait();
        $rightEnvelope = json_decode($gotRight->output(), true);
        $leftEnvelope = json_decode($gotLeft->output(), true);

        expect($gotRight->exitCode())->toBe(0)
            ->and($gotLeft->exitCode())->toBe(0)
            ->and($rightEnvelope['payload'])->toBe($toRight)
            ->and($leftEnvelope['payload'])->toBe($toLeft);

        $rightAgain = proveBus([
            'inbox',
            '--session='.$right,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '0.3']);
        $leftAgain = proveBus([
            'inbox',
            '--session='.$left,
            '--agent-type=grok',
        ], ['AGENT_BUS_INBOX_EXPIRES' => '0.3']);

        expect($rightAgain->exitCode())->toBe(2)
            ->and($rightAgain->output())->toBe('')
            ->and($leftAgain->exitCode())->toBe(2)
            ->and($leftAgain->output())->toBe('');
    } finally {
        proveEnd($left, $right);
    }
});

it('gives each overlapping work request to one worker and one correlated result', function () {
    requireBroker();

    $capability = 'prove'.bin2hex(random_bytes(4));
    $consumer = 'agent-bus-'.$capability;
    $workerA = proveGrokSession();
    $workerB = proveGrokSession();
    $requester = proveGrokSession();
    $markerA = bin2hex(random_bytes(6));
    $markerB = bin2hex(random_bytes(6));
    $claims = [];

    expect($capability)->not->toBe('code_review');
    expect($capability)->not->toBe('tool_failure_triage');

    try {
        $requestA = proveStart([
            'work-request',
            '--capability='.$capability,
            '--agent-type=grok',
            '--session='.$requester,
            '--wait=0',
            '--payload='.json_encode(['marker' => $markerA], JSON_THROW_ON_ERROR),
        ]);
        $requestB = proveStart([
            'work-request',
            '--capability='.$capability,
            '--agent-type=grok',
            '--session='.$requester,
            '--wait=0',
            '--payload='.json_encode(['marker' => $markerB], JSON_THROW_ON_ERROR),
        ]);
        $sentA = $requestA->wait();
        $sentB = $requestB->wait();
        $envelopeA = json_decode($sentA->output(), true);
        $envelopeB = json_decode($sentB->output(), true);
        $beforeWorkers = Process::timeout(5)->run(['nats', 'consumer', 'info', 'AGENT_BUS', $consumer]);

        expect($sentA->exitCode())->toBe(0)
            ->and($sentB->exitCode())->toBe(0)
            ->and($envelopeA['payload']['capability'])->toBe($capability)
            ->and($envelopeB['payload']['capability'])->toBe($capability)
            ->and($beforeWorkers->exitCode())->not->toBe(0);

        $claims = [
            proveStart(proveClaim($capability, $consumer, $workerA), [], 20),
            proveStart(proveClaim($capability, $consumer, $workerB), [], 20),
        ];

        $claimedA = $claims[0]->wait();
        $claimedB = $claims[1]->wait();
        $resultA = json_decode($claimedA->output(), true);
        $resultB = json_decode($claimedB->output(), true);

        expect([$claimedA->exitCode(), trim($claimedA->errorOutput()), $claimedB->exitCode(), trim($claimedB->errorOutput())])->toBe([0, '', 0, '']);

        $byRequest = [];

        foreach ([$resultA, $resultB] as $result) {
            $requestId = $result['payload']['requestId'];
            $byRequest[$requestId] = $result;
        }

        expect(array_keys($byRequest))->toEqualCanonicalizing([
            $envelopeA['payload']['requestId'],
            $envelopeB['payload']['requestId'],
        ]);

        $bus = app(NatsBus::class);

        foreach ([
            $envelopeA['payload']['requestId'] => $markerA,
            $envelopeB['payload']['requestId'] => $markerB,
        ] as $requestId => $marker) {
            $printed = $byRequest[$requestId];
            $stored = $bus->lastEnvelope('work.result.'.$requestId);

            expect($printed['payload']['status'])->toBe('ok')
                ->and($printed['payload']['capability'])->toBe($capability)
                ->and($printed['payload']['result']['task'])->toBe(['marker' => $marker])
                ->and($printed['payload']['result']['worker'])->toBeIn([$workerA, $workerB])
                ->and($stored)->toBe($printed);
        }

        $info = Process::timeout(5)->run(['nats', 'consumer', 'info', 'AGENT_BUS', $consumer, '--json']);
        $consumerInfo = json_decode($info->output(), true);

        expect($info->exitCode())->toBe(0)
            ->and($consumerInfo['config']['deliver_policy'] ?? null)->toBe('all');
    } finally {
        foreach ($claims as $claim) {
            if ($claim->running()) {
                $claim->stop(1);
            }
        }

        Process::timeout(5)->run(['nats', 'consumer', 'rm', 'AGENT_BUS', $consumer, '--force']);
    }
});

it('refuses to claim the live code review and tool failure capabilities', function () {
    requireBroker();

    foreach (['code_review', 'tool_failure_triage'] as $capability) {
        $result = proveBus(proveClaim($capability, 'agent-bus-must-not-create', proveGrokSession(), 0), [], 15);

        $leftBehind = Process::timeout(5)->run(['nats', 'consumer', 'info', 'AGENT_BUS', 'agent-bus-must-not-create']);

        expect($result->exitCode())->toBe(1)
            ->and($result->output())->toContain('refusing live capability '.$capability)
            ->and($leftBehind->exitCode())->not->toBe(0);
    }
});

function requireBroker(): void
{
    if (brokerIsDown()) {
        throw new RuntimeException('NATS broker is not running at nats://127.0.0.1:4222.');
    }
}

function proveUuid(): string
{
    $bytes = random_bytes(16);
    $bytes[6] = chr((ord($bytes[6]) & 0x0F) | 0x40);
    $bytes[8] = chr((ord($bytes[8]) & 0x3F) | 0x80);
    $hex = bin2hex($bytes);

    return substr($hex, 0, 8).'-'.substr($hex, 8, 4).'-'.substr($hex, 12, 4).'-'.substr($hex, 16, 4).'-'.substr($hex, 20);
}

function proveGrokSession(): string
{
    return 'grok:'.proveUuid();
}

/**
 * @return list<string>
 */
function proveSessionIds(): array
{
    $listed = proveBus(['sessions', '--ids']);
    $ids = json_decode($listed->output(), true);

    expect($listed->exitCode())->toBe(0)->and($ids)->toBeArray();

    return array_values($ids);
}

function proveHeartbeat(string $sessionId): ProcessResult
{
    return proveBus([
        'heartbeat',
        '--session='.$sessionId,
        '--agent-type=grok',
    ]);
}

function proveEnd(string ...$sessionIds): void
{
    foreach ($sessionIds as $sessionId) {
        proveBus([
            'session-end',
            '--session='.$sessionId,
            '--agent-type=grok',
        ]);
    }
}

/**
 * @return list<string>
 */
function proveClaim(string $capability, string $consumer, string $sessionId, int $wait = 12): array
{
    return [
        'work-claim',
        '--capability='.$capability,
        '--consumer='.$consumer,
        '--session='.$sessionId,
        '--agent-type=grok',
        '--wait='.$wait,
    ];
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function proveBus(array $arguments, array $environment = [], int $timeout = 15): ProcessResult
{
    return Process::path(base_path())
        ->timeout($timeout)
        ->env($environment)
        ->run([PHP_BINARY, base_path('bin/agent-bus'), ...$arguments]);
}

/**
 * @param  list<string>  $arguments
 * @param  array<string, string>  $environment
 */
function proveStart(array $arguments, array $environment = [], int $timeout = 15): InvokedProcess
{
    return Process::path(base_path())
        ->timeout($timeout)
        ->env($environment)
        ->start([PHP_BINARY, base_path('bin/agent-bus'), ...$arguments]);
}
