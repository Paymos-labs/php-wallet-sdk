<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * The read model, ported field-for-field from the Python SDK. Plain readonly DTOs; every amount
 * IN THESE RESPONSE TYPES is a STRING of raw integer units, never a number — a token with 18
 * decimals runs past what an int64 or a float holds exactly. Amounts::formatUnits shows one as a
 * human decimal; Amounts::parseUnits turns a human figure into raw units to COMPARE with one.
 *
 * The amounts you PASS to Wallet are the other way round: human decimal strings ('100', '0.5'),
 * which the server scales. Never pass the output of parseUnits there — '10000' meant as 0.01 USDC
 * is read as 10 000 USDC.
 *
 * The server OMITS a null field rather than sending null, so every optional field here tolerates
 * absence. A REQUIRED field that is missing is a different thing — contract drift or something
 * that is not the vault API answering — and it throws PaymosError naming the field, the way the
 * Python SDK refuses with a KeyError. It never becomes an empty string a caller then compares.
 * Unknown fields are ignored, so a newer server never breaks an older SDK.
 */
final class Asset
{
    /**
     * @param ?string $fingerprint lowercase hex sha256 of the asset's raw id — what the co-sign
     *   guard matches each disclosed transfer's token against, unless the label is pinned (see
     *   Wallet's $pinnedFingerprints). It comes from the same server that builds the messages, so
     *   on its own it catches a server bug, not a server that lies here too. Null from an older
     *   server that does not publish it: reads keep working, signing refuses.
     */
    public function __construct(
        public readonly string $asset,
        public readonly string $symbol,
        public readonly string $chain,
        public readonly int $decimals,
        public readonly ?string $fingerprint = null,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'asset');
        $fingerprint = Wire::optStr($o, 'fingerprint', 'asset');
        return new self(Wire::str($o, 'asset', 'asset'), Wire::str($o, 'symbol', 'asset'),
            Wire::str($o, 'chain', 'asset'), Wire::int($o, 'decimals', 'asset'),
            $fingerprint === null ? null : strtolower($fingerprint));
    }
}

/** What the vault holds of one asset. $usd is a display estimate and may be null. */
final class Balance
{
    public function __construct(
        public readonly string $asset,
        public readonly string $symbol,
        public readonly string $chain,
        public readonly int $decimals,
        public readonly string $amountRaw,
        public readonly ?string $usd,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'balance');
        return new self(Wire::str($o, 'asset', 'balance'), Wire::str($o, 'symbol', 'balance'),
            Wire::str($o, 'chain', 'balance'), Wire::int($o, 'decimals', 'balance'),
            Wire::str($o, 'amount_raw', 'balance'), Wire::optStr($o, 'usd', 'balance'));
    }
}

/** The routing layer's own fee. $estimate marks a figure that can still move. */
final class RouteFee
{
    public function __construct(
        public readonly string $amount,
        public readonly bool $estimate,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'fees.route');
        return new self(Wire::str($o, 'amount', 'fees.route'), Wire::bool($o, 'estimate', 'fees.route'));
    }
}

/** Every charge on an operation, split so none of them can hide inside another. */
final class Fees
{
    /**
     * @param ?RouteFee $route null when no route leg applies (an unpriced cross-asset pair).
     * @param ?array{platform: ?string, network: ?string, route: ?string, total: ?string} $usd a
     *   display view, null when the source asset is unpriced; every key is always present, and a
     *   leg the server left out is null.
     */
    public function __construct(
        public readonly string $asset,
        public readonly string $platform,
        public readonly string $network,
        public readonly ?RouteFee $route,
        public readonly string $total,
        public readonly ?array $usd,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'fees');
        $route = isset($o['route']) ? RouteFee::fromWire($o['route']) : null;
        $usd = null;
        if (isset($o['usd'])) {
            $u = Wire::object($o['usd'], 'fees.usd');
            $usd = [];
            foreach (['platform', 'network', 'route', 'total'] as $k) {
                $usd[$k] = Wire::optStr($u, $k, 'fees.usd');
            }
        }
        return new self(Wire::str($o, 'asset', 'fees'), Wire::str($o, 'platform', 'fees'),
            Wire::str($o, 'network', 'fees'), $route, Wire::str($o, 'total', 'fees'), $usd);
    }
}

final class Amount
{
    public function __construct(
        public readonly string $amount,
        public readonly string $asset,
    ) {}

    public static function fromWire(mixed $o, string $what = 'amount'): self
    {
        $o = Wire::object($o, $what);
        return new self(Wire::str($o, 'amount', $what), Wire::str($o, 'asset', $what));
    }
}

/** What arrives. $min is the floor the route's quote commits to delivering. */
final class Receive
{
    public function __construct(
        public readonly string $amount,
        public readonly string $min,
        public readonly string $asset,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'receive');
        return new self(Wire::str($o, 'amount', 'receive'), Wire::str($o, 'min', 'receive'),
            Wire::str($o, 'asset', 'receive'));
    }
}

