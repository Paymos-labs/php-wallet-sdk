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
 *                                             package binds to it, it is one transfer from this
 *                                             vault of the send asset's token (by the fingerprint
 *                                             the server publishes, or one the caller pinned), and
 *                                             the sources move no more than the approved debit
 *
 * Any of those failing throws before a single signature share exists. What none of them checks:
 * the transfer's RECIPIENT — the route's deposit address is chosen by the server and is not
 * verified here — and, for exact_out, the size of the approved debit itself, which the server
 * computes unless the caller passes $maxDebit. Pass it for unattended payouts.
 */
final class Wallet
{
    /**
     * A movement past "pending" is already committed to the co-sign / relay pipeline: if a retry
     * with the same durable Idempotency-Key replays the SAME movement in one of these states, the
     * SDK converges on it instead of signing a second time. The public vocabulary only — the
     * server never sends its internal states (see contract/fixtures/vocabulary.json). "failed" is
     * deliberately absent: a failed movement is not something a retry may report as done.
     * Equal to the Python SDK's _PROGRESSED_STATUSES.
     */
    private const PROGRESSED = ['processing', 'completed', 'refunded'];

    /** A movement is settled (no more polling) once it reaches one of these. Python's _TERMINAL_STATUSES. */
    private const TERMINAL = ['completed', 'failed', 'refunded', 'expired', 'cancelled'];

    private const DEFAULT_BASE_URL = 'https://wallet.paymos.io';

    private readonly VaultSecret $secret;
    private readonly Http $http;
    /** @var null|list<Asset> the catalog as /assets answered it, duplicates of one label included */
    private ?array $assetList = null;
    /** @var null|array<string, Asset> by label, first entry wins as on the server — for decimals */
    private ?array $assetCache = null;
    /** @var array<string, list<string>> label → the only fingerprints the guard accepts for it */
    private readonly array $pinned;

    /**
     * The base URL resolves as: an explicit $baseUrl, else PAYMOS_BASE_URL, else
     * https://wallet.paymos.io. $transport replaces the HTTP client (tests); leave it null.
     *
     * $pinnedFingerprints maps an asset label ("USDC@base") to its fingerprint, or a list of them —
     * lowercase hex sha256 of the raw asset id, obtained out of band. For a pinned label the co-sign
     * guard accepts only those and ignores what /assets publishes; an unpinned label uses the
     * published ones. Pin to have a trust anchor that does not come from the server that also
     * builds the messages being signed.
     *
     * @param null|array<string, string|list<string>> $pinnedFingerprints
     */
    public function __construct(#[\SensitiveParameter] string $secret, ?string $baseUrl = null,
                                ?int $timeoutSeconds = null, ?callable $transport = null,
                                ?array $pinnedFingerprints = null)
    {
        $this->secret = VaultSecret::parse($secret);
        $this->pinned = self::normalizePins($pinnedFingerprints ?? []);
        $baseUrl ??= self::env('PAYMOS_BASE_URL') ?? self::DEFAULT_BASE_URL;
        $this->http = new Http(rtrim($baseUrl, '/'), $this->secret->apiKey,
            $timeoutSeconds ?? 60, $transport);
    }

    /**
     * What var_dump, print_r and Symfony's VarDumper show of a wallet: which vault, whether it can
     * sign, the key's vk_live_ prefix — never the key or the share. (var_export, an (array) cast and
     * serialize() have no such hook in PHP; do not hand them a wallet.)
     */
    public function __debugInfo(): array
    {
        return [
            'vaultId' => $this->secret->vaultId,
            'hasShare' => $this->secret->hasShare(),
            'apiKey' => VaultSecret::mask($this->secret->apiKey),
            'pinnedFingerprints' => $this->pinned,
        ];
    }

    /** Reads PAYMOS_VAULT_SECRET (and PAYMOS_BASE_URL unless $baseUrl is given), the way a deployed process is configured. */
    public static function fromEnv(?string $baseUrl = null, ?int $timeoutSeconds = null,
                                   ?array $pinnedFingerprints = null): self
    {
        $secret = self::env('PAYMOS_VAULT_SECRET');
        if ($secret === null) {
            throw new PaymosError('PAYMOS_VAULT_SECRET is not set');
        }
        return new self($secret, $baseUrl, $timeoutSeconds, pinnedFingerprints: $pinnedFingerprints);
    }

