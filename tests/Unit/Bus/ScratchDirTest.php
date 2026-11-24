<?php

use App\Bus\ScratchDir;

it('skips a temp dir inside the checkout and falls back to the next candidate', function () {
    $root = sys_get_temp_dir().'/m6-scratch-root-'.bin2hex(random_bytes(3));
    $outside = sys_get_temp_dir().'/m6-scratch-outside-'.bin2hex(random_bytes(3));
    mkdir($root.'/tmp', 0700, true);
    mkdir($outside, 0700);

    try {
        // Like a CI runner that sets TMPDIR inside the clone.
        expect(ScratchDir::path($root, [$root.'/tmp', $outside]))->toBe(realpath($outside))
            ->and(ScratchDir::path($root, [$root, $outside]))->toBe(realpath($outside))
            ->and(fn () => ScratchDir::path($root, [$root.'/tmp']))->toThrow(RuntimeException::class);
    } finally {
        rmdir($root.'/tmp');
        rmdir($root);
        rmdir($outside);
    }
});

it('never returns a directory inside this checkout', function () {
    $dir = ScratchDir::path();

    expect(ScratchDir::inside($dir, (string) realpath(dirname(__DIR__, 3))))->toBeFalse()
        ->and(is_writable($dir))->toBeTrue();
});

it('treats only the root and its subdirectories as inside', function () {
    expect(ScratchDir::inside('/srv/app', '/srv/app'))->toBeTrue()
        ->and(ScratchDir::inside('/srv/app/tmp', '/srv/app/'))->toBeTrue()
        ->and(ScratchDir::inside('/srv/app-tmp', '/srv/app'))->toBeFalse();
});
