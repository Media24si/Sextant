<?php

use App\Net\Real\CappedSink;

/* The response sink behind the HTTP probe and the versions client. */
it('keeps the first bytes and lets the rest of the download through', function () {
    $sink = new CappedSink(5);

    expect($sink->write('abc'))->toBe(3)
        ->and($sink->write('defgh'))->toBe(5)
        ->and($sink->overflowed)->toBeTrue()
        ->and((string) $sink)->toBe('abcde');
});

it('makes curl stop the transfer at the limit when asked to', function () {
    $sink = new CappedSink(4, abort: true);

    expect($sink->write('abcd'))->toBe(4)
        ->and($sink->overflowed)->toBeFalse()
        ->and($sink->write('e'))->toBe(0)
        ->and($sink->overflowed)->toBeTrue()
        ->and((string) $sink)->toBe('abcd');
});

it('keeps nothing with a limit of zero', function () {
    $sink = new CappedSink(0);

    expect($sink->write('<html>'))->toBe(6)
        ->and((string) $sink)->toBe('');
});
