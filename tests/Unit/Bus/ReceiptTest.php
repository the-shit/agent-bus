<?php

use App\Bus\Receipt;

it('keeps a caller job id such as a coord uuid', function () {
    expect(Receipt::jobId('3f1c2a9e-7b4d-4e21-9a55-0c8d1e2f3a4b'))->toBe('3f1c2a9e-7b4d-4e21-9a55-0c8d1e2f3a4b');
});

it('generates a 32-hex job id when none is given', function () {
    expect(Receipt::jobId())->toMatch('/^[0-9a-f]{32}$/')
        ->and(Receipt::jobId())->not->toBe(Receipt::jobId());
});

it('rejects job ids that would break the subject token', function (string $bad) {
    Receipt::jobId($bad);
})->throws(InvalidArgumentException::class)->with(['a.b', 'a b', 'a*', 'a>', str_repeat('x', 129)]);

it('puts each attempt and state on its own subject', function () {
    expect(Receipt::subject('job-1', 1, 'queued'))->toBe('work.receipt.job-1.1.queued')
        ->and(Receipt::subject('job-1', 1, 'delivered'))->toBe('work.receipt.job-1.1.delivered')
        ->and(Receipt::subject('job-1', 2, 'failed'))->toBe('work.receipt.job-1.2.failed');
});

it('rejects unknown states', function () {
    Receipt::subject('job-1', 1, 'acked');
})->throws(InvalidArgumentException::class);

it('defaults the attempt to 1 and accepts coord attempts', function () {
    expect(Receipt::attempt())->toBe(1)
        ->and(Receipt::attempt(''))->toBe(1)
        ->and(Receipt::attempt('3'))->toBe(3)
        ->and(Receipt::attempt(7))->toBe(7);
});

it('rejects attempts that are not a positive whole number', function (int|string $bad) {
    Receipt::attempt($bad);
})->throws(InvalidArgumentException::class)->with([0, -1, '0', '1.5', 'two', '01', '2000000']);

it('reads the attempt an envelope carries, 1 when it carries none or garbage', function () {
    expect(Receipt::attemptOf(['attempt' => 2]))->toBe(2)
        ->and(Receipt::attemptOf([]))->toBe(1)
        ->and(Receipt::attemptOf(['attempt' => 'x']))->toBe(1);
});

it('ranks delivered above queued and done/failed above delivered', function () {
    expect(Receipt::reached('delivered', 'queued'))->toBeTrue()
        ->and(Receipt::reached('queued', 'delivered'))->toBeFalse()
        ->and(Receipt::reached('done', 'delivered'))->toBeTrue()
        ->and(Receipt::reached('failed', 'delivered'))->toBeTrue()
        ->and(Receipt::READ_ORDER[0])->toBe('done');
});

it('builds a v2 receipt envelope keyed by job id and attempt', function () {
    $receipt = Receipt::envelope('job-1', 2, 'delivered', 'pi:abc', 'pi', ['latencyMs' => 12], 'the-shit/agent-bus');

    expect($receipt['v'])->toBe(2)
        ->and($receipt['type'])->toBe('receipt')
        ->and($receipt['jobId'])->toBe('job-1')
        ->and($receipt['attempt'])->toBe(2)
        ->and($receipt['payload'])->toMatchArray([
            'jobId' => 'job-1',
            'attempt' => 2,
            'state' => 'delivered',
            'sessionId' => 'pi:abc',
            'latencyMs' => 12,
        ]);
});

it('derives an inbox de-duplication id per session, job and attempt', function () {
    expect(Receipt::inboxMessageId('pi:abc', 'job-1', 1))->toBe(Receipt::inboxMessageId('pi:abc', 'job-1', 1))
        ->and(Receipt::inboxMessageId('pi:abc', 'job-1', 1))->not->toBe(Receipt::inboxMessageId('pi:abc', 'job-2', 1))
        // A requeued coord job is a new attempt, so JetStream lets it through.
        ->and(Receipt::inboxMessageId('pi:abc', 'job-1', 2))->not->toBe(Receipt::inboxMessageId('pi:abc', 'job-1', 1));
});
