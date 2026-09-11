<?php

use App\Bus\SessionPaneMap;

it('maps herdr agent_session.value to pane_id when the session is in KV', function () {
    $map = (new SessionPaneMap)->build(
        agentsFromHerdrList(herdrAgentListJson([
            herdrAgent('01a08ef9-2515-7460-89bd-5efc21f28642', 'w2:p2'),
            herdrAgent('01a08ee2-779b-7cc2-87a1-d5aa858c8e13', 'w2:p1'),
        ])),
        ['01a08ef9-2515-7460-89bd-5efc21f28642', '01a08ee2-779b-7cc2-87a1-d5aa858c8e13'],
    );

    expect($map)->toBe([
        '01a08ef9-2515-7460-89bd-5efc21f28642' => 'w2:p2',
        '01a08ee2-779b-7cc2-87a1-d5aa858c8e13' => 'w2:p1',
    ]);
});

it('drops herdr panes whose session is not in KV', function () {
    $map = (new SessionPaneMap)->build(
        agentsFromHerdrList(herdrAgentListJson([
            herdrAgent('herdr-only', 'w2:p1'),
            herdrAgent('on-the-bus', 'w2:p2'),
        ])),
        ['on-the-bus'],
    );

    expect($map)->toBe(['on-the-bus' => 'w2:p2']);
});

it('drops KV session ids that are not in herdr', function () {
    $map = (new SessionPaneMap)->build(
        agentsFromHerdrList(herdrAgentListJson([
            herdrAgent('on-the-bus', 'w2:p2'),
        ])),
        ['on-the-bus', 'kv-only'],
    );

    expect($map)->toBe(['on-the-bus' => 'w2:p2'])
        ->and($map)->not->toHaveKey('kv-only');
});

it('parses session id from session.{id}.inbox', function () {
    $map = new SessionPaneMap;

    expect($map->sessionIdFromSubject('session.01a08ef9-2515-7460-89bd-5efc21f28642.inbox'))
        ->toBe('01a08ef9-2515-7460-89bd-5efc21f28642')
        ->and($map->sessionIdFromSubject('repo.the-shit.agent-bus.toolCall'))
        ->toBeNull();
});

it('prefers envelope sessionId when the subject is not an inbox', function () {
    $map = new SessionPaneMap;
    $body = json_encode(['sessionId' => 'sess-b', 'payload' => ['text' => 'ping']], JSON_THROW_ON_ERROR);

    expect($map->sessionIdFromMessage('handler.abcd', $body))->toBe('sess-b');
});

it('reads agents from herdr agent list JSON', function () {
    $json = file_get_contents(__DIR__.'/../../Fixtures/herdr-agent-list.json');
    $agents = (new SessionPaneMap)->agentsFromListJson((string) $json);

    expect($agents)->toHaveCount(3)
        ->and($agents[2]['pane_id'])->toBe('w2:p8')
        ->and($agents[2]['agent_session']['value'])->toBe('01a090c7-256d-7222-a356-fd21fbe3afed');
});

/**
 * @return list<array<string, mixed>>
 */
function agentsFromHerdrList(string $json): array
{
    return (new SessionPaneMap)->agentsFromListJson($json);
}

/**
 * @param  list<array<string, mixed>>  $agents
 */
function herdrAgentListJson(array $agents): string
{
    return json_encode([
        'id' => 'cli:agent:list',
        'result' => [
            'agents' => $agents,
            'type' => 'agent_list',
        ],
    ], JSON_THROW_ON_ERROR);
}

/**
 * @return array<string, mixed>
 */
function herdrAgent(string $sessionId, string $paneId): array
{
    return [
        'agent' => 'grok',
        'agent_session' => [
            'agent' => 'grok',
            'kind' => 'id',
            'source' => 'herdr:grok',
            'value' => $sessionId,
        ],
        'pane_id' => $paneId,
    ];
}
