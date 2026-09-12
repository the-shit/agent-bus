<?php

use App\Bus\GrokHook;

it('maps PostToolUse to one toolCall emit with payload.tool', function () {
    $actions = GrokHook::actions(grokEvent('grok/post-tool-use.json'));

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->verb)->toBe('emit')
        ->and($actions[0]->type)->toBe('toolCall')
        ->and($actions[0]->agentType)->toBe('grok')
        ->and($actions[0]->sessionId)->toBe('01a08ef9-2515-7460-89bd-5efc21f28642')
        ->and($actions[0]->payload['tool'])->toBe('run_terminal_command');
});

it('drops phase_changed so it is not published', function () {
    expect(GrokHook::actions(grokEvent('grok/phase-changed.json')))->toBe([]);
});

it('maps SessionStart to heartbeat then sessionStart emit', function () {
    $actions = GrokHook::actions(grokEvent('grok/session-start.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->verb)->toBe('heartbeat')
        ->and($actions[1]->verb)->toBe('emit')
        ->and($actions[1]->type)->toBe('sessionStart');
});

it('maps SessionEnd to sessionEnd emit then KV delete', function () {
    $actions = GrokHook::actions(grokEvent('grok/session-end.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->verb)->toBe('emit')
        ->and($actions[0]->type)->toBe('sessionEnd')
        ->and($actions[1]->verb)->toBe('sessionEnd');
});

it('maps idle_prompt Notification to idle emit and heartbeat', function () {
    $actions = GrokHook::actions(grokEvent('grok/idle-prompt.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('idle')
        ->and($actions[1]->verb)->toBe('heartbeat');
});

it('drops Notification types that are not idle_prompt', function () {
    $event = grokEvent('grok/idle-prompt.json');
    $event['notificationType'] = 'permission_prompt';

    expect(GrokHook::actions($event))->toBe([]);
});

it('maps PostToolUseFailure to errorRaised', function () {
    $actions = GrokHook::actions([
        'hook_event_name' => 'PostToolUseFailure',
        'sessionId' => 's1',
        'toolName' => 'run_terminal_command',
    ]);

    expect($actions)->toHaveCount(1)
        ->and($actions[0]->type)->toBe('errorRaised')
        ->and($actions[0]->payload['tool'])->toBe('run_terminal_command');
});

it('skips subagent events', function () {
    $event = grokEvent('grok/post-tool-use.json');
    $event['subagentType'] = 'explore';

    expect(GrokHook::actions($event))->toBe([]);
});

/**
 * @return array<string, mixed>
 */
function grokEvent(string $path): array
{
    $decoded = json_decode((string) file_get_contents(fixture($path)), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}