    /**
     * A pin is configuration, so a malformed one fails at construction rather than as a refused
     * co-sign in production: each value is 64 hex characters, compared lowercase.
     *
     * @return array<string, list<string>>
     */
    private static function normalizePins(array $pins): array
    {
        $out = [];
        foreach ($pins as $label => $value) {
            $values = is_array($value) ? $value : [$value];
            if (!is_string($label) || $label === '' || $values === []) {
                throw new PaymosError('pinnedFingerprints: expected a map of asset label to fingerprint(s)');
            }
            foreach ($values as $fp) {
                if (!is_string($fp) || !preg_match('/^[0-9a-fA-F]{64}$/D', $fp)) {
                    throw new PaymosError("pinnedFingerprints[$label]: a fingerprint is 64 hex characters (sha256)");
                }
                $out[$label][] = strtolower($fp);
            }
        }
        return $out;
    }

    /**
     * getenv() first, then $_ENV and $_SERVER: a framework's .env loader (Symfony's Dotenv by
     * default) fills those two without calling putenv(), and getenv() alone would call a
     * configured secret "not set".
     */
    private static function env(string $name): ?string
    {
        foreach ([getenv($name), $_ENV[$name] ?? null, $_SERVER[$name] ?? null] as $v) {
            if (is_string($v) && $v !== '') {
                return $v;
            }
        }
        return null;
    }

    // ── reads ────────────────────────────────────────────────────────────────

    /**
     * The curated asset catalog ("SYMBOL@chain" ids + decimals). Fetched once and cached; every
     * amount conversion reads its decimals from here and never guesses.
     *
     * @return Asset[]
     */
    public function assets(): array
    {
        if ($this->assetList === null) {
            // The server answers a BARE JSON array. Reading a {"assets": [...]} wrapper here once
            // returned an empty catalog, and every quote, swap and withdraw then failed with
            // "unknown asset" — so anything but an array is an error, never an empty list.
            $body = Wire::list($this->http->get('/vault/v1/assets'), 'the /assets response');
            $list = [];
            $map = [];
            foreach ($body as $a) {
                $asset = Asset::fromWire($a);
                $list[] = $asset;
                // One label can be listed twice; the server resolves a label to its FIRST catalog
                // entry, so decimals do too. (The fingerprint check accepts any of them.)
                $map[$asset->asset] ??= $asset;
            }
            $this->assetList = $list;
            $this->assetCache = $map;
        }
        return $this->assetList;
    }

    /**
     * The vault's per-asset balances: raw amount strings plus a nullable USD value.
     *
     * @return Balance[]
     */
    public function balances(): array
    {
        // A bare JSON array, like /assets.
        $body = Wire::list($this->http->get('/vault/v1/balances'), 'the /balances response');
        return array_map(fn ($b) => Balance::fromWire($b), $body);
    }

    /** One movement in full, with its fee breakdown. */
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
        $body = Wire::object($this->http->get('/vault/v1/movements', ['limit' => $limit, 'cursor' => $cursor]),
            'the /movements response');
        $items = array_map(fn ($m) => Movement::fromWire($m),
            Wire::list(Wire::req($body, 'items', 'movements'), 'movements.items'));
        return [$items, Wire::optStr($body, 'next_cursor', 'movements')];
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

    /**
     * A dry preview. Nothing is created and nothing is signed.
     *
     * $amount is a HUMAN decimal string in the send asset ('100', '0.5'); the server scales it by
     * the asset's decimals. The quote comes back in raw integer strings. Never pass
     * Amounts::parseUnits output here: '10000' is ten thousand of the asset, not 0.01.
     */
    public function quoteSwap(string $send, string $receive, string $amount, int $slippageBps = 50): Quote
    {
        $this->validateAmount($amount, $send);
        $this->decimalsOf($receive);
        return $this->quote('/vault/v1/quote/swap', [
            'send' => $send, 'receive' => $receive, 'amount' => $amount, 'mode' => 'exact_in',
            'slippage_bps' => $slippageBps,
        ], true);
    }

