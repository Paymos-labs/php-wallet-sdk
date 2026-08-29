<?php

declare(strict_types=1);

use Paymos\Wallet\Amounts;
use Paymos\Wallet\PaymosError;

test('parses human decimals into raw units', function () {
    eq('1500000', Amounts::parseUnits('1.5', 6));
    eq('1000000', Amounts::parseUnits('1', 6));
    eq('1', Amounts::parseUnits('0.000001', 6));
    eq('12', Amounts::parseUnits('12', 0));
});

test('rejects anything that is not a non-negative decimal', function () {
    foreach (['-1', 'abc', '', '1,5', '1.2.3', ' ', '1.'] as $bad) {
        throws(fn () => Amounts::parseUnits($bad, 6), PaymosError::class);
    }
    throws(fn () => Amounts::parseUnits('1.1234567', 6), PaymosError::class);
});

test('survives amounts a float would round', function () {
    // 18 decimals: past what an IEEE 754 double holds exactly, which is the reason these are
    // strings.
    $raw = Amounts::parseUnits('123456789.123456789123456789', 18);
    eq('123456789123456789123456789', $raw);
    eq('123456789.123456789123456789', Amounts::formatUnits($raw, 18));
});

test('formats raw units back, dropping trailing zeros', function () {
    eq('1.5', Amounts::formatUnits('1500000', 6));
    eq('1', Amounts::formatUnits('1000000', 6));
    eq('0.000001', Amounts::formatUnits('1', 6));
    eq('12', Amounts::formatUnits('12', 0));
});

test('round-trips', function () {
    foreach ([['1.5', 6], ['0.000001', 6], ['1000', 18], ['7', 0]] as [$human, $dec]) {
        eq($human, Amounts::formatUnits(Amounts::parseUnits($human, $dec), $dec));
    }
});

test('compares and adds raw amounts as strings, never as numbers', function () {
    eq(0, Amounts::cmpRaw('007', '7'));
    eq(-1, Amounts::cmpRaw('999', '1000'));
    eq(1, Amounts::cmpRaw('123456789123456789123456789', '2'));
    eq('1000000000000000000000000000', Amounts::addRaw('999999999999999999999999999', '1'));
    throws(fn () => Amounts::cmpRaw('abc', '1'), PaymosError::class);
});
