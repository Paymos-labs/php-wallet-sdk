<?php

declare(strict_types=1);

namespace Paymos\Wallet;

use Paymos\Wallet\Native\Core;

/**
 * The entry point, ported from the Python SDK via the TypeScript, Rust, .NET, Go and Ruby ones.
 * Same methods, same guarantees, same refusals.
 *
 * Everything that moves money goes through a 2-of-2 co-sign in which this process holds one
 * half of the key and never sends it anywhere. The server cannot sign alone and neither can
 * this — which is only worth anything if this side refuses to sign things it has not checked:
 *
 *   the quote must echo what was approved   — a decimals bug or a lying server changes the
 *                                             numbers, and the numbers are compared before a
 *                                             commitment
 *   the debit may be capped                 — for an exact_out payout the input side is computed
 *                                             by the server, so $maxDebit is the caller's ceiling
 *   never blind-sign                        — the server discloses each message, this side
 *                                             recomputes the digest and refuses unless the
 *                                             package binds to it
 *
 * Any of those failing throws before a single signature share exists.
 */
final class Wallet
{
    /** A movement in any of these states has already been signed; a retry must not sign again. */
    private const PROGRESSED = ['signed', 'relaying', 'relayed', 'settling', 'completed', 'failed'];

    /** A movement is settled (no more polling) once it reaches one of these. */
    private const TERMINAL = ['completed', 'failed', 'refunded', 'expired', 'cancelled'];

    private const DEFAULT_BASE_URL = 'https://wallet.paymos.io';

    private readonly VaultSecret $secret;
    private readonly Http $http;
    /** @var null|array<string, Asset> */
    private ?array $assetCache = null;

    public function __construct(string $secret, ?string $baseUrl = null, ?int $timeoutSeconds = null,
                                ?callable $transport = null)
    {
        $this->secret = VaultSecret::parse($secret);
        $baseUrl ??= getenv('PAYMOS_BASE_URL') ?: self::DEFAULT_BASE_URL;
        $this->http = new Http(rtrim($baseUrl, '/'), $this->secret->apiKey,
            $timeoutSeconds ?? 60, $transport);
    }

    /** Reads PAYMOS_VAULT_SECRET, the way a deployed process is configured. */
    public static function fromEnv(): self
    {
        $secret = getenv('PAYMOS_VAULT_SECRET');
        if (!is_string($secret) || $secret === '') {
            throw new PaymosError('PAYMOS_VAULT_SECRET is not set');
        }
        return new self($secret);
    }

    // ── reads ────────────────────────────────────────────────────────────────

    /** @return Asset[] */
    public function assets(): array
    {
        $body = $this->http->get('/vault/v1/assets');
        return array_map(fn ($a) => Asset::fromWire($a), $body['assets'] ?? []);
    }

    /** @return Balance[] */
    public function balances(): array
    {
        $body = $this->http->get('/vault/v1/balances');
        return array_map(fn ($b) => Balance::fromWire($b), $body['balances'] ?? []);
    }

    public function movement(string $movementId): Movement
    {
        return Movement::fromWire($this->http->get('/vault/v1/movements/' . rawurlencode($movementId)));
    }

    /**
     * A page of the caller's movement history (newest first, summary rows without fee legs).
     * Returns [items, nextCursor]; pass nextCursor back as $cursor for the next page
     * (null when exhausted). The server speaks keyset pagination: items + next_cursor.
     *
     * @return array{0: Movement[], 1: ?string}
     */
    public function movements(int $limit = 50, ?string $cursor = null): array
    {
        $body = $this->http->get('/vault/v1/movements', ['limit' => $limit, 'cursor' => $cursor]);
        $items = array_map(fn ($m) => Movement::fromWire($m), $body['items'] ?? []);
        return [$items, $body['next_cursor'] ?? null];
    }

    /** Polls until the movement leaves the states that are still in flight. */
    public function wait(string $movementId, int $timeoutSeconds = 300, float $intervalSeconds = 2.0): Movement
    {
        $deadline = microtime(true) + $timeoutSeconds;
        while (true) {
            $mv = $this->movement($movementId);
            if (in_array($mv->status, self::TERMINAL, true)) {
                return $mv;
            }
            if (microtime(true) >= $deadline) {
                throw new PaymosError("timed out waiting for movement $movementId");
            }
            usleep((int)($intervalSeconds * 1_000_000));
        }
    }

