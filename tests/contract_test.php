<?php

// The wire contract: every response here is what the REAL server pipeline answered, recorded into
// sdk/contract/fixtures by the server's own test. None of it is mocked from memory — the last time
// a suite mocked the server its author imagined, it mocked {"assets": [...]}, passed, and no money
// call could succeed against the real one.

declare(strict_types=1);

require_once __DIR__ . '/contract_support.php';

use Paymos\Wallet\AuthError;
use Paymos\Wallet\Conflict;
use Paymos\Wallet\Forbidden;
use Paymos\Wallet\Http;
use Paymos\Wallet\Movement;
use Paymos\Wallet\PaymosError;
use Paymos\Wallet\Quote;
use Paymos\Wallet\Wallet;

const CONTRACT_TO = '0xabc0000000000000000000000000000000000000';
const CONTRACT_TS = '2026-01-01T00:00:00.0000000+00:00';

/** The request the SDK sent must be the fixture's: same method, same path, same body fields. */
function assertSentAsRecorded(array $sent, string $fixtureName, array $extra = []): void
{
    $want = fixture($fixtureName)['request'];
    eq($want['method'], $sent['method'], "$fixtureName method");
    eq($want['path'], $sent['path'], "$fixtureName path");
    $body = $want['body'] ?? null;
    if (is_array($body)) {
        $body = array_merge($body, $extra);
        ksort($body);
    }
    $got = $sent['body'];
    if (is_array($got)) {
        ksort($got);
    }
    eq($body, $got, "$fixtureName body");
}

/** Exactly this class — a subclass would mean the SDK invented a classification. */
function throwsExactly(callable $fn, string $class, int $status, string $message): PaymosError
{
    $e = throws($fn, $class);
    eq($class, get_class($e), 'error class');
    eq($status, $e->status, 'error status');
    eq($message, $e->getMessage(), 'error message');
    return $e;
}

function assertWithdrawQuote(Quote $q, string $fixtureName): void
{
    $b = fixture($fixtureName)['response']['body'];
    eq($b['movement_id'] ?? null, $q->movementId, 'movement_id');
    eq($b['mode'], $q->mode, 'mode');
    foreach (['send', 'debit'] as $leg) {
        eq($b[$leg]['amount'], $q->$leg->amount, "$leg.amount");
        eq($b[$leg]['asset'], $q->$leg->asset, "$leg.asset");
    }
    eq($b['receive']['amount'], $q->receive->amount, 'receive.amount');
    eq($b['receive']['min'], $q->receive->min, 'receive.min');
    eq($b['receive']['asset'], $q->receive->asset, 'receive.asset');
    eq($b['fees']['asset'], $q->fees->asset, 'fees.asset');
    eq($b['fees']['platform'], $q->fees->platform, 'fees.platform');
    eq($b['fees']['network'], $q->fees->network, 'fees.network');
    eq($b['fees']['total'], $q->fees->total, 'fees.total');
    eq($b['fees']['route']['amount'], $q->fees->route->amount, 'fees.route.amount');
    eq($b['fees']['route']['estimate'], $q->fees->route->estimate, 'fees.route.estimate');
    eq($b['fees']['usd'], $q->fees->usd, 'fees.usd');
    eq(CONTRACT_TS, $q->expiresAt, 'expires_at');
    eq($b['sources'] ?? null, $q->sources, 'sources');
}

function assertMovement(array $b, Movement $m): void
{
    eq($b['id'], $m->id, 'id');
    eq($b['type'], $m->type, 'type');
    eq($b['status'], $m->status, 'status');
    eq($b['send']['amount'], $m->send->amount, 'send.amount');
    eq($b['send']['asset'], $m->send->asset, 'send.asset');
    eq($b['receive']['amount'], $m->receive->amount, 'receive.amount');
    eq($b['receive']['min'], $m->receive->min, 'receive.min');
    eq($b['receive']['asset'], $m->receive->asset, 'receive.asset');
    eq(CONTRACT_TS, $m->createdAt, 'created_at');
    // Omitted by the server when null — absent, not null, and the model must not care.
    eq(null, $m->destChainTxHash, 'dest_chain_tx_hash');
    eq(null, $m->destChainExplorerUrl, 'dest_chain_explorer_url');
    eq(null, $m->completedAt, 'completed_at');
}

test('contract: the fixtures are found on disk', function () {
    ok(is_dir(contractFixturesDir()), 'fixtures dir');
    ok(count(glob(contractFixturesDir() . '/*.json')) >= 20, 'every recorded response is there');
});

