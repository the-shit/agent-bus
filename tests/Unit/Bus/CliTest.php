<?php

use App\Bus\Cli;

test('repoFromRemote maps github remotes to owner/name', function (string $url) {
    expect(Cli::repoFromRemote($url))->toBe('the-shit/agent-bus');
})->with([
    'ssh' => ['git@github.com:the-shit/agent-bus.git'],
    'https' => ['https://github.com/the-shit/agent-bus.git'],
    'https without git suffix' => ['https://github.com/the-shit/agent-bus'],
    'ssh url' => ['ssh://git@github.com/the-shit/agent-bus.git'],
]);