    // ── quotes ───────────────────────────────────────────────────────────────

    /** A dry preview. Nothing is created and nothing is signed. */
    public function quoteSwap(string $send, string $receive, string $amount, int $slippageBps = 50): Quote
    {
        $this->validateAmount($amount, $send);
        $this->decimalsOf($receive);
        return $this->quote('/vault/v1/quote/swap', [
            'send' => $send, 'receive' => $receive, 'amount' => $amount, 'slippage_bps' => $slippageBps,
        ], true);
    }

    /** A dry preview. Nothing is created and nothing is signed. */
    public function quoteWithdraw(string $asset, string $amount, string $to, string $mode = 'exact_out'): Quote
    {
        $this->validateAmount($amount, $asset);
        return $this->quote('/vault/v1/quote/withdraw', [
            'asset' => $asset, 'amount' => $amount, 'to' => $to, 'mode' => $mode,
        ], true);
    }

    // ── money ────────────────────────────────────────────────────────────────

    public function swap(string $send, string $receive, string $amount, int $slippageBps = 50,
                         ?string $minReceive = null, ?string $maxDebit = null,
                         ?string $idempotencyKey = null): Movement
    {
        $this->requireShare('swap');
        $sendDec = $this->validateAmount($amount, $send);
        $recvDec = $this->decimalsOf($receive);

        $q = $this->quote('/vault/v1/quote/swap', [
            'send' => $send, 'receive' => $receive, 'amount' => $amount, 'slippage_bps' => $slippageBps,
        ], false, $idempotencyKey ?? self::freshIdempotencyKey());

        if ($minReceive !== null) {
            $floor = Amounts::parseUnits($minReceive, $recvDec);
            if (Amounts::cmpRaw($q->receive->min, $floor) < 0) {
                throw new SlippageExceeded(
                    "guaranteed receive {$q->receive->min} is below the requested minimum $floor ($minReceive $receive)",
                    0);
            }
        }
        // A swap is exact_in, so the send leg must be exactly what was approved.
        $this->verifyQuoteEcho($q, $send, $receive, expectSendRaw: Amounts::parseUnits($amount, $sendDec));
        $this->enforceMaxDebit($q, $maxDebit, $sendDec);
        $this->cosign($q);
        return $this->movement(self::requireMovementId($q));
    }

    public function withdraw(string $asset, string $amount, string $to, string $mode = 'exact_out',
                             ?string $maxDebit = null, ?string $idempotencyKey = null): Movement
    {
        $this->requireShare('withdraw');
        $dec = $this->validateAmount($amount, $asset);

        $q = $this->quote('/vault/v1/quote/withdraw', [
            'asset' => $asset, 'amount' => $amount, 'to' => $to, 'mode' => $mode,
        ], false, $idempotencyKey ?? self::freshIdempotencyKey());

        // A withdraw is same-asset. For exact_out the approved amount is the guaranteed OUT; for
        // total_in it is the total DEBIT — and the send leg is total minus fees, so pinning send
        // would false-fail every time.
        $expected = Amounts::parseUnits($amount, $dec);
        if ($mode === 'total_in') {
            $this->verifyQuoteEcho($q, $asset, $asset, maxDebitRaw: $expected);
        } else {
            $this->verifyQuoteEcho($q, $asset, $asset, expectReceiveRaw: $expected);
        }

        $this->enforceMaxDebit($q, $maxDebit, $dec);
        $this->cosign($q);
        return $this->movement(self::requireMovementId($q));
    }

    // ── internals ────────────────────────────────────────────────────────────

    private function requireShare(string $op): void
    {
        if (!$this->secret->hasShare()) {
            throw new PaymosError("$op needs a full-scope secret: this one carries no co-signing share");
        }
    }

    /** @return array<string, Asset> */
    private function assetMap(): array
    {
        if ($this->assetCache === null) {
            $map = [];
            foreach ($this->assets() as $a) {
                $map[$a->asset] = $a;
            }
            $this->assetCache = $map;
        }
        return $this->assetCache;
    }

    private function decimalsOf(string $asset): int
    {
        $found = $this->assetMap()[$asset] ?? null;
        if ($found === null) {
            throw new PaymosError("unknown asset: $asset");
        }
        return $found->decimals;
    }

