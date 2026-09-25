<?php

// The co-sign, end to end and offline: the SDK against a test that plays the server's half with
// the same native core the server runs. The quote and movement bodies are the recorded contract
// fixtures; sign/begin is built for real, so the signature at the end verifies — and every way a
// server could ask for something other than what was approved is refused before a share exists.

declare(strict_types=1);

require_once __DIR__ . '/contract_support.php';

use Paymos\Wallet\Digest;
use Paymos\Wallet\Native\Core;
use Paymos\Wallet\PaymosError;
use Paymos\Wallet\SlippageExceeded;

/**
 * The server's side of one withdraw: quote_withdraw.json (two sources, debit 10025000), a real
 * sign/begin over disclosed transfer messages, a real aggregate that verifies the
 * signature, and movement.json. $tamper edits the disclosure before it is sent, the way a lying
 * server would.
 */
final class CosignServer
{
    public ContractServer $http;
    /** @var list<bool> one entry per verified aggregate */
    public array $verified = [];
    /** @var list<mixed> the transfer amount of each source, as disclosed */
    public array $amounts = ['5000000', '5025000'];
    /** The raw id of the token each source moves; sha256("usdc-base") is USDC@base's fingerprint in assets.json. */
    public string $tokenKey = 'usdc-base';
    /** @var null|callable(array $messages, array $packages): array{0: array, 1: array} */
    public $tamper = null;
    /** @var null|string|callable a fixture name or responder for sign/aggregate */
    public $aggregateResponse = 'sign_aggregate';
    private array $sessions = [];

