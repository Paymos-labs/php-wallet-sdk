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

test('var_dump, print_r and json_encode of a secret, a wallet or a job holding them show no credential', function () use ($full) {
    $s = VaultSecret::parse($full);
    $w = new \Paymos\Wallet\Wallet($full, 'https://vault.test', transport: fn () => [200, [], '[]']);
    ob_start();
    var_dump($s, $w, ['job' => ['wallet' => $w]]);
    $dump = (string)ob_get_clean();
    $rendered = [$dump, print_r($s, true), print_r($w, true), print_r(['job' => ['wallet' => $w, 'secret' => $s]], true),
        (string)json_encode($s), (string)json_encode(['wallet' => $w, 'secret' => $s]), (string)$s];
    foreach ($rendered as $r) {
        ok(!str_contains($r, 'abc123'), "the key leaked: $r");
        ok(!str_contains($r, 'share":"x') && !str_contains($r, 'share\":\"x'), "the share leaked: $r");
    }
    ok(str_contains($dump, 'vk_live_'), 'the non-secret prefix is fine to show');
});

test('an empty idempotency key is refused before anything reaches the server', function () {
    // It used to go out as an empty header, which the server reads as no key at all.
    $seen = [];
    $w = new \Paymos\Wallet\Wallet(VaultSecret::pack('vk_live_test', 1, '{"verifying_key":"vk"}'), 'https://vault.test',
        transport: function (string $method, string $url) use (&$seen): array {
            $seen[] = $url;
            throw new \LogicException("nothing may reach the server: $url");
        });
    foreach (['', ' ', "\t"] as $key) {
        $e = throws(fn () => $w->withdraw('USDC@base', '10', '0xabc', idempotencyKey: $key));
        ok(str_starts_with($e->getMessage(), 'idempotencyKey is empty'), $e->getMessage());
        $e = throws(fn () => $w->swap('USDC@base', 'USDT@base', '5', idempotencyKey: $key));
        ok(str_starts_with($e->getMessage(), 'idempotencyKey is empty'), $e->getMessage());
    }
    eq([], $seen, 'nothing reached the server');
});

test('a stack trace through parse or the constructor does not carry the secret as an argument', function () {
    if (PHP_VERSION_ID < 80200) {
        return; // #[\SensitiveParameter] redacts from PHP 8.2; earlier it is an inert attribute
    }
    ini_set('zend.exception_ignore_args', '0');
    $bad = 'vs_live_' . rtrim(strtr(base64_encode('{"k":"vk_live_abc123","v":"no id"}'), '+/', '-_'), '=');
    foreach ([fn () => VaultSecret::parse($bad), fn () => new \Paymos\Wallet\Wallet($bad)] as $call) {
        $e = throws($call);
        ok(!str_contains($e->getTraceAsString(), 'abc123'), $e->getTraceAsString());
        // Every argument the SDK's own frames recorded: the secret is a SensitiveParameterValue there.
        // (The test's closure captured $bad itself; that frame is the test's, not the SDK's.)
        foreach ($e->getTrace() as $frame) {
            if (str_starts_with($frame['class'] ?? '', 'Paymos\\')) {
                foreach ($frame['args'] ?? [] as $arg) {
                    ok($arg !== $bad, "{$frame['class']}::{$frame['function']} recorded the secret as an argument");
                }
            }
        }
    }
});
