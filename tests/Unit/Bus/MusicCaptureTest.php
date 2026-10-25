<?php

use App\Bus\MusicCapture;

it('maps PlaybackStart to playbackStart emit and heartbeat', function () {
    $actions = MusicCapture::actions(musicEvent('music/playback-start.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('playbackStart')
        ->and($actions[1]->verb)->toBe('heartbeat')
        ->and($actions[1]->agentType)->toBe('music')
        ->and($actions[0]->sessionId)->toBe('music:01a08ef9-2515-7460-89bd-5efc21f28642')
        ->and($actions[0]->payload['track'])->toBe([
            'title' => 'Never Gonna Give You Up',
            'artist' => 'Rick Astley',
            'album' => 'Whenever You Need Somebody',
            'durationMs' => 213000,
            'trackNumber' => 1,
            'uri' => 'spotify:track:4uK7t4w2cLqZv8k7hY6b5A',
        ]);
});

it('maps PlaybackPause to playbackPause emit and heartbeat', function () {
    $actions = MusicCapture::actions(musicEvent('music/playback-pause.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('playbackPause')
        ->and($actions[0]->payload['positionMs'])->toBe(120000);
});

it('maps PlaybackStop to playbackStop emit and heartbeat', function () {
    $actions = MusicCapture::actions(musicEvent('music/playback-stop.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('playbackStop');
});

it('maps TrackChange to trackChange emit and heartbeat', function () {
    $actions = MusicCapture::actions(musicEvent('music/track-change.json'));

    expect($actions)->toHaveCount(2)
        ->and($actions[0]->type)->toBe('trackChange')
        ->and($actions[0]->payload['track'])->toBe([
            'title' => 'Walking on Sunshine',
            'artist' => 'KT Tunstall',
            'album' => 'Kiss My Pain',
            'durationMs' => 199000,
            'trackNumber' => 3,
            'uri' => 'spotify:track:7qiFf1pM6c6e8b3w9x4y1Z',
        ])
        ->and($actions[0]->payload['previousTrack'])->toBe([
            'title' => 'Never Gonna Give You Up',
            'artist' => 'Rick Astley',
            'album' => 'Whenever You Need Somebody',
            'durationMs' => 213000,
            'trackNumber' => 1,
            'uri' => 'spotify:track:4uK7t4w2cLqZv8k7hY6b5A',
        ]);
});

it('drops unlisted music events', function () {
    $event = musicEvent('music/playback-start.json');
    $event['hook_event_name'] = 'VolumeChange';

    expect(MusicCapture::actions($event))->toBe([]);
});

it('drops events with no provider id instead of falling back', function () {
    expect(MusicCapture::actions([
        'hook_event_name' => 'PlaybackStart',
        'track' => ['title' => 'Test'],
    ]))->toBe([]);
});

/**
 * @return array<string, mixed>
 */
function musicEvent(string $path): array
{
    $decoded = json_decode((string) file_get_contents(fixture($path)), true, 512, JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    return $decoded;
}