    public function __construct(string $quoteFixture = 'quote_withdraw', string $mid = 'mv_1')
    {
        $keys = contractDkg();
        $this->http = contractServer()
            ->route('POST', '/vault/v1/quote/withdraw', $quoteFixture)
            ->route('POST', '/vault/v1/quote/swap', $quoteFixture)
            ->route('GET', "/vault/v1/movements/$mid", 'movement')
            ->route('POST', "/vault/v1/movements/$mid/sign/begin", function (array $body) use ($keys) {
                $messages = [];
                $packages = [];
                $this->sessions = [];
                foreach ($body['commitments'] as $i => $clientCommitment) {
                    $message = json_encode([
                        'deadline' => '2026-01-01T00:00:00.000Z',
                        'intents' => [[
                            'intent' => 'transfer',
                            'receiver_id' => 'payee.example',
                            'tokens' => [$this->tokenKey => $this->amounts[$i] ?? '0'],
                        ]],
                        'signer_id' => $keys['group'],
                    ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
                    $nonce = random_bytes(32);
                    $server = Core::callOp(['op' => 'commit', 'key_package' => $keys['server']]);
                    $pkg = Core::callOp(['op' => 'build_signing_package',
                        'commitments' => [[1, $clientCommitment], [2, $server['commitments']]],
                        'message_hex' => Digest::payloadDigestHex($message, $nonce, 'recipient.example')]);
                    $this->sessions[] = ['pkg' => $pkg['signing_package'], 'nonces' => $server['nonces']];
                    $packages[] = $pkg['signing_package'];
                    $messages[] = ['message' => $message, 'nonce' => bin2hex($nonce), 'recipient' => 'recipient.example'];
                }
                if ($this->tamper !== null) {
                    [$messages, $packages] = ($this->tamper)($messages, $packages);
                }
                return jsonResponse(['token' => 'sign-session-token', 'signing_packages' => $packages,
                    'messages' => $messages]);
            })
            ->route('POST', "/vault/v1/movements/$mid/sign/aggregate", function (array $body) use ($keys) {
                foreach ($body['shares'] as $i => $clientShare) {
                    $session = $this->sessions[$i];
                    $serverShare = Core::callOp(['op' => 'sign', 'signing_package' => $session['pkg'],
                        'nonces' => $session['nonces'], 'key_package' => $keys['server']]);
                    $agg = Core::callOp(['op' => 'aggregate', 'signing_package' => $session['pkg'],
                        'signature_shares' => [[1, $clientShare], [2, $serverShare['signature_share']]],
                        'public_key_package' => $keys['public']]);
                    $this->verified[] = $agg['verified'] === true;
                }
                $r = $this->aggregateResponse;
                return is_callable($r) ? $r() : [fixture($r)['response']['status'], [], fixtureBodyText($r)];
            });
    }

    public function wallet(?array $pinnedFingerprints = null): \Paymos\Wallet\Wallet
    {
        return contractWallet($this->http, contractShare(), $pinnedFingerprints);
    }

    /** Serve /assets with USDC@base carrying these fingerprints, one catalog entry each (none: omitted). */
    public function publishUsdc(string ...$rawIds): self
    {
        $catalog = fixture('assets')['response']['body'];
        $usdc = $catalog[0];
        unset($usdc['fingerprint']);
        $entries = $rawIds === [] ? [$usdc] : array_map(fn ($id) => $usdc + ['fingerprint' => hash('sha256', $id)], $rawIds);
        $this->http->route('GET', '/vault/v1/assets', fn () => jsonResponse(array_merge($entries, [$catalog[1]])));
        return $this;
    }

    public function aggregated(): int
    {
        return count(array_filter($this->http->requests, fn ($r) => str_ends_with($r['path'], '/sign/aggregate')));
    }
}

test('cosign: a withdraw signs both sources and the signatures verify', function () {
    $srv = new CosignServer();
    $mv = $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO, maxDebit: '10.025');
    eq([true, true], $srv->verified, 'one verified ed25519 signature per source');
    eq('mv_3', $mv->id, 'the movement is fetched after the co-sign');
    $agg = $srv->http->requestsTo('POST', '/vault/v1/movements/mv_1/sign/aggregate')[0]['body'];
    eq('sign-session-token', $agg['token'], 'the session token goes back');
    eq(2, count($agg['shares']));
});

test('cosign: a lying server is refused before a single share exists', function () {
    $other = str_repeat('ab', 32);
    $cases = [
        'no disclosure' => fn ($m, $p) => [[], $p],
        'one disclosure short' => fn ($m, $p) => [[$m[0]], $p],
        'inflated amount' => function ($m, $p) {
            // A message rebuilt to move more: the package no longer binds to it.
            $m[1]['message'] = str_replace('"5025000"', '"9025000"', $m[1]['message']);
            return [$m, $p];
        },
        'other recipient' => function ($m, $p) {
            $m[0]['recipient'] = 'attacker.example';
            return [$m, $p];
        },
        'packages swapped' => fn ($m, $p) => [$m, [$p[1], $p[0]]],
        'short nonce' => function ($m, $p) {
            $m[0]['nonce'] = '00ff';
            return [$m, $p];
        },
    ];
    foreach ($cases as $what => $tamper) {
        $srv = new CosignServer();
        $srv->tamper = $tamper;
        $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
        ok(str_starts_with($e->getMessage(), 'co-sign refused:'), "$what: " . $e->getMessage());
        eq(0, $srv->aggregated(), "$what: nothing aggregated");
    }
    // Honestly bound messages that still must not be signed: the server builds the package over
    // exactly what it discloses, so only the content checks can catch these.
    $honest = [
        'more than the approved debit' => [['5000000', '5025001'], null],
        // debit D = 10025000: A = 2D alone moves twice the approval, B = -D makes the sum read D.
        'a negative source hiding an inflated one (2D, -D)' => [['20050000', '-10025000'], null],
        'a negative amount' => [['-900', '5025000'], null],
        'a signed amount' => [['+5', '5025000'], null],
        'an empty amount' => [['', '5025000'], null],
        'a zero amount' => [['0', '5025000'], null],
        'zeros' => [['000', '5025000'], null],
        'a double zero' => [['00', '5025000'], null],
        'minus one' => [['-1', '5025000'], null],
        'a leading space' => [[' 5', '5025000'], null],
        'a trailing newline' => [["5000000\n", '5025000'], null],
        'a JSON number' => [[5000000, '5025000'], null],
        'a fraction' => [['5000000.0', '5025000'], null],
        'from another vault' => [['5000000', '5025000'], fn ($msg) => str_replace(contractDkg()['group'], $other, $msg)],
        'two intents' => [['5000000', '5025000'], fn ($msg) => preg_replace('/"intents":\[(\{.*?\})\]/',
            '"intents":[$1,$1]', $msg)],
        'a non-transfer intent' => [['5000000', '5025000'], fn ($msg) => str_replace('"transfer"', '"ft_withdraw"', $msg)],
    ];
    foreach ($honest as $what => [$amounts, $rewrite]) {
        $srv = new CosignServer();
        $srv->amounts = $amounts;
        if ($rewrite !== null) {
            $srv->tamper = function ($m, $p) use ($rewrite) {
                // Re-disclose the rewritten message AND rebuild what it binds to, as a server
                // that controls both would.
                foreach ($m as $i => $d) {
                    $m[$i]['message'] = $rewrite($d['message']);
                    $p[$i]['message'] = Digest::payloadDigestHex($m[$i]['message'], hex2bin($d['nonce']), $d['recipient']);
                }
                return [$m, $p];
            };
        }
        $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
        ok(str_starts_with($e->getMessage(), 'co-sign refused:'), "$what: " . $e->getMessage());
        eq(0, $srv->aggregated(), "$what: nothing aggregated");
    }
});

test('cosign: sign_aggregate_error is raised with the server\'s message, never swallowed', function () {
    $srv = new CosignServer();
    $srv->aggregateResponse = 'sign_aggregate_error';
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
    eq(PaymosError::class, get_class($e));
    eq(400, $e->status);
    eq(fixture('sign_aggregate_error')['response']['body']['error'], $e->getMessage());

    // A 200 that still says ok:false is a failure too.
    $srv = new CosignServer();
    $srv->aggregateResponse = fn () => jsonResponse(['ok' => false, 'status' => 'failed', 'error' => 'relay rejected']);
    eq('relay rejected', throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO))->getMessage());
});