    /**
     * A dry preview. Nothing is created and nothing is signed.
     *
     * $amount is a HUMAN decimal string in $asset ('25', '0.01'); the server scales it. The quote
     * comes back in raw integer strings. Never pass Amounts::parseUnits output here.
     */
    public function quoteWithdraw(string $asset, string $amount, string $to, string $mode = 'exact_out'): Quote
    {
        $this->validateAmount($amount, $asset);
        return $this->quote('/vault/v1/quote/withdraw', [
            'asset' => $asset, 'amount' => $amount, 'to' => $to, 'mode' => $mode,
        ], true);
    }

    // ── money ────────────────────────────────────────────────────────────────

    /**
     * Swaps $amount of $send for $receive, delivered back to this vault: a real movement,
     * co-signed. Full-key secrets only.
     *
     * $amount, $minReceive and $maxDebit are HUMAN decimal strings ('100', '99.5') that the server
     * scales by each asset's decimals — never Amounts::parseUnits output, which would be read as
     * that many whole units: swap('USDC@base', 'USDT@base', '10000') swaps ten thousand USDC.
     */
    public function swap(string $send, string $receive, string $amount, int $slippageBps = 50,
                         ?string $minReceive = null, ?string $maxDebit = null,
                         ?string $idempotencyKey = null): Movement
    {
        $this->requireShare('swap');
        $key = self::idempotencyKeyOf($idempotencyKey);
        $sendDec = $this->validateAmount($amount, $send);
        $recvDec = $this->decimalsOf($receive);

        $q = $this->quote('/vault/v1/quote/swap', [
            'send' => $send, 'receive' => $receive, 'amount' => $amount, 'mode' => 'exact_in',
            'slippage_bps' => $slippageBps,
        ], false, $key);

        if ($minReceive !== null) {
            $floor = Amounts::parseUnits($minReceive, $recvDec);
            if (Amounts::cmpRaw($q->receive->min, $floor) < 0) {
                throw new SlippageExceeded(
                    "guaranteed receive {$q->receive->min} is below the requested minimum $floor ($minReceive $receive)");
            }
        }
        // A swap is exact_in, so the send leg must be exactly what was approved.
        $this->verifyQuoteEcho($q, $send, $receive, expectSendRaw: Amounts::parseUnits($amount, $sendDec));
        $this->enforceMaxDebit($q, $maxDebit, $sendDec);
        $this->cosign($q);
        return $this->movement(self::requireMovementId($q));
    }