test('contract: /assets is a bare array and parses field for field', function () {
    $s = contractServer();
    $assets = contractWallet($s)->assets();
    $want = fixture('assets')['response']['body'];
    eq(count($want), count($assets), 'every asset, none dropped');
    foreach ($want as $i => $a) {
        eq($a['asset'], $assets[$i]->asset);
        eq($a['symbol'], $assets[$i]->symbol);
        eq($a['chain'], $assets[$i]->chain);
        eq($a['decimals'], $assets[$i]->decimals);
        eq($a['fingerprint'], $assets[$i]->fingerprint);
    }
    assertSentAsRecorded($s->requests[0], 'assets');
});

test('contract: /balances is a bare array and parses field for field', function () {
    $s = contractServer()->route('GET', '/vault/v1/balances', 'balances');
    $balances = contractWallet($s)->balances();
    $want = fixture('balances')['response']['body'];
    eq(count($want), count($balances), 'every balance, none dropped');
    foreach ($want as $i => $b) {
        eq($b['asset'], $balances[$i]->asset);
        eq($b['symbol'], $balances[$i]->symbol);
        eq($b['chain'], $balances[$i]->chain);
        eq($b['decimals'], $balances[$i]->decimals);
        eq($b['amount_raw'], $balances[$i]->amountRaw);
        eq($b['usd'], $balances[$i]->usd);
    }
    assertSentAsRecorded($s->requests[0], 'balances');
});

test('contract: an unpriced balance omits usd and reads as null', function () {
    $body = fixture('balances')['response']['body'];
    unset($body[0]['usd']);
    $s = contractServer()->route('GET', '/vault/v1/balances', fn () => jsonResponse($body));
    eq(null, contractWallet($s)->balances()[0]->usd);
});

test('contract: quote_withdraw_dry — the request sent and every field read', function () {
    $s = contractServer()->route('POST', '/vault/v1/quote/withdraw', 'quote_withdraw_dry');
    $q = contractWallet($s)->quoteWithdraw('USDC@base', '10', CONTRACT_TO);
    $sent = $s->requestsTo('POST', '/vault/v1/quote/withdraw')[0];
    assertSentAsRecorded($sent, 'quote_withdraw_dry');
    ok(!isset($sent['headers']['idempotency-key']), 'a dry preview carries no Idempotency-Key');
    assertWithdrawQuote($q, 'quote_withdraw_dry');
    eq(null, $q->movementId, 'a dry preview persists nothing');
    eq(null, $q->sources, 'a dry preview has nothing to sign');
});

test('contract: quote_swap_dry — the request sent and every field read', function () {
    $s = contractServer()->route('POST', '/vault/v1/quote/swap', 'quote_swap_dry');
    $q = contractWallet($s)->quoteSwap('USDC@base', 'USDT@base', '5');
    $sent = $s->requestsTo('POST', '/vault/v1/quote/swap')[0];
    assertSentAsRecorded($sent, 'quote_swap_dry');
    ok(is_int($sent['body']['slippage_bps']), 'slippage_bps is an integer');
    ok(is_string($sent['body']['amount']), 'amount is a decimal string');
    ok($sent['body']['dry'] === true, 'dry is a boolean');
    ok(!isset($sent['headers']['idempotency-key']), 'a dry preview carries no Idempotency-Key');
    assertWithdrawQuote($q, 'quote_swap_dry');
    eq(true, $q->fees->route->estimate, 'a cross-asset route fee is an estimate');
});

test('contract: quote_withdraw, quote_withdraw_total_in, quote_swap parse every field', function () {
    foreach (['quote_withdraw', 'quote_withdraw_total_in', 'quote_swap'] as $name) {
        $q = Quote::fromWire(fixture($name)['response']['body']);
        assertWithdrawQuote($q, $name);
        ok($q->movementId !== null && $q->sources >= 1, "$name is a real, signable quote");
    }
});

