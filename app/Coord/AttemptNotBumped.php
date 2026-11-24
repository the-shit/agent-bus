<?php

namespace App\Coord;

use RuntimeException;

/**
 * coord handed back a job with an attempt the bridge has already delivered.
 * Receipts and the inbox msg-id are keyed by attempt, so delivering it would
 * be silently de-duplicated (or read the old attempt's receipts).
 */
final class AttemptNotBumped extends RuntimeException {}
