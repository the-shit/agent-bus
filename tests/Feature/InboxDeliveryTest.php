<?php

use App\Bus\InboxDelivery;
use App\Bus\NatsBus;
use Basis\Nats\Message\Msg;
use Basis\Nats\Message\Payload;
use LaravelNats\Laravel\Facades\NatsV2;

function inboxMessage(int $attempt = 1): Msg
{
    $message = Mockery::mock(Msg::class)->makePartial();
    $message->subject = 'session.test.inbox';
    $message->replyTo = '$JS.ACK.AGENT_BUS.sidecar.'.$attempt.'.42.1.123456789.0';
    $message->payload = new Payload('{"sessionId":"test","payload":{"text":"ping"}}');

    return $message;
}

it('acknowledges a successful injection', function () {
    $message = inboxMessage();
    $message->shouldReceive('ack')->once();
    $message->shouldNotReceive('nack');
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldNotReceive('publishEnvelope');
    $received = null;

    (new InboxDelivery($bus))->handle($message, function ($subject, $body) use (&$received) {
        $received = [$subject, $body];
    });

    expect($received)->toBe(['session.test.inbox', $message->payload->body]);
});

it('delays failed injection without acknowledging and succeeds after recovery', function () {
    config(['agent_bus.sidecar.retry_seconds' => 5]);
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldNotReceive('publishEnvelope');
    $failed = inboxMessage();
    $failed->shouldReceive('nack')->once()->with(5.0);
    $failed->shouldNotReceive('ack');
    $recovered = inboxMessage(2);
    $recovered->shouldReceive('ack')->once();
    $recovered->shouldNotReceive('nack');

    (new InboxDelivery($bus))->handle($failed, fn () => throw new RuntimeException('recipient unavailable'));
    // A fresh delivery handler represents a restarted sidecar; attempts live in NATS.
    (new InboxDelivery($bus))->handle($recovered, fn () => null);
});

it('persists an exhausted delivery before acknowledging it', function () {
    config(['agent_bus.sidecar.max_attempts' => 3]);
    $message = inboxMessage(3);
    $message->shouldNotReceive('nack');
    $message->shouldReceive('ack')->once()->andReturnUsing(function () use (&$receipt) {
        expect($receipt)->not->toBeNull();
    });
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('streamName')->andReturn('AGENT_BUS');
    $bus->shouldReceive('sidecarConsumerName')->andReturn('sidecar');
    $receipt = null;
    $bus->shouldReceive('publishEnvelope')->once()->with('repo.agent-bus.delivery.failed', Mockery::on(function ($value) use (&$receipt) {
        $receipt = $value;

        return $value['message_id'] === 'AGENT_BUS:42' && $value['attempts'] === 3;
    }))->andReturnNull();

    (new InboxDelivery($bus))->handle($message, fn () => throw new RuntimeException('failed'));

    expect($receipt['body'])->toBe($message->payload->body)
        ->and($receipt['subject'])->toBe('session.test.inbox');
});

it('leaves exhausted delivery unacknowledged if its failure receipt cannot be stored', function () {
    config(['agent_bus.sidecar.max_attempts' => 1]);
    $message = inboxMessage();
    $message->shouldNotReceive('ack');
    $bus = Mockery::mock(NatsBus::class);
    $bus->shouldReceive('streamName')->andReturn('AGENT_BUS');
    $bus->shouldReceive('sidecarConsumerName')->andReturn('sidecar');
    $bus->shouldReceive('publishEnvelope')->once()->andThrow(new RuntimeException('broker unavailable'));

    expect(fn () => (new InboxDelivery($bus))->handle($message, fn () => throw new RuntimeException('failed')))
        ->toThrow(RuntimeException::class, 'broker unavailable');
});