test('cosign: every sign status the server answers with ok:true completes the call', function () {
    foreach (fixture('vocabulary')['sign_statuses'] as $status) {
        $srv = new CosignServer();
        $srv->aggregateResponse = fn () => jsonResponse(['ok' => true, 'status' => $status]);
        eq('mv_3', $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO)->id, "sign status $status");
    }
});

test('cosign: a retry converges on a progressed movement and re-raises on any other', function () {
    // Python's _PROGRESSED_STATUSES, written out: read from the SDK, this test could only agree with it.
    $progressed = ['processing', 'completed', 'refunded'];
    foreach (fixture('vocabulary')['movement_statuses'] as $status) {
        $srv = new CosignServer();
        $body = fixture('movement')['response']['body'];
        $body['status'] = $status;
        $srv->http
            ->route('GET', '/vault/v1/movements/mv_1', fn () => jsonResponse($body))
            ->route('POST', '/vault/v1/movements/mv_1/sign/begin', fn () => jsonResponse(
                ['error' => "this movement is no longer awaiting a signature (status: $status)"], 400));
        $call = fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO, idempotencyKey: 'payout-42');
        if (in_array($status, $progressed, true)) {
            eq($status, $call()->status, "$status: already signed by a prior attempt, converge");
        } else {
            $e = throws($call);
            eq("this movement is no longer awaiting a signature (status: $status)", $e->getMessage(),
                "$status: the original error, not a silent success");
        }
        eq(0, $srv->aggregated(), "$status: never signed twice");
    }
});

test('cosign: the echo and cap checks refuse before round one', function () {
    // exact_out: the quote must deliver exactly what was approved.
    $srv = new CosignServer();
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '9', CONTRACT_TO));
    ok(str_contains($e->getMessage(), 'not the approved 9000000'), $e->getMessage());
    // total_in: the debit may not exceed the approved total.
    $srv = new CosignServer('quote_withdraw_total_in', 'mv_2');
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '9.99', CONTRACT_TO, 'total_in'));
    ok(str_contains($e->getMessage(), 'above the approved 9990000'), $e->getMessage());
    // max_debit: the caller's ceiling on a server-computed exact_out debit.
    $srv = new CosignServer();
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO, maxDebit: '10.024999'));
    ok(str_contains($e->getMessage(), 'above the cap 10024999'), $e->getMessage());
    // min_receive: the guaranteed floor of a swap; a local refusal carries no HTTP status.
    $srv = new CosignServer('quote_swap', 'mv_3');
    $e = throws(fn () => $srv->wallet()->swap('USDC@base', 'USDT@base', '5', minReceive: '5.000001'),
        SlippageExceeded::class);
    eq(null, $e->status);
    foreach ($srv->http->requests as $r) {
        ok(!str_contains($r['path'], '/sign/'), 'no round one after a refused quote');
    }
});