    private function validateAmount(string $amount, string $asset): int
    {
        $dec = $this->decimalsOf($asset);
        Amounts::parseUnits($amount, $dec); // throws on a bad decimal before anything reaches the server
        return $dec;
    }

    private function quote(string $path, array $body, bool $dry, ?string $idempotencyKey = null): Quote
    {
        $body['dry'] = $dry;
        $headers = $idempotencyKey !== null ? ['idempotency-key' => $idempotencyKey] : [];
        return Quote::fromWire($this->http->post($path, $body, $headers));
    }

    private static function requireMovementId(Quote $q): string
    {
        if ($q->movementId === null || $q->movementId === '') {
            throw new PaymosError('quote created no movement — cannot sign a dry preview');
        }
        return $q->movementId;
    }

    /** Drives the 2-of-2 round: commit per source, verify the disclosure, sign, aggregate. */
    private function cosign(Quote $q): void
    {
        $mid = self::requireMovementId($q);
        $n = $q->sources ?? 0;
        if ($n < 1) {
            throw new PaymosError('quote is missing its source count — cannot co-sign');
        }

        $kp = $this->clientKeyPackage();
        $commitments = [];
        $nonces = [];
        for ($i = 0; $i < $n; $i++) {
            [$c, $nn] = Core::commit($kp);
            $commitments[] = $c;
            $nonces[] = $nn;
        }

        try {
            $begin = $this->http->post("/vault/v1/movements/$mid/sign/begin", ['commitments' => $commitments]);
        } catch (\Throwable $e) {
            // An ambiguous failure after the server already progressed the movement is not a
            // reason to sign it a second time.
            $mv = $this->movement($mid);
            if (in_array($mv->status, self::PROGRESSED, true)) {
                return;
            }
            throw $e;
        }

        $packages = $begin['signing_packages'] ?? [];
        if (!is_array($packages) || count($packages) !== $n) {
            throw new PaymosError(
                "co-sign protocol mismatch: expected $n signing packages, got " . count((array)$packages));
        }

        $this->verifySigningDisclosure($q, $packages, $begin['messages'] ?? null, $kp);

        $shares = [];
        foreach ($packages as $i => $pkg) {
            $shares[] = Core::sign($pkg, $nonces[$i], $kp);
        }
        $res = $this->http->post("/vault/v1/movements/$mid/sign/aggregate", [
            'token' => $begin['token'] ?? null, 'shares' => $shares,
        ]);
        if (!is_array($res) || ($res['ok'] ?? false) !== true) {
            $msg = is_array($res) ? ($res['error'] ?? null) : null;
            throw new PaymosError(is_string($msg) && $msg !== '' ? $msg : 'sign failed');
        }
    }

    private function clientKeyPackage(): array
    {
        if ($this->secret->share === null) {
            throw new PaymosError('this secret carries no co-signing share');
        }
        $kp = json_decode($this->secret->share, true);
        if (!is_array($kp)) {
            throw new PaymosError('the co-signing share is not valid JSON');
        }
        return $kp;
    }

    /**
     * Refuse to sign unless the server's quote echoes what the caller approved. This is what
     * makes co-signing a server-built movement safe: a units bug, contract drift or a
     * compromised server changes one of these numbers, and the change is caught before a
     * commitment exists.
     */
    private function verifyQuoteEcho(Quote $q, string $sendAsset, string $receiveAsset,
                                     ?string $expectSendRaw = null, ?string $expectReceiveRaw = null,
                                     ?string $maxDebitRaw = null): void
    {
        if ($q->send->asset !== $sendAsset || $q->receive->asset !== $receiveAsset) {
            throw new PaymosError(
                "quote is for {$q->send->asset}→{$q->receive->asset}, not the {$sendAsset}→{$receiveAsset} that was approved");
        }
        if ($expectSendRaw !== null && Amounts::cmpRaw($q->send->amount, $expectSendRaw) !== 0) {
            throw new PaymosError("quote sends {$q->send->amount}, not the approved $expectSendRaw");
        }
        if ($expectReceiveRaw !== null && Amounts::cmpRaw($q->receive->min, $expectReceiveRaw) < 0) {
            throw new PaymosError("quote guarantees {$q->receive->min}, below the approved $expectReceiveRaw");
        }
        if ($maxDebitRaw !== null && Amounts::cmpRaw($q->debit->amount, $maxDebitRaw) > 0) {
            throw new PaymosError("quote debits {$q->debit->amount}, above the approved $maxDebitRaw");
        }
    }

