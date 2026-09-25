<?php

// The error mapping, which is the whole reason this layer exists: a caller branches on a class
// instead of matching a string, and the classes must land the same way they do in Python.

declare(strict_types=1);

use Paymos\Wallet\AuthError;
use Paymos\Wallet\Conflict;
use Paymos\Wallet\CrossAssetWithdrawNotAllowed;
use Paymos\Wallet\Forbidden;
use Paymos\Wallet\InsufficientFunds;
use Paymos\Wallet\PaymosError;
use Paymos\Wallet\QuoteExpired;
use Paymos\Wallet\RateLimited;
use Paymos\Wallet\RouteUnavailable;
use Paymos\Wallet\SlippageExceeded;
use Paymos\Wallet\VaultSecret;
use Paymos\Wallet\Wallet;

function walletOver(int $status, string $body, array $headers = []): Wallet
{
    $stub = fn ($method, $url, $reqHeaders, $reqBody) => [$status, $headers, $body];
    return new Wallet(VaultSecret::pack('vk_live_x', 1, null), 'https://example.test',
        transport: $stub);
}

test('returns parsed json on a 2xx', function () {
    // /assets answers a BARE array — the {"assets": [...]} this test once mocked was never sent.
    $w = walletOver(200, '[{"asset":"USDC@base","symbol":"USDC","chain":"base","decimals":6}]');
    $assets = $w->assets();
    eq(1, count($assets));
    eq('USDC@base', $assets[0]->asset);
});

test('maps status codes to types', function () {
    $cases = [
        [401, AuthError::class],
        [403, Forbidden::class],
        [409, Conflict::class],
        [404, PaymosError::class],
        [500, PaymosError::class],
    ];
    foreach ($cases as [$status, $class]) {
        $err = throws(fn () => walletOver($status, '{"error":"boom"}')->assets(), $class);
        eq($status, $err->status, "status $status");
    }
});

test('classifies a 400 by its message, first match winning', function () {
    $cases = [
        ['cross-asset withdraw is not allowed', CrossAssetWithdrawNotAllowed::class],
        ['slippage exceeded', SlippageExceeded::class],
        ['quote expired', QuoteExpired::class],
        ['insufficient balance', InsufficientFunds::class],
        ['available balance is lower', InsufficientFunds::class],
        ['No liquidity available, try again in a moment', RouteUnavailable::class],
        ["route can't be quoted", RouteUnavailable::class],
        ['something else entirely', PaymosError::class],
    ];
    foreach ($cases as [$message, $class]) {
        throws(fn () => walletOver(400, json_encode(['error' => $message]))->assets(), $class);
    }
});

test('carries Retry-After on a 429 when the server sends one', function () {
    $err = throws(fn () => walletOver(429, '{"error":"slow down"}', ['retry-after' => '12'])->assets(),
        RateLimited::class);
    eq(12, $err->retryAfter);

    $err = throws(fn () => walletOver(429, '{"error":"slow down"}')->assets(), RateLimited::class);
    eq(null, $err->retryAfter);
});

test('names a non-JSON body instead of failing to parse it', function () {
    $err = throws(fn () => walletOver(500, 'upstream exploded', ['Content-Type' => 'text/plain'])->assets());
    eq(500, $err->status);
    ok(str_contains($err->getMessage(), 'non-JSON response (HTTP 500, text/plain)'), $err->getMessage());
    ok(str_contains($err->getMessage(), 'upstream exploded'), 'quotes the body');
});

test('falls back to the JSON body when it carries no error field', function () {
    $err = throws(fn () => walletOver(502, '{"message":"bad gateway"}')->assets());
    eq('{"message":"bad gateway"}', $err->getMessage());
});

test('a 2xx that is not JSON is a typed error, not a null', function () {
    $err = throws(fn () => walletOver(200, '<html><body>maintenance</body></html>',
        ['content-type' => 'text/html; charset=utf-8'])->balances());
    ok(str_contains($err->getMessage(), 'HTTP 200, text/html'), $err->getMessage());
});

test('an object where the server answers an array is an error, never an empty catalog', function () {
    // The exact defect that shipped: the wrapper shape silently became [] and every money call
    // then failed with "unknown asset".
    $err = throws(fn () => walletOver(200, '{"assets":[{"asset":"USDC@base","symbol":"USDC","chain":"base","decimals":6}]}')->assets());
    ok(str_contains($err->getMessage(), 'not a JSON array'), $err->getMessage());
    throws(fn () => walletOver(200, '{"balances":[]}')->balances());
});

test('cuts a huge body down to a snippet', function () {
    $err = throws(fn () => walletOver(503, str_repeat('<p>busy</p> ', 5000))->assets());
    ok(strlen($err->getMessage()) < 400, 'message stays short: ' . strlen($err->getMessage()));
});

test('wraps a transport failure instead of leaking it', function () {
    $failing = function () {
        throw new \RuntimeException('connection reset');
    };
    // The transport seam is exercised in error too — but a THROWING stub is the curl path's job;
    // here we assert the SDK surface only ever throws PaymosError.
    $w = new Wallet(VaultSecret::pack('vk_live_x', 1, null), 'https://example.test', transport: $failing);
    $err = throws(fn () => $w->assets(), PaymosError::class);
});

test('sends the bearer token and the idempotency key', function () {
    $seen = [];
    $stub = function ($method, $url, $reqHeaders, $reqBody) use (&$seen) {
        $seen = ['url' => $url, 'headers' => $reqHeaders];
        if (str_ends_with(parse_url($url, PHP_URL_PATH), '/vault/v1/assets')) {
            return [200, [], '[{"asset":"USDC@base","symbol":"USDC","chain":"base","decimals":6}]'];
        }
        // A deliberately wrong asset: the echo check fails AFTER the request was seen.
        return [200, [], '{"movement_id":"m1","mode":"exact_out",' .
            '"send":{"amount":"24000000","asset":"OTHER"},' .
            '"debit":{"amount":"25000000","asset":"USDC@base"},' .
            '"receive":{"amount":"25000000","min":"25000000","asset":"USDC@base"},' .
            '"fees":{"asset":"USDC@base","platform":"0","network":"0","total":"0"},' .
            '"expires_at":"2026-01-01T00:00:00+00:00","sources":1}'];
    };
    $w = new Wallet(VaultSecret::pack('vk_live_secret', 1, '{"k":1}'), 'https://example.test',
        transport: $stub);
    $err = throws(fn () => $w->withdraw('USDC@base', '25', '0xRecipient', idempotencyKey: 'abc'));
    ok(str_contains($err->getMessage(), 'OTHER'), 'refused by the echo check: ' . $err->getMessage());
    eq('Bearer vk_live_secret', $seen['headers']['authorization']);
    eq('abc', $seen['headers']['idempotency-key']);
});