test('cosign: a read-only key never creates a movement', function () {
    $s = contractServer();
    $e = throws(fn () => contractWallet($s)->withdraw('USDC@base', '10', CONTRACT_TO));
    ok(str_contains($e->getMessage(), 'full-scope'), $e->getMessage());
    eq([], $s->requests, 'nothing reached the server');
});

test('cosign: the guard is bound to the token — the send asset\'s fingerprint, never skipped', function () {
    $assets = fixture('assets')['response']['body'];
    eq(hash('sha256', 'usdc-base'), $assets[0]['fingerprint'], 'the fixture fingerprint is sha256 of the raw id');

    // The approved amount, disclosed in another asset (USDT@base's raw id): refused unsigned.
    $srv = new CosignServer();
    $srv->tokenKey = 'usdt-base';
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
    eq('co-sign refused: source 0 transfers a different asset than USDC@base', $e->getMessage());
    eq(0, $srv->aggregated(), 'nothing aggregated');

    // An older server that publishes no fingerprint: reads still work, signing refuses.
    foreach ($assets as $i => $a) {
        unset($assets[$i]['fingerprint']);
    }
    $srv = new CosignServer();
    $srv->http->route('GET', '/vault/v1/assets', fn () => jsonResponse($assets));
    $w = $srv->wallet();
    eq(null, $w->assets()[0]->fingerprint, 'fingerprint is optional on the wire');
    $e = throws(fn () => $w->withdraw('USDC@base', '10', CONTRACT_TO));
    eq('co-sign refused: the server did not publish an asset fingerprint — update the server', $e->getMessage());
    eq(0, $srv->aggregated(), 'nothing aggregated');
    eq([], $srv->verified, 'no share was computed');
});

test('cosign: a key package without verifying_key is refused, never waved through', function () {
    $kp = contractDkg()['client'];
    unset($kp['verifying_key']);
    // End to end the native core already rejects it at commit — nothing reaches round one.
    $srv = new CosignServer();
    $w = contractWallet($srv->http, json_encode($kp, JSON_THROW_ON_ERROR));
    throws(fn () => $w->withdraw('USDC@base', '10', CONTRACT_TO));
    eq([], $srv->http->requestsTo('POST', '/vault/v1/movements/mv_1/sign/'), 'no round one');
    // And the guard itself refuses rather than skipping the own-vault check, whatever the core does.
    $guard = new \ReflectionMethod(\Paymos\Wallet\Wallet::class, 'verifySigningDisclosure');
    $q = \Paymos\Wallet\Quote::fromWire(fixture('quote_withdraw')['response']['body']);
    $e = throws(fn () => $guard->invoke($w, $q, [['message' => 'x']], [['message' => '{}', 'nonce' => '00', 'recipient' => 'r']], $kp));
    eq('co-sign refused: the co-signing share carries no verifying key', $e->getMessage());
});

test('cosign: one label listed twice accepts a token matching either fingerprint, and no other', function () {
    // Two contracts of one coin under one label. The first entry is the one a last-wins map
    // would lose — the server resolves a label to its FIRST catalog match.
    foreach (['usdc-base', 'usdc-base-bridged'] as $token) {
        $srv = (new CosignServer())->publishUsdc('usdc-base', 'usdc-base-bridged');
        $srv->tokenKey = $token;
        $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO);
        eq([true, true], $srv->verified, "$token signs past the guard");
    }
    $srv = (new CosignServer())->publishUsdc('usdc-base', 'usdc-base-bridged');
    $srv->tokenKey = 'usdt-base';
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
    eq('co-sign refused: source 0 transfers a different asset than USDC@base', $e->getMessage());
    eq(0, $srv->aggregated());
    // Decimals still resolve for the duplicated label, and assets() keeps both entries.
    eq(3, count($srv->wallet()->assets()));
});