it('supports domain-qualified acknowledgement metadata', function () {
    config(['agent_bus.sidecar.max_attempts' => 5, 'agent_bus.sidecar.retry_seconds' => 5]);
    $message = inboxMessage();
    $message->replyTo = '$JS.ACK.domain.account.AGENT_BUS.sidecar.2.42.1.123456789.0.random';
    $message->shouldReceive('nack')->once()->with(10.0);
    $message->shouldNotReceive('ack');

    (new InboxDelivery(Mockery::mock(NatsBus::class)))->handle($message, fn () => throw new RuntimeException('failed'));
});

it('refuses to acknowledge messages without delivery metadata', function () {
    $message = inboxMessage();
    $message->replyTo = null;
    $message->shouldNotReceive('ack');

    expect(fn () => (new InboxDelivery(Mockery::mock(NatsBus::class)))->handle($message, fn () => null))
        ->toThrow(RuntimeException::class, 'Missing JetStream delivery metadata');
});

it('consumeInbox acknowledges through InboxDelivery so the message is not redelivered', function () {
    $consumer = 'delivery-ack-'.bin2hex(random_bytes(4));
    $subject = 'session.ack-'.$consumer.'.inbox';
    config([
        'agent_bus.sidecar.consumer' => $consumer,
        'agent_bus.sidecar.expires' => 0.05,
    ]);
    $bus = app(NatsBus::class);
    try {
        $bus->provision();
        $bus->ensureInboxConsumer();
        $bus->publishEnvelope($subject, ['payload' => ['text' => 'once']]);

        $received = [];
        $processed = $bus->consumeInbox(function ($inboxSubject, $body) use (&$received): void {
            $received[] = json_decode($body, true)['payload']['text'];
        });

        expect($processed)->toBe(1)
            ->and($received)->toBe(['once']);

        $bus->consumeInbox(fn () => throw new LogicException('Acknowledged message was delivered again'));
    } finally {
        NatsV2::jetstream()->stream($bus->streamName())->getConsumer($consumer)->delete();
        NatsV2::disconnectAll();
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');

it('redelivers an unavailable recipient after a consumer reconnect and records exhausted messages', function () {
    $consumer = 'delivery-test-'.bin2hex(random_bytes(4));
    config([
        'agent_bus.sidecar.consumer' => $consumer,
        'agent_bus.sidecar.retry_seconds' => 0.01,
        'agent_bus.sidecar.max_attempts' => 2,
        'agent_bus.sidecar.expires' => 0.05,
    ]);
    $bus = app(NatsBus::class);
    try {
        $bus->provision();
        $bus->ensureInboxConsumer();
        $bus->publishEnvelope('session.retry-test.inbox', ['payload' => ['text' => 'recover']]);
        $bus->consumeInbox(fn () => throw new RuntimeException('recipient unavailable'));
        NatsV2::disconnectAll();
        $received = [];
        $deadline = microtime(true) + 5;
        while ($received === [] && microtime(true) < $deadline) {
            $bus->consumeInbox(function ($subject, $body) use (&$received) {
                $received[] = json_decode($body, true)['payload']['text'];
            });
        }
        expect($received)->toBe(['recover']);

        $bus->publishEnvelope('session.retry-test.inbox', ['payload' => ['text' => 'exhaust']]);
        $attempts = 0;
        $deadline = microtime(true) + 5;
        while ($attempts < 2 && microtime(true) < $deadline) {
            $bus->consumeInbox(function () use (&$attempts) {
                $attempts++;
                throw new RuntimeException('injection failed');
            });
        }
        expect($attempts)->toBe(2);
        $receipt = $bus->lastEnvelope('repo.agent-bus.delivery.failed');
        expect($receipt['type'])->toBe('delivery_failed')
            ->and($receipt['attempts'])->toBe(2)
            ->and(json_decode($receipt['body'], true)['payload']['text'])->toBe('exhaust');
        $bus->consumeInbox(fn () => throw new LogicException('Acknowledged message was delivered again'));
    } finally {
        NatsV2::jetstream()->stream($bus->streamName())->getConsumer($consumer)->delete();
        NatsV2::disconnectAll();
    }
})->skip(fn () => brokerIsDown(), 'NATS broker is not running');