final class Quote
{
    /**
     * @param ?string $movementId null on a dry preview: nothing was persisted.
     * @param Amount $debit The MAXIMUM this quote may take from the vault, reserve against
     *   slippage included — a ceiling, not a prediction. The co-sign guard refuses sources that
     *   move more than it; for exact_out the server computes it, so cap it with $maxDebit. An
     *   exact-output route routinely spends less and returns the difference. Compare the finished movement's $send to see what it actually cost.
     * @param ?int $sources how many co-sign sessions a real quote needs (one FROST commitment
     *   each at sign/begin). The server omits it on a dry preview.
     */
    public function __construct(
        public readonly ?string $movementId,
        public readonly string $mode,
        public readonly Amount $send,
        public readonly Amount $debit,
        public readonly Receive $receive,
        public readonly Fees $fees,
        public readonly string $expiresAt,
        public readonly ?int $sources,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'quote');
        return new self(
            Wire::optStr($o, 'movement_id', 'quote'),
            Wire::str($o, 'mode', 'quote'),
            Amount::fromWire(Wire::req($o, 'send', 'quote'), 'quote.send'),
            Amount::fromWire(Wire::req($o, 'debit', 'quote'), 'quote.debit'),
            Receive::fromWire(Wire::req($o, 'receive', 'quote')),
            Fees::fromWire(Wire::req($o, 'fees', 'quote')),
            Wire::str($o, 'expires_at', 'quote'),
            isset($o['sources']) ? Wire::int($o, 'sources', 'quote') : null,
        );
    }
}

final class Movement
{
    /**
     * @param string $status one of pending | processing | completed | failed | refunded | expired
     *   | cancelled — the public vocabulary; the server never sends its internal states.
     * @param Amount $send What the movement actually cost the vault: the approved maximum minus
     *   anything the route handed back. This is the figure that matches the on-chain balance —
     *   reconcile on it. It is NOT the quote's $debit, which is the ceiling that was approved
     *   before the route ran.
     * @param ?Fees $fees the full breakdown on a single movement; null on a history-page row.
     */
    public function __construct(
        public readonly string $id,
        public readonly string $type,
        public readonly string $status,
        public readonly Amount $send,
        public readonly Receive $receive,
        public readonly ?Fees $fees,
        public readonly ?string $destChainTxHash,
        public readonly ?string $destChainExplorerUrl,
        public readonly string $createdAt,
        public readonly ?string $completedAt,
    ) {}

    public static function fromWire(mixed $o): self
    {
        $o = Wire::object($o, 'movement');
        return new self(
            Wire::str($o, 'id', 'movement'),
            Wire::str($o, 'type', 'movement'),
            Wire::str($o, 'status', 'movement'),
            Amount::fromWire(Wire::req($o, 'send', 'movement'), 'movement.send'),
            Receive::fromWire(Wire::req($o, 'receive', 'movement')),
            isset($o['fees']) ? Fees::fromWire($o['fees']) : null,
            Wire::optStr($o, 'dest_chain_tx_hash', 'movement'),
            Wire::optStr($o, 'dest_chain_explorer_url', 'movement'),
            Wire::str($o, 'created_at', 'movement'),
            Wire::optStr($o, 'completed_at', 'movement'),
        );
    }
}

/**
 * @internal Field readers for the models above. Each one throws PaymosError naming the field
 * when the wire does not carry what the contract says it does.
 */
final class Wire
{
    /** A JSON object. `{}` decodes to [] in PHP, so an empty array passes; a non-empty list does not. */
    public static function object(mixed $v, string $what): array
    {
        if (!is_array($v) || ($v !== [] && array_is_list($v))) {
            throw new PaymosError("malformed response: $what is not a JSON object");
        }
        return $v;
    }

    /** A JSON array, as the /assets and /balances endpoints answer — bare, not wrapped. */
    public static function list(mixed $v, string $what): array
    {
        if (!is_array($v) || !array_is_list($v)) {
            throw new PaymosError("malformed response: $what is not a JSON array");
        }
        return $v;
    }

    public static function req(array $o, string $key, string $what): mixed
    {
        if (!isset($o[$key])) {
            throw new PaymosError("malformed response: $what.$key is missing");
        }
        return $o[$key];
    }

    public static function str(array $o, string $key, string $what): string
    {
        $v = self::req($o, $key, $what);
        if (!is_string($v) && !is_int($v)) {
            throw new PaymosError("malformed response: $what.$key is not a string");
        }
        return (string)$v;
    }

    /** Absent and null are the same thing on this wire: the server omits what is null. */
    public static function optStr(array $o, string $key, string $what): ?string
    {
        return isset($o[$key]) ? self::str($o, $key, $what) : null;
    }

    public static function int(array $o, string $key, string $what): int
    {
        $v = self::req($o, $key, $what);
        if (is_string($v) && preg_match('/^-?[0-9]{1,18}$/D', $v)) {
            return (int)$v;
        }
        if (!is_int($v)) {
            throw new PaymosError("malformed response: $what.$key is not an integer");
        }
        return $v;
    }

    public static function bool(array $o, string $key, string $what): bool
    {
        $v = self::req($o, $key, $what);
        if (!is_bool($v)) {
            throw new PaymosError("malformed response: $what.$key is not a boolean");
        }
        return $v;
    }
}