    /**
     * Pays $amount of $asset out to $to: a real movement, co-signed. Full-key secrets only.
     *
     * $amount and $maxDebit are HUMAN decimal strings ('25', '0.01') that the server scales by the
     * asset's decimals — never Amounts::parseUnits output. withdraw('USDC@base', '10000', $to),
     * which is what parseUnits('0.01', 6) returns, pays out ten thousand USDC; the SDK and the server
     * agree on that number, so no check can tell it from an intended one.
     */
    public function withdraw(string $asset, string $amount, string $to, string $mode = 'exact_out',
                             ?string $maxDebit = null, ?string $idempotencyKey = null): Movement
    {
        $this->requireShare('withdraw');
        $key = self::idempotencyKeyOf($idempotencyKey);
        $dec = $this->validateAmount($amount, $asset);

        $q = $this->quote('/vault/v1/quote/withdraw', [
            'asset' => $asset, 'amount' => $amount, 'to' => $to, 'mode' => $mode,
        ], false, $key);

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

    private function decimalsOf(string $asset): int
    {
        $this->assets();
        $found = $this->assetCache[$asset] ?? null;
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
            // A real quote must report its source count; a missing or zero one is a server or
            // contract fault, not a signable movement.
            throw new PaymosError('quote is missing its source count — cannot co-sign');
        }
        $path = '/vault/v1/movements/' . rawurlencode($mid);

        $kp = $this->clientKeyPackage();
        $commitments = [];
        $nonces = [];
        for ($i = 0; $i < $n; $i++) {
            [$c, $nn] = Core::commit($kp);
            $commitments[] = $c;
            $nonces[] = $nn;
        }

        try {
            $begin = $this->http->post("$path/sign/begin", ['commitments' => $commitments]);
        } catch (PaymosError $e) {
            // Round one rejected because a prior attempt with the same durable Idempotency-Key
            // replayed this SAME movement and already signed it: converge, that is idempotent and
            // not a double-sign. Anything still pending (or failed) re-raises the original error.
            $mv = $this->movement($mid);
            if (in_array($mv->status, self::PROGRESSED, true)) {
                return;
            }
            throw $e;
        }
        if (!is_array($begin)) {
            throw new PaymosError('co-sign protocol mismatch: sign/begin did not answer a JSON object');
        }

        $token = $begin['token'] ?? null;
        if (!is_string($token) || $token === '') {
            throw new PaymosError('co-sign protocol mismatch: sign/begin returned no session token');
        }
        // Exactly one signing package per source committed to: a count mismatch would silently
        // under- or over-sign against nonces generated here.
        $packages = $begin['signing_packages'] ?? null;
        if (!is_array($packages) || !array_is_list($packages) || count($packages) !== $n) {
            throw new PaymosError("co-sign protocol mismatch: expected $n signing packages, got "
                . (is_array($packages) ? count($packages) : 'none'));
        }

        $this->verifySigningDisclosure($q, $packages, $begin['messages'] ?? null, $kp);

        $shares = [];
        foreach ($packages as $i => $pkg) {
            $shares[] = Core::sign($pkg, $nonces[$i], $kp);
        }
        // A round-two failure is a genuine crypto or relay rejection and always propagates — a
        // 400 {"ok":false,…} arrives here as a typed error from the transport, a 200 with
        // ok:false is caught just below. Neither is ever swallowed.
        $res = $this->http->post("$path/sign/aggregate", ['token' => $token, 'shares' => $shares]);
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
     * Refuse to sign unless the server's quote echoes what the caller approved: a units bug,
     * contract drift or a server that changes the assets or the approved amount is caught before
     * a commitment exists. It compares only what the caller typed — an exact_out debit is not
     * something the caller typed, which is what $maxDebit is for.
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
        if ($expectReceiveRaw !== null) {
            // The approved OUT is exact: the quote must deliver exactly it (Python's check — an
            // inflated receive drags an unbounded exact_out debit with it) and guarantee no less.
            if (Amounts::cmpRaw($q->receive->amount, $expectReceiveRaw) !== 0) {
                throw new PaymosError("quote receives {$q->receive->amount}, not the approved $expectReceiveRaw");
            }
            if (Amounts::cmpRaw($q->receive->min, $expectReceiveRaw) < 0) {
                throw new PaymosError("quote guarantees {$q->receive->min}, below the approved $expectReceiveRaw");
            }
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
     * to exactly that message; confirms the message is a single transfer from THIS vault of the
     * quote's SEND asset; and confirms the sources together move no more than the approved debit.
     * Any mismatch aborts before a share is produced. Not checked: the receiver of each transfer
     * (server-chosen). "Approved debit" is the quote's debit — server-computed for exact_out unless
     * capped by $maxDebit.
     */
    private function verifySigningDisclosure(Quote $q, array $packages, mixed $messages, array $kp): void
    {
        if (!is_array($messages) || !array_is_list($messages) || count($messages) !== count($packages)) {
            throw new PaymosError(
                'co-sign refused: the server did not disclose the signing messages — refusing to blind-sign');
        }
        // A key package always carries its group key; without it "signed from this vault" cannot
        // be checked at all, so its absence refuses rather than skipping the check.
        $expectedSigner = strtolower(is_string($kp['verifying_key'] ?? null) ? $kp['verifying_key'] : '');
        if ($expectedSigner === '') {
            throw new PaymosError('co-sign refused: the co-signing share carries no verifying key');
        }
        // Every source moves the SEND asset. The token it transfers must hash (sha256 of the raw
        // id) to a fingerprint accepted for that label: the pinned ones if the caller pinned the
        // label, else every one /assets publishes under it — one label can list two contracts of
        // one coin, and either is that coin. Without this the sum check below adds amounts of
        // unknown tokens. What it proves is bounded by where the fingerprint came from: a published
        // one catches a server bug or a compromised signing path, not a server that lies in /assets
        // too — a pinned one does not depend on the server at all. No fingerprint, no sign.
        $acceptedTokens = $this->acceptedFingerprints($q->send->asset);
        if ($acceptedTokens === []) {
            throw new PaymosError(
                'co-sign refused: the server did not publish an asset fingerprint — update the server');
        }
        $total = '0';

        foreach ($packages as $i => $pkg) {
            $disc = $messages[$i];
            $message = is_array($disc) ? ($disc['message'] ?? null) : null;
            $nonceHex = is_array($disc) ? ($disc['nonce'] ?? null) : null;
            $recipient = is_array($disc) ? ($disc['recipient'] ?? null) : null;
            if (!is_string($message) || !is_string($nonceHex) || !is_string($recipient)) {
                throw new PaymosError("co-sign refused: malformed disclosure for source $i");
            }

            // (1) binding: the package about to be signed hashes to EXACTLY the disclosed message.
            if (strlen($nonceHex) !== 64 || !ctype_xdigit($nonceHex)) {
                throw new PaymosError("co-sign refused: source $i discloses a nonce that is not 32 bytes of hex");
            }
            $want = Digest::payloadDigestHex($message, (string)hex2bin($nonceHex), $recipient);
            $got = is_array($pkg) ? ($pkg['message'] ?? null) : null;
            if (!is_string($got) || strtolower($got) !== $want) {
                throw new PaymosError("co-sign refused: signing package $i does not bind to the disclosed message");
            }

            // (2) structure and own-vault: one transfer, signed from this vault and no other. Read
            // the way the verifier on chain reads it — see parseStrictMessage.
            $parsed = self::parseStrictMessage($message, $i);
            $list = $parsed->intents ?? null;
            if (!is_array($list) || count($list) !== 1
                || !($list[0] instanceof \stdClass) || ($list[0]->intent ?? null) !== 'transfer') {
                throw new PaymosError("co-sign refused: source $i is not a single transfer intent");
            }
            $one = $list[0];
            $signerId = $parsed->signer_id ?? null;
            if (!is_string($signerId) || strtolower($signerId) !== $expectedSigner) {
                throw new PaymosError("co-sign refused: source $i signs from a different vault");
            }
            $tokens = ($one->tokens ?? null) instanceof \stdClass ? get_object_vars($one->tokens) : null;
            if ($tokens === null || count($tokens) !== 1) {
                throw new PaymosError("co-sign refused: source $i does not transfer exactly one token");
            }
            // (2b) the token: the one it moves must be the asset the debit was approved in.
            if (!in_array(hash('sha256', (string)array_key_first($tokens)), $acceptedTokens, true)) {
                throw new PaymosError(
                    "co-sign refused: source $i transfers a different asset than {$q->send->asset}");
            }
            // A transfer amount is a u128 string of ASCII digits, greater than zero — nothing else.
            // Each source is signed on its own, so a sign that parses would let a server disclose
            // A = 2D and B = -D: the sum in (3) reads D and passes while A alone moves 2D. "+5",
            // "", "0", a JSON number and a trailing newline are refused with it.
            $tokenAmount = array_values($tokens)[0];
            if (!is_string($tokenAmount) || !preg_match('/^[0-9]+$/D', $tokenAmount)
                || Amounts::cmpRaw($tokenAmount, '0') <= 0) {
                throw new PaymosError("co-sign refused: bad transfer amount for source $i");
            }
            $total = Amounts::addRaw($total, $tokenAmount);
        }

        // (3) no inflation past the approved debit: the sources together move no more than it.
        // For exact_out that debit is server-computed; $maxDebit is what bounds it for the caller.
        $approved = $q->debit->amount;
        if (Amounts::cmpRaw($total, $approved) > 0) {
            throw new PaymosError("co-sign refused: sources move $total, above the approved debit $approved");
        }
    }

    /**
     * A disclosed message, decoded the way the on-chain verifier reads it — the verifier re-parses
     * these exact bytes, so the guard must not read them another way. Three things json_decode
     * would otherwise do differently:
     *   - it keeps the LAST of a repeated object key, where the verifier rejects the message — a
     *     repeated key (at any depth, escaped spellings included) is refused here;
     *   - as associative arrays, {"0": …} and [ … ] decode alike and array_is_list cannot tell
     *     them apart — objects stay stdClass, so only a JSON array reads as a list;
     *   - JSON_BIGINT_AS_STRING turns a JSON number too big for an int into a string that passes
     *     a digits check — it is not used, so a number stays a number and is refused as an amount.
     * Nesting is capped at 64 levels; a transfer message has four.
     */
    private static function parseStrictMessage(string $message, int $i): \stdClass
    {
        try {
            $parsed = json_decode($message, false, 64, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new PaymosError("co-sign refused: unreadable message for source $i: {$e->getMessage()}");
        }
        $repeated = self::repeatedKey($message);
        if ($repeated !== null) {
            throw new PaymosError("co-sign refused: unreadable message for source $i: duplicate key \"$repeated\"");
        }
        if (!$parsed instanceof \stdClass) {
            throw new PaymosError("co-sign refused: unreadable message for source $i");
        }
        return $parsed;
    }

    /**
     * The first key that some object in $json repeats, or null. Runs only on text json_decode has
     * already accepted, so it need only find keys: a string followed by ':' is one — no value string
     * ever is — compared after json_decode resolves its escapes. Iterative: the nesting is the
     * server's to choose, and a recursion depth is not.
     */
    private static function repeatedKey(string $json): ?string
    {
        $open = []; // one entry per open container: an object's seen keys, or null for an array
        $len = strlen($json);
        for ($i = 0; $i < $len;) {
            $c = $json[$i];
            if ($c === '"') {
                $end = $i + 1;
                while ($json[$end] !== '"') {
                    $end += $json[$end] === '\\' ? 2 : 1;
                }
                $literal = substr($json, $i, $end - $i + 1);
                $i = $end + 1;
                $next = $i;
                while ($next < $len && ($json[$next] === ' ' || $json[$next] === "\t"
                        || $json[$next] === "\n" || $json[$next] === "\r")) {
                    $next++;
                }
                if ($next < $len && $json[$next] === ':') {
                    $key = (string)json_decode($literal);
                    $top = array_key_last($open);
                    // 'k' . $key: a PHP array would turn a key like "12" into the integer 12.
                    if ($top === null || $open[$top] === null || isset($open[$top]['k' . $key])) {
                        return $key;
                    }
                    $open[$top]['k' . $key] = true;
                }
                continue;
            }
            if ($c === '{') {
                $open[] = [];
            } elseif ($c === '[') {
                $open[] = null;
            } elseif ($c === '}' || $c === ']') {
                array_pop($open);
            }
            $i++;
        }
        return null;
    }

    /** @return list<string> the fingerprints the guard accepts for $label, lowercase */
    private function acceptedFingerprints(string $label): array
    {
        if (isset($this->pinned[$label])) {
            return $this->pinned[$label];
        }
        // Published: every entry under the label whose decimals equal the FIRST entry's — the one
        // the server resolves the label to, and the one the debit is counted in. A same-label entry
        // with other decimals is excluded: its raw units are worth 10^Δ more or less, so a raw
        // amount under the debit would say nothing about value. Pins skip this: the caller vouches.
        $this->assets();
        $decimals = $this->assetCache[$label]->decimals ?? null;
        $accepted = [];
        foreach ($this->assets() as $a) {
            if ($a->asset === $label && $a->decimals === $decimals && is_string($a->fingerprint)
                && preg_match('/^[0-9a-f]{64}$/D', $a->fingerprint)) {
                $accepted[] = $a->fingerprint;
            }
        }
        return $accepted;
    }

    /**
     * The key a money call is sent under: the caller's, or a fresh one when they passed null. An
     * empty or blank key is refused. It used to go out as an empty header, which the server reads
     * as no key at all: a retry paid again while the caller believed it was deduplicated.
     */
    private static function idempotencyKeyOf(?string $key): string
    {
        if ($key === null) {
            return self::freshIdempotencyKey();
        }
        if (trim($key) === '') {
            throw new PaymosError('idempotencyKey is empty — pass a durable key (a payout or job id) so a retry '
                . 'replays the same movement, or leave it null for a fresh one per call');
        }
        return $key;
    }

    /** uuid-v4-shaped hex without the dashes — the same shape the other SDKs generate. */
    private static function freshIdempotencyKey(): string
    {
        return bin2hex(random_bytes(16));
    }
}
