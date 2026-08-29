<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * The read model, ported field-for-field from the Python SDK. Plain readonly DTOs; every amount
 * is a STRING of raw integer units, never a number — a token with 18 decimals runs past what an
 * int64 or a float holds exactly.
 */
final class Asset
{
    public function __construct(
        public readonly string $asset,
        public readonly string $symbol,
        public readonly string $chain,
        public readonly int $decimals,
    ) {}

    public static function fromWire(array $o): self
    {
        return new self((string)($o['asset'] ?? ''), (string)($o['symbol'] ?? ''),
            (string)($o['chain'] ?? ''), (int)($o['decimals'] ?? 0));
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

    public static function fromWire(array $o): self
    {
        return new self((string)($o['asset'] ?? ''), (string)($o['symbol'] ?? ''),
            (string)($o['chain'] ?? ''), (int)($o['decimals'] ?? 0),
            (string)($o['amount_raw'] ?? ''), isset($o['usd']) ? (string)$o['usd'] : null);
    }
}

/** The routing layer's own fee. $estimate marks a figure that can still move. */
final class RouteFee
{
    public function __construct(
        public readonly string $amount,
        public readonly bool $estimate,
    ) {}
}

/** Every charge on an operation, split so none of them can hide inside another. */
final class Fees
{
    public function __construct(
        public readonly string $asset,
        public readonly string $platform,
        public readonly string $network,
        public readonly ?RouteFee $route,
        public readonly string $total,
        public readonly ?array $usd,
    ) {}

    public static function fromWire(?array $o): ?self
    {
        if ($o === null) {
            return null;
        }
        $route = isset($o['route']) && is_array($o['route'])
            ? new RouteFee((string)($o['route']['amount'] ?? ''), (bool)($o['route']['estimate'] ?? false))
            : null;
        return new self((string)($o['asset'] ?? ''), (string)($o['platform'] ?? ''),
            (string)($o['network'] ?? ''), $route, (string)($o['total'] ?? ''),
            isset($o['usd']) && is_array($o['usd']) ? $o['usd'] : null);
    }
}

final class Amount
{
    public function __construct(
        public readonly string $amount,
        public readonly string $asset,
    ) {}

    public static function fromWire(?array $o): self
    {
        $o ??= [];
        return new self((string)($o['amount'] ?? ''), (string)($o['asset'] ?? ''));
    }
}

/** What arrives. $min is the floor the operation is allowed to deliver, and it is guaranteed. */
final class Receive
{
    public function __construct(
        public readonly string $amount,
        public readonly string $min,
        public readonly string $asset,
    ) {}

    public static function fromWire(?array $o): self
    {
        $o ??= [];
        return new self((string)($o['amount'] ?? ''), (string)($o['min'] ?? ''), (string)($o['asset'] ?? ''));
    }
}

final class Quote
{
    /**
     * @param Amount $debit The MAXIMUM this quote may take from the vault, reserve against
     *   slippage included — a ceiling, not a prediction. Approve against this and nothing can
     *   debit more than you agreed to; an exact-output route routinely spends less and returns
     *   the difference. Compare the finished movement's $send to see what it actually cost.
     */
    public function __construct(
        public readonly ?string $movementId,
        public readonly string $mode,
        public readonly Amount $send,
        public readonly Amount $debit,
        public readonly Receive $receive,
        public readonly ?Fees $fees,
        public readonly string $expiresAt,
        public readonly ?int $sources,
    ) {}

    public static function fromWire(array $o): self
    {
        return new self(
            isset($o['movement_id']) ? (string)$o['movement_id'] : null,
            (string)($o['mode'] ?? ''),
            Amount::fromWire($o['send'] ?? null),
            Amount::fromWire($o['debit'] ?? null),
            Receive::fromWire($o['receive'] ?? null),
            Fees::fromWire(isset($o['fees']) && is_array($o['fees']) ? $o['fees'] : null),
            (string)($o['expires_at'] ?? ''),
            isset($o['sources']) ? (int)$o['sources'] : null,
        );
    }
}

final class Movement
{
    /**
     * @param Amount $send What the movement actually cost the vault: the approved maximum minus
     *   anything the route handed back. This is the figure that matches the on-chain balance —
     *   reconcile on it. It is NOT the quote's $debit, which is the ceiling that was approved
     *   before the route ran.
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

    public static function fromWire(array $o): self
    {
        return new self(
            (string)($o['id'] ?? ''),
            (string)($o['type'] ?? ''),
            (string)($o['status'] ?? ''),
            Amount::fromWire($o['send'] ?? null),
            Receive::fromWire($o['receive'] ?? null),
            Fees::fromWire(isset($o['fees']) && is_array($o['fees']) ? $o['fees'] : null),
            isset($o['dest_chain_tx_hash']) ? (string)$o['dest_chain_tx_hash'] : null,
            isset($o['dest_chain_explorer_url']) ? (string)$o['dest_chain_explorer_url'] : null,
            (string)($o['created_at'] ?? ''),
            isset($o['completed_at']) ? (string)$o['completed_at'] : null,
        );
    }
}
