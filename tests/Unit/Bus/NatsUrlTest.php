<?php

use App\Bus\NatsUrl;

it('defaults to loopback 4222 when NATS_URL is empty', function () {
    expect(NatsUrl::parse(''))->toBe([
        'host' => '127.0.0.1',
        'port' => 4222,
        'user' => null,
        'pass' => null,
    ]);
});

it('parses a host and port', function () {
    expect(NatsUrl::parse('nats://10.0.0.5:4333'))->toBe([
        'host' => '10.0.0.5',
        'port' => 4333,
        'user' => null,
        'pass' => null,
    ]);
});

it('assumes the nats scheme when the URL omits it', function () {
    expect(NatsUrl::parse('homelab:4222'))->toBe([
        'host' => 'homelab',
        'port' => 4222,
        'user' => null,
        'pass' => null,
    ]);
});

it('carries credentials so a tailnet broker can require them', function () {
    expect(NatsUrl::parse('nats://bus:secret@homelab.tail:4222'))->toBe([
        'host' => 'homelab.tail',
        'port' => 4222,
        'user' => 'bus',
        'pass' => 'secret',
    ]);
});
