<?php

use App\Bus\OpenCodeCapture;

it('maps tool.execute.after onto the same toolCall emit as Grok', function () {
    $actions = OpenCodeCapture::actions(opencodeEvent('tool-execute-after.json'));

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->verb)->toBe('emit')
        ->and($actions[0]->type)->toBe('toolCall')
        ->and($actions[0]->agentType)->toBe('opencode')
        ->and($actions[0]->sessionId)->toBe('oc-session-1')
        ->and($actions[0]->eventId)->toBe('oc-session-1.toolCall.call-1')
        ->and($actions[0]->correlationId)->toBeNull()
        ->and($actions[0]->payload)->toBe([
            'tool' => 'run_terminal_command',
            'callId' => 'call-1',
            'params' => ['command' => 'composer test'],
        ]);
});

it('uses the provider message id to correlate events from the same turn', function () {
    $event = opencodeEvent('tool-execute-after.json');
    $event['messageID'] = 'message-7';

    $actions = OpenCodeCapture::actions($event);

    expect($actions[0]->correlationId)->toBe('oc-session-1.turn.message-7');
});

it('keeps an empty provider argument object as empty params', function () {
    $event = opencodeEvent('tool-execute-after.json');
    $event['args'] = [];

    $actions = OpenCodeCapture::actions($event);

    expect($actions[0]->payload['params'])->toBe([]);
});

it('maps session.created to heartbeat and sessionStart', function () {
    $actions = OpenCodeCapture::actions(opencodeEvent('session-created.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->verb)->toBe('heartbeat')
        ->and($actions[1]->type)->toBe('sessionStart')
        ->and($actions[1]->agentType)->toBe('opencode');
});

it('maps session.idle to idle emit and heartbeat', function () {
    $actions = OpenCodeCapture::actions(opencodeEvent('session-idle.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('idle')
        ->and($actions[1]->verb)->toBe('heartbeat');
});

it('maps session.deleted to sessionEnd emit and KV delete', function () {
    $actions = OpenCodeCapture::actions(opencodeEvent('session-deleted.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('sessionEnd')
        ->and($actions[1]->verb)->toBe('sessionEnd');
});

it('maps session.error to errorRaised', function () {
    $actions = OpenCodeCapture::actions(opencodeEvent('session-error.json'));

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->type)->toBe('errorRaised')
        ->and($actions[0]->sessionId)->toBe('oc-session-1');
});

it('does not publish unlisted OpenCode events', function () {
    expect(OpenCodeCapture::actions(opencodeEvent('session-updated.json')))->toBe([]);
});

it('OpenCode plugin pipes events to bin/agent-bus opencode', function () {
    $plugin = dirname(__DIR__, 3).'/.opencode/plugin/agent-bus.js';

    expect(file_exists($plugin))->toBeTrue();

    $source = (string) file_get_contents($plugin);

    expect($source)
        ->toContain('bin/agent-bus')
        ->toContain('opencode')
        ->toContain('tool.execute.after')
        ->toContain('event')
        ->not->toContain('wrangler')
        ->not->toContain('DurableObject')
        ->not->toContain('new_sqlite_classes');
});

/**
 * @return array<string, mixed>
 */
function opencodeEvent(string $name): array
{
    $decoded = json_decode((string) file_get_contents(fixture('opencode/'.$name)), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}