test('contract: withdraw sends quote_withdraw, then one commitment per source', function () {
    $s = contractServer()
        ->route('POST', '/vault/v1/quote/withdraw', 'quote_withdraw')
        ->route('POST', '/vault/v1/movements/mv_1/sign/begin', 'sign_begin');
    $w = contractWallet($s, contractShare());
    // The fixture echoes what was approved, so the SDK gets as far as round one; the recorded
    // sign/begin holds ONE package for the quote's TWO sources, and that is refused.
    $e = throws(fn () => $w->withdraw('USDC@base', '10', CONTRACT_TO, idempotencyKey: 'payout-42'));
    eq('co-sign protocol mismatch: expected 2 signing packages, got 1', $e->getMessage());

    $sent = $s->requestsTo('POST', '/vault/v1/quote/withdraw')[0];
    assertSentAsRecorded($sent, 'quote_withdraw', ['dry' => false]);
    eq('payout-42', $sent['headers']['idempotency-key'], 'the caller\'s idempotency key');
    $begin = $s->requestsTo('POST', '/vault/v1/movements/mv_1/sign/begin');
    eq(1, count($begin));
    eq(2, count($begin[0]['body']['commitments']), 'sources: 2 → two commitments');
    eq([], $s->requestsTo('POST', '/vault/v1/movements/mv_1/sign/aggregate'), 'nothing aggregated');
});

test('contract: withdraw total_in sends quote_withdraw_total_in and checks the debit, not the send', function () {
    $s = contractServer()
        ->route('POST', '/vault/v1/quote/withdraw', 'quote_withdraw_total_in')
        ->route('POST', '/vault/v1/movements/mv_2/sign/begin', 'sign_begin');
    $w = contractWallet($s, contractShare());
    // send is 9975062 of an approved total of 10 — pinning send would refuse here, before round one.
    $e = throws(fn () => $w->withdraw('USDC@base', '10', CONTRACT_TO, 'total_in'));
    eq('co-sign protocol mismatch: expected 2 signing packages, got 1', $e->getMessage());
    $sent = $s->requestsTo('POST', '/vault/v1/quote/withdraw')[0];
    assertSentAsRecorded($sent, 'quote_withdraw_total_in', ['dry' => false]);
    ok(preg_match('/^[0-9a-f]{32}$/', $sent['headers']['idempotency-key']) === 1, 'a generated idempotency key');
});

test('contract: swap sends quote_swap, and the placeholder sign_begin is refused unsigned', function () {
    $s = contractServer()
        ->route('POST', '/vault/v1/quote/swap', 'quote_swap')
        ->route('POST', '/vault/v1/movements/mv_3/sign/begin', 'sign_begin');
    $w = contractWallet($s, contractShare());
    // sign_begin.json parses — one package, one disclosure, a token — but its values are
    // placeholders: the blind-sign guard must refuse them, and nothing may reach aggregate.
    $e = throws(fn () => $w->swap('USDC@base', 'USDT@base', '5'));
    ok(str_starts_with($e->getMessage(), 'co-sign refused:'), $e->getMessage());
    assertSentAsRecorded($s->requestsTo('POST', '/vault/v1/quote/swap')[0], 'quote_swap', ['dry' => false]);
    eq(1, count($s->requestsTo('POST', '/vault/v1/movements/mv_3/sign/begin')[0]['body']['commitments']));
    eq([], $s->requestsTo('POST', '/vault/v1/movements/mv_3/sign/aggregate'), 'nothing aggregated');
});

test('contract: movements_page — items, omitted fees, next_cursor', function () {
    $s = contractServer()->route('GET', '/vault/v1/movements?limit=2', 'movements_page');
    [$items, $next] = contractWallet($s)->movements(2);
    assertSentAsRecorded($s->requests[0], 'movements_page');
    $want = fixture('movements_page')['response']['body'];
    eq(count($want['items']), count($items));
    foreach ($want['items'] as $i => $b) {
        assertMovement($b, $items[$i]);
        eq(null, $items[$i]->fees, 'a history row carries no fee legs');
    }
    eq('cursor_1', $next);
});

test('contract: movements follows next_cursor and stops when it is omitted', function () {
    $s = contractServer()->route('GET', '/vault/v1/movements?limit=2&cursor=cursor_1',
        fn () => jsonResponse(['items' => []]));
    [$items, $next] = contractWallet($s)->movements(2, 'cursor_1');
    eq([], $items);
    eq(null, $next, 'the last page omits next_cursor');
});

test('contract: movement — every field, fees included', function () {
    $s = contractServer()->route('GET', '/vault/v1/movements/mv_3', 'movement');
    $m = contractWallet($s)->movement('mv_3');
    assertSentAsRecorded($s->requests[0], 'movement');
    $b = fixture('movement')['response']['body'];
    assertMovement($b, $m);
    eq($b['fees']['asset'], $m->fees->asset);
    eq($b['fees']['platform'], $m->fees->platform);
    eq($b['fees']['network'], $m->fees->network);
    eq($b['fees']['total'], $m->fees->total);
    eq($b['fees']['route']['amount'], $m->fees->route->amount);
    eq(true, $m->fees->route->estimate);
    eq($b['fees']['usd'], $m->fees->usd);
});