test('cosign: a pinned fingerprint is the only one accepted for its label', function () {
    $pin = strtoupper(hash('sha256', 'usdc-base')); // compared lowercase

    // The pin matches the disclosed token: signs, even where the server publishes nothing.
    $srv = (new CosignServer())->publishUsdc();
    $srv->wallet(['USDC@base' => $pin])->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified, 'pinned value matches — signed');

    // A server that lies consistently — publishes another fingerprint and discloses that token —
    // passes an unpinned wallet and is refused by a pinned one.
    $srv = (new CosignServer())->publishUsdc('costlier-asset');
    $srv->tokenKey = 'costlier-asset';
    $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified, 'unpinned: the published fingerprint is all there is');
    $srv = (new CosignServer())->publishUsdc('costlier-asset');
    $srv->tokenKey = 'costlier-asset';
    $e = throws(fn () => $srv->wallet(['USDC@base' => [$pin]])->withdraw('USDC@base', '10', CONTRACT_TO));
    eq('co-sign refused: source 0 transfers a different asset than USDC@base', $e->getMessage());
    eq(0, $srv->aggregated(), 'nothing aggregated');

    // A pin on another label leaves this one on the published set.
    $srv = new CosignServer();
    $srv->wallet(['USDT@base' => hash('sha256', 'something-else')])->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified);

    // A malformed pin fails at construction, not as a refusal in production.
    foreach ([['USDC@base' => 'abc'], ['USDC@base' => []], [0 => $pin]] as $bad) {
        throws(fn () => contractWallet(contractServer(), null, $bad));
    }
});

test('cosign: a label listed twice takes its decimals from the FIRST entry, as the server does', function () {
    $catalog = fixture('assets')['response']['body'];
    $second = ['decimals' => 18, 'fingerprint' => hash('sha256', 'usdc-base-18')] + $catalog[0];
    $srv = new CosignServer();
    $srv->http->route('GET', '/vault/v1/assets', fn () => jsonResponse([$catalog[0], $second, $catalog[1]]));

    // Amount conversion: seven fractional digits do not fit the first entry's 6 decimals.
    $e = throws(fn () => $srv->wallet()->quoteWithdraw('USDC@base', '0.0000001', CONTRACT_TO));
    ok(str_contains($e->getMessage(), 'more than 6 fractional digits'), $e->getMessage());
    eq([], $srv->http->requestsTo('POST', '/vault/v1/quote/'), 'refused before the wire');

    // The echo check: "10" is 10000000 at 6 decimals — what quote_withdraw.json echoes. At the last
    // entry's 18 it would be 10^19 and the honest quote would be refused.
    $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified, 'signed against the first entry\'s decimals');
});

test('cosign: a same-label entry with other decimals is not an accepted token', function () {
    $catalog = fixture('assets')['response']['body'];
    // Second USDC@base entry at 18 decimals: its raw units are worth 10^12 more than the debit's.
    $second = ['decimals' => 18, 'fingerprint' => hash('sha256', 'usdc-base-18')] + $catalog[0];
    $srv = new CosignServer();
    $srv->http->route('GET', '/vault/v1/assets', fn () => jsonResponse([$catalog[0], $second, $catalog[1]]));
    $srv->tokenKey = 'usdc-base-18';
    $e = throws(fn () => $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO));
    eq('co-sign refused: source 0 transfers a different asset than USDC@base', $e->getMessage());
    eq(0, $srv->aggregated(), 'refused before any share');
    eq([], $srv->verified);

    // The first entry's own token still signs in the same catalog.
    $srv = new CosignServer();
    $srv->http->route('GET', '/vault/v1/assets', fn () => jsonResponse([$catalog[0], $second, $catalog[1]]));
    $srv->wallet()->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified);

    // A pin is the integrator's word and is not filtered by decimals.
    $srv = new CosignServer();
    $srv->http->route('GET', '/vault/v1/assets', fn () => jsonResponse([$catalog[0], $second, $catalog[1]]));
    $srv->tokenKey = 'usdc-base-18';
    $srv->wallet(['USDC@base' => hash('sha256', 'usdc-base-18')])->withdraw('USDC@base', '10', CONTRACT_TO);
    eq([true, true], $srv->verified, 'pinned: accepted as vouched');
});
