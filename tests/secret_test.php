<?php

declare(strict_types=1);

use Paymos\Wallet\PaymosError;
use Paymos\Wallet\VaultSecret;

$full = VaultSecret::pack('vk_live_abc123', 42, '{"share":"x"}');
$read = VaultSecret::pack('vk_live_abc123', 42, null);

test('packs then parses, full and read-only', function () use ($full, $read) {
    $f = VaultSecret::parse($full);
    eq('vk_live_abc123', $f->apiKey);
    eq(42, $f->vaultId);
    ok($f->hasShare());

    $r = VaultSecret::parse($read);
    ok(!$r->hasShare());
    eq(null, $r->share);
});

test('rejects anything that is not a vault secret', function () {
    foreach (['sk_prod_not_ours', 'vk_test_dev_key', '', 'vs_live_@@@'] as $bad) {
        throws(fn () => VaultSecret::parse($bad), PaymosError::class);
    }
});

test('rejects a secret whose api key is not one of ours', function () {
    throws(fn () => VaultSecret::parse(VaultSecret::pack('nope', 1, null)), PaymosError::class);
});

test('never renders the share and masks the key', function () use ($full) {
    $s = VaultSecret::parse($full);
    $rendered = (string)$s;
    ok(!str_contains($rendered, 'share":"x'), 'the share must never be rendered');
    ok(!str_contains($rendered, 'abc123'), 'the key must be masked');
    ok(str_contains($rendered, 'vk_live_'), 'the non-secret prefix is fine to show');
});