test('contract: every error fixture maps to its typed error and message', function () {
    $cases = [
        'error_400' => function () {
            $s = contractServer()->route('POST', '/vault/v1/quote/withdraw', 'error_400');
            throwsExactly(fn () => contractWallet($s)->quoteWithdraw('USDC@base', '10', CONTRACT_TO, 'exact-out'),
                PaymosError::class, 400, 'invalid mode');
            assertSentAsRecorded($s->requestsTo('POST', '/vault/v1/quote/withdraw')[0], 'error_400', ['dry' => true]);
        },
        'error_400_body' => function () {
            // The SDK cannot send a body that is not JSON; the answer still maps the same way.
            $s = contractServer()->route('POST', '/vault/v1/quote/withdraw', 'error_400_body');
            throwsExactly(fn () => contractWallet($s)->quoteWithdraw('USDC@base', '10', CONTRACT_TO),
                PaymosError::class, 400, 'invalid request — the body must be JSON matching this endpoint');
        },
        'error_401' => function () {
            $s = contractServer()->route('GET', '/vault/v1/balances', 'error_401');
            throwsExactly(fn () => contractWallet($s)->balances(), AuthError::class, 401, 'invalid or missing API key');
            assertSentAsRecorded($s->requests[0], 'error_401');
        },
        'error_403' => function () {
            $s = contractServer()->route('POST', '/vault/v1/quote/withdraw', 'error_403');
            throwsExactly(fn () => contractWallet($s)->quoteWithdraw('USDC@base', '10', CONTRACT_TO),
                Forbidden::class, 403, 'this key lacks the required scope');
            assertSentAsRecorded($s->requestsTo('POST', '/vault/v1/quote/withdraw')[0], 'error_403', ['dry' => true]);
        },
        'error_404_movement' => function () {
            $s = contractServer()->route('GET', '/vault/v1/movements/does-not-exist', 'error_404_movement');
            throwsExactly(fn () => contractWallet($s)->movement('does-not-exist'), PaymosError::class, 404,
                'movement not found');
            assertSentAsRecorded($s->requests[0], 'error_404_movement');
        },
        'error_404_path' => function () {
            $s = contractServer()->route('GET', '/vault/v1/no-such-endpoint', 'error_404_path');
            $http = new Http('https://vault.test', 'vk_live_contract', 60, $s->transport());
            throwsExactly(fn () => $http->get('/vault/v1/no-such-endpoint'), PaymosError::class, 404, 'not found');
        },
        'error_409_idempotency' => function () {
            $s = contractServer()->route('POST', '/vault/v1/quote/withdraw', 'error_409_idempotency');
            $w = contractWallet($s, '{"verifying_key":"unused"}');
            throwsExactly(fn () => $w->withdraw('USDC@base', '11', CONTRACT_TO, idempotencyKey: 'payout-42'),
                Conflict::class, 409, 'idempotency key reused with a different request');
            assertSentAsRecorded($s->requestsTo('POST', '/vault/v1/quote/withdraw')[0], 'error_409_idempotency',
                ['dry' => false]);
        },
    ];
    // A new error fixture with no case here fails, instead of passing untested.
    foreach (glob(contractFixturesDir() . '/error_*.json') as $file) {
        $name = basename($file, '.json');
        ok(isset($cases[$name]), "error fixture $name has a case");
        $cases[$name]();
    }
});

test('contract: sign_begin_error on a still-pending movement re-raises, never converges', function () {
    $s = contractServer()
        ->route('POST', '/vault/v1/quote/swap', 'quote_swap')
        ->route('POST', '/vault/v1/movements/mv_3/sign/begin', 'sign_begin_error')
        ->route('GET', '/vault/v1/movements/mv_3', 'movement'); // status: pending
    throwsExactly(fn () => contractWallet($s, contractShare())->swap('USDC@base', 'USDT@base', '5'),
        PaymosError::class, 400, 'commitments are required');
});

test('contract: sign_aggregate and sign_aggregate_error bodies read as the server means them', function () {
    $ok = fixture('sign_aggregate')['response'];
    eq(200, $ok['status']);
    eq(true, $ok['body']['ok']);
    $bad = fixture('sign_aggregate_error')['response'];
    // The 400 is mapped by the transport; its message is the "error" field, not the status word.
    $s = contractServer()->route('POST', '/vault/v1/movements/mv_3/sign/aggregate', 'sign_aggregate_error');
    $http = new Http('https://vault.test', 'vk_live_contract', 60, $s->transport());
    throwsExactly(fn () => $http->post('/vault/v1/movements/mv_3/sign/aggregate', ['token' => '', 'shares' => []]),
        PaymosError::class, $bad['status'], $bad['body']['error']);
    assertSentAsRecorded($s->requests[0], 'sign_aggregate_error');
    // The full round — a real signature, then these two bodies — runs in cosign_test.php.
});

