<?php

use App\Bus\BusIdentity;

const PI_UUID = '01a09e6a-3b2c-4d5e-8f60-71a2b3c4d5e6';
const PI_JSONL = '/home/jordan/.pi/agent/sessions/--home-jordan-projects--/2026-09-13T05-36-19-422Z_01a09e6a-3b2c-4d5e-8f60-71a2b3c4d5e6.jsonl';

describe('resolve', function () {
    it('canonicalizes a grok hook UUID', function () {
        expect(BusIdentity::resolve('grok', ['sessionId' => '01a08ef9-2515-7460-89bd-5efc21f28642']))
            ->toBe('grok:01a08ef9-2515-7460-89bd-5efc21f28642');
    });

    it('extracts the UUID segment of a pi jsonl path, never the path', function () {
        expect(BusIdentity::resolve('pi', ['sessionId' => PI_JSONL]))->toBe('pi:'.PI_UUID);
    });

    it('accepts a pi jsonl basename and a bare PI_SESSION_ID UUID', function () {
        expect(BusIdentity::resolve('pi', ['sessionId' => '2026-09-13T05-36-19-422Z_'.PI_UUID.'.jsonl']))
            ->toBe('pi:'.PI_UUID)
            ->and(BusIdentity::resolve('pi', ['sessionId' => PI_UUID]))
            ->toBe('pi:'.PI_UUID);
    });

    it('canonicalizes an opencode ses_* id from any candidate field', function () {
        expect(BusIdentity::resolve('opencode', ['sessionID' => 'ses_31f4c2abbe']))->toBe('opencode:ses_31f4c2abbe')
            ->and(BusIdentity::resolve('opencode', ['properties' => ['sessionID' => 'ses_31f4c2abbe']]))
            ->toBe('opencode:ses_31f4c2abbe');
    });

    it('canonicalizes a codex rollout UUID', function () {
        expect(BusIdentity::resolve('codex', ['rollout_id' => PI_UUID]))->toBe('codex:'.PI_UUID)
            ->and(BusIdentity::resolve('codex', ['sessionId' => PI_UUID]))->toBe('codex:'.PI_UUID);
    });

    it('no longer mints pane-grade herdr ids', function () {
        expect(BusIdentity::resolve('herdr', ['pane_id' => 'w2:p1']))->toBeNull()
            ->and(BusIdentity::resolve('herdr', ['sessionId' => PI_JSONL]))->toBeNull();
    });

    it('returns null when no provider id exists instead of inventing one', function () {
        expect(BusIdentity::resolve('grok', ['cwd' => '/tmp/x']))->toBeNull()
            ->and(BusIdentity::resolve('grok', ['sessionId' => 'not-a-uuid']))->toBeNull()
            ->and(BusIdentity::resolve('opencode', ['sessionId' => 'oc-session-1']))->toBeNull()
            ->and(BusIdentity::resolve('pi', []))->toBeNull();
    });

    it('passes canonical ids through untouched for any kind', function () {
        expect(BusIdentity::resolve('grok', ['sessionId' => 'pi:'.PI_UUID]))->toBe('pi:'.PI_UUID)
            ->and(BusIdentity::resolve('opencode', ['sessionId' => 'grok:'.PI_UUID]))->toBe('grok:'.PI_UUID);
    });
});

describe('aliases', function () {
    it('collects the raw alternates a reporter supplied', function () {
        expect(BusIdentity::aliases('pi:'.PI_UUID, ['sessionId' => PI_JSONL]))->toBe([PI_JSONL])
            ->and(BusIdentity::aliases('grok:'.PI_UUID, ['sessionId' => PI_UUID]))->toBe([PI_UUID]);
    });

    it('ignores pane ids', function () {
        expect(BusIdentity::aliases('pi:'.PI_UUID, ['sessionId' => PI_JSONL, 'pane_id' => 'w2:p1']))->toBe([PI_JSONL]);
    });

    it('never aliases an id to itself', function () {
        expect(BusIdentity::aliases('pi:'.PI_UUID, ['sessionId' => 'pi:'.PI_UUID]))->toBe([]);
    });
});

describe('quality and shape', function () {
    it('reports session quality for canonical ids', function () {
        expect(BusIdentity::quality('pi:'.PI_UUID))->toBe('session');
    });

    it('recognizes canonical ids and no longer treats herdr as a kind', function () {
        expect(BusIdentity::isCanonical('grok:'.PI_UUID))->toBeTrue()
            ->and(BusIdentity::isCanonical('herdr:w2:p1'))->toBeFalse()
            ->and(BusIdentity::isCanonical(PI_UUID))->toBeFalse()
            ->and(BusIdentity::isCanonical(PI_JSONL))->toBeFalse()
            ->and(BusIdentity::isCanonical('unknownkind:x'))->toBeFalse();
    });
});
