<?php

namespace App\Bus;

use RuntimeException;

/**
 * Where agent-bus puts scratch files (wake stdin and result files, the Pi
 * specialist's default cwd): the system temp dir, unless it lies inside the
 * agent-bus checkout. Some CI runners set TMPDIR inside the clone, and the
 * specialist sandbox refuses the checkout, so fall back to /tmp then.
 * Pure: the hot path loads this without the framework.
 */
final class ScratchDir
{
    /**
     * @param  string|null  $root  the checkout to stay out of (default: this one)
     * @param  list<string>|null  $candidates  directories to try, in order
     */
    public static function path(?string $root = null, ?array $candidates = null): string
    {
        $root = realpath($root ?? dirname(__DIR__, 2));

        foreach ($candidates ?? [sys_get_temp_dir(), '/tmp'] as $candidate) {
            $dir = realpath($candidate);

            if ($dir !== false && is_dir($dir) && is_writable($dir) && ($root === false || ! self::inside($dir, $root))) {
                return $dir;
            }
        }

        throw new RuntimeException('No writable temp directory outside the agent-bus checkout; set TMPDIR outside it.');
    }

    /** True when $path is $root or below it (both already resolved). */
    public static function inside(string $path, string $root): bool
    {
        $root = rtrim($root, '/');

        return $path === $root || str_starts_with($path.'/', $root.'/');
    }
}