test('contract: every public status is classified exactly as the Python SDK does', function () {
    $vocab = fixture('vocabulary');
    $terminal = (new \ReflectionClassConstant(Wallet::class, 'TERMINAL'))->getValue();
    $progressed = (new \ReflectionClassConstant(Wallet::class, 'PROGRESSED'))->getValue();
    $sorted = function (array $a) {
        sort($a);
        return $a;
    };
    // Python: _TERMINAL_STATUSES and _PROGRESSED_STATUSES.
    eq(['cancelled', 'completed', 'expired', 'failed', 'refunded'], $sorted($terminal), 'terminal set');
    eq(['completed', 'processing', 'refunded'], $sorted($progressed), 'progressed set');
    // No internal word the server never sends ("signed", "relaying", "settling"…).
    foreach (array_merge($terminal, $progressed) as $status) {
        ok(in_array($status, $vocab['movement_statuses'], true), "$status is on the wire");
    }
    // And the behaviour, per status: wait() returns on a terminal one and keeps polling otherwise.
    $terminal = ['completed', 'failed', 'refunded', 'expired', 'cancelled'];
    foreach ($vocab['movement_statuses'] as $status) {
        $body = fixture('movement')['response']['body'];
        $body['status'] = $status;
        $s = contractServer()->route('GET', '/vault/v1/movements/mv_3', fn () => jsonResponse($body));
        $w = contractWallet($s);
        if (in_array($status, $terminal, true)) {
            eq($status, $w->wait('mv_3', 0)->status, "$status is terminal");
        } else {
            $e = throws(fn () => $w->wait('mv_3', 0));
            eq('timed out waiting for movement mv_3', $e->getMessage(), "$status is still in flight");
        }
    }
});

test('contract: a proxy\'s HTML 404 is a typed error that says what happened', function () {
    $html = "<html>\r\n<head><title>404 Not Found</title></head>\r\n<body>\r\n<center><h1>404 Not Found</h1></center>\r\n"
        . "<hr><center>nginx</center>\r\n</body>\r\n</html>\r\n";
    $s = (new ContractServer())->route('GET', '/vault/v1/assets',
        fn () => [404, ['Content-Type' => 'text/html'], $html]);
    $e = throwsExactly(fn () => contractWallet($s)->assets(), PaymosError::class, 404,
        'unexpected non-JSON response (HTTP 404, text/html) — check that the base URL (PAYMOS_BASE_URL) '
        . 'points at the Paymos wallet API: <html> <head><title>404 Not Found</title></head> <body> '
        . '<center><h1>404 Not Found</h1></center> <hr><center>nginx</center> </body> </html>');
    // The same page on a quote, where it once became a TypeError deep in the parser.
    $s->route('GET', '/vault/v1/assets', 'assets')
        ->route('POST', '/vault/v1/quote/swap', fn () => [404, ['Content-Type' => 'text/html'], $html]);
    $e = throws(fn () => contractWallet($s)->quoteSwap('USDC@base', 'USDT@base', '5'));
    eq(404, $e->status);
});

test('contract: PAYMOS_BASE_URL overrides the default, an explicit base URL overrides both', function () {
    $seen = [];
    $stub = function ($method, $url) use (&$seen) {
        $seen[] = $url;
        return [200, [], fixtureBodyText('assets')];
    };
    $secret = \Paymos\Wallet\VaultSecret::pack('vk_live_contract', 7, null);
    $old = getenv('PAYMOS_BASE_URL');
    try {
        putenv('PAYMOS_BASE_URL');
        (new Wallet($secret, transport: $stub))->assets();
        putenv('PAYMOS_BASE_URL=https://staging.example/');
        (new Wallet($secret, transport: $stub))->assets();
        (new Wallet($secret, 'https://explicit.example', transport: $stub))->assets();
    } finally {
        putenv($old === false ? 'PAYMOS_BASE_URL' : "PAYMOS_BASE_URL=$old");
    }
    eq(['https://wallet.paymos.io/vault/v1/assets', 'https://staging.example/vault/v1/assets',
        'https://explicit.example/vault/v1/assets'], $seen);
});
