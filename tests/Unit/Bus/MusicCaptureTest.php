<?php

use App\Bus\Cli;
use App\Bus\MusicCapture;

it('maps a music JSONL line to a spotify.> subject and envelope v2', function () {
    $mapped = MusicCapture::board(musicJsonlObject('spotify-track-changed.jsonl'));

    expect($mapped)->toBeArray()
        ->and($mapped['subject'])->toBe('spotify.track.changed')
        ->and($mapped['envelope'])->toHaveKeys(['v', 'sessionId', 'agentType', 'model', 'repo', 'type', 'timestamp', 'payload'])
        ->and($mapped['envelope']['v'])->toBe(2)
        ->and($mapped['envelope']['sessionId'])->toBe('')
        ->and($mapped['envelope']['agentType'])->toBe('music')
        ->and($mapped['envelope']['model'])->toBe('')
        ->and($mapped['envelope']['repo'])->toBe('the-shit/music')
        ->and($mapped['envelope']['type'])->toBe('track.changed')
        ->and($mapped['envelope']['timestamp'])->toBe('2026-09-19T08:00:00Z')
        ->and($mapped['envelope']['payload'])->not->toHaveKey('component')
        ->and($mapped['envelope']['payload'])->not->toHaveKey('event')
        ->and($mapped['envelope']['payload']['track'])->toBe('Never Gonna Give You Up')
        ->and($mapped['envelope']['payload']['artist'])->toBe('Rick Astley')
        ->and($mapped['envelope']['payload']['uri'])->toBe('spotify:track:4PTG3Z6ehGkBFwjybzWkR8')
        ->and($mapped['envelope']['payload']['album'])->toBe('Whenever You Need Somebody')
        ->and($mapped['envelope']['payload']['is_playing'])->toBeTrue();
});

it('prefixes a spotify-less event once so the subject still matches spotify.>', function () {
    $mapped = MusicCapture::board([
        'event' => 'track.played',
        'data' => [
            'track' => 'Never Gonna Give You Up',
            'artist' => 'Rick Astley',
            'uri' => 'spotify:track:4PTG3Z6ehGkBFwjybzWkR8',
        ],
        'timestamp' => '2026-09-19T08:00:00+00:00',
    ]);

    expect($mapped['subject'])->toBe('spotify.track.played')
        ->and($mapped['envelope']['type'])->toBe('track.played')
        ->and($mapped['envelope']['v'])->toBe(2);
});

it('drops music JSONL lines that are not a boardable spotify event', function () {
    expect(MusicCapture::board([]))->toBeNull()
        ->and(MusicCapture::board(['event' => '']))->toBeNull()
        ->and(MusicCapture::board(['event' => 'spotify.track.*']))->toBeNull()
        ->and(MusicCapture::board(['event' => 'spotify.track>changed']))->toBeNull()
        ->and(MusicCapture::board(['event' => 'spotify.track changed']))->toBeNull();
});

it('keeps the music connector off the spotify hot path', function () {
    $root = dirname(__DIR__, 3);

    expect(Cli::HOT_VERBS)->not->toContain('music')
        ->and(Cli::HOT_VERBS)->not->toContain('spotify')
        ->and(file_exists($root.'/app/Music/SpotifyCapture.php'))->toBeFalse()
        ->and(file_exists($root.'/bin/spotify-bus-connector'))->toBeFalse();
});

/**
 * @return array<string, mixed>
 */
function musicJsonlObject(string $name): array
{
    $decoded = json_decode((string) file_get_contents(__DIR__.'/../../Fixtures/music/'.$name), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}