    private function enforceMaxDebit(Quote $q, ?string $maxDebit, int $decimals): void
    {
        if ($maxDebit === null) {
            return;
        }
        $cap = Amounts::parseUnits($maxDebit, $decimals);
        if (Amounts::cmpRaw($q->debit->amount, $cap) > 0) {
            throw new PaymosError("quote debits {$q->debit->amount}, above the cap $cap ($maxDebit)");
        }
    }

    /**
     * Never blind-sign. The server discloses, per source, the plaintext message it wants signed.
     * For each one this recomputes the digest and confirms the package about to be signed binds
     * to exactly that message; confirms the message is a single transfer from THIS vault; and
     * confirms the sources together move no more than the approved debit. Any mismatch aborts
     * before a share is produced.
     */
    private function verifySigningDisclosure(Quote $q, array $packages, mixed $messages, array $kp): void
    {
        if (!is_array($messages) || count($messages) !== count($packages)) {
            throw new PaymosError(
                'co-sign refused: the server did not disclose the signing messages — refusing to blind-sign');
        }
        $expectedSigner = strtolower((string)($kp['verifying_key'] ?? ''));
        $total = '0';

        foreach (array_values($packages) as $i => $pkg) {
            $disc = $messages[$i] ?? null;
            $message = is_array($disc) ? ($disc['message'] ?? null) : null;
            $nonceHex = is_array($disc) ? ($disc['nonce'] ?? null) : null;
            $recipient = is_array($disc) ? ($disc['recipient'] ?? null) : null;
            if (!is_string($message) || !is_string($nonceHex) || !is_string($recipient)) {
                throw new PaymosError("co-sign refused: malformed disclosure for source $i");
            }

            // (1) binding: the package about to be signed hashes to EXACTLY the disclosed message.
            if (strlen($nonceHex) % 2 !== 0 || !ctype_xdigit($nonceHex)) {
                throw new PaymosError("co-sign refused: bad nonce hex for source $i");
            }
            $want = Digest::payloadDigestHex($message, hex2bin($nonceHex), $recipient);
            $got = is_array($pkg) ? ($pkg['message'] ?? null) : null;
            if (!is_string($got) || strtolower($got) !== $want) {
                throw new PaymosError("co-sign refused: signing package $i does not bind to the disclosed message");
            }

            // (2) structure and own-vault: one transfer, signed from this vault and no other.
            $parsed = json_decode($message, true);
            if (!is_array($parsed)) {
                throw new PaymosError("co-sign refused: unreadable message for source $i");
            }
            $list = $parsed['intents'] ?? null;
            if (!is_array($list) || count($list) !== 1) {
                throw new PaymosError("co-sign refused: source $i is not a single transfer");
            }
            $one = $list[0];
            if (($one['intent'] ?? null) !== 'transfer') {
                throw new PaymosError("co-sign refused: source $i is not a single transfer");
            }
            $signerId = strtolower((string)($parsed['signer_id'] ?? ''));
            if ($expectedSigner !== '' && $signerId !== $expectedSigner) {
                throw new PaymosError("co-sign refused: source $i signs from a different vault");
            }
            $tokens = $one['tokens'] ?? null;
            if (!is_array($tokens) || count($tokens) !== 1) {
                throw new PaymosError("co-sign refused: source $i does not transfer exactly one token");
            }
            $tokenAmount = (string)array_values($tokens)[0];
            if (!preg_match('/^\d+$/', $tokenAmount)) {
                throw new PaymosError("co-sign refused: bad transfer amount for source $i");
            }
            $total = Amounts::addRaw($total, $tokenAmount);
        }

        // (3) no inflation: the sources together move no more than the approved debit.
        $approved = $q->debit->amount;
        if (Amounts::cmpRaw($total, $approved) > 0) {
            throw new PaymosError("co-sign refused: sources move $total, above the approved debit $approved");
        }
    }

    /** uuid-v4-shaped hex without the dashes — the same shape the other SDKs generate. */
    private static function freshIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
