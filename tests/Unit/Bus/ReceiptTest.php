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

it('puts each state on its own subject', function () {
    expect(Receipt::subject('job-1', 'queued'))->toBe('work.receipt.job-1.queued')
        ->and(Receipt::subject('job-1', 'delivered'))->toBe('work.receipt.job-1.delivered');
});

it('rejects unknown states', function () {
    Receipt::subject('job-1', 'acked');
})->throws(InvalidArgumentException::class);

it('ranks delivered above queued and done/failed above delivered', function () {
    expect(Receipt::reached('delivered', 'queued'))->toBeTrue()
        ->and(Receipt::reached('queued', 'delivered'))->toBeFalse()
        ->and(Receipt::reached('done', 'delivered'))->toBeTrue()
        ->and(Receipt::reached('failed', 'delivered'))->toBeTrue()
        ->and(Receipt::READ_ORDER[0])->toBe('done');
});

it('builds a v2 receipt envelope keyed by job id', function () {
    $receipt = Receipt::envelope('job-1', 'delivered', 'pi:abc', 'pi', ['latencyMs' => 12], 'the-shit/agent-bus');

    expect($receipt['v'])->toBe(2)
        ->and($receipt['type'])->toBe('receipt')
        ->and($receipt['jobId'])->toBe('job-1')
        ->and($receipt['payload'])->toMatchArray([
            'jobId' => 'job-1',
            'state' => 'delivered',
            'sessionId' => 'pi:abc',
            'latencyMs' => 12,
        ]);
});

it('derives a stable inbox de-duplication id from session and job', function () {
    expect(Receipt::inboxMessageId('pi:abc', 'job-1'))->toBe(Receipt::inboxMessageId('pi:abc', 'job-1'))
        ->and(Receipt::inboxMessageId('pi:abc', 'job-1'))->not->toBe(Receipt::inboxMessageId('pi:abc', 'job-2'));
});
