<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * The ten error types of the SDK spec. PaymosError is the base case; the nine subclasses are the
 * classifications a caller branches on. The set is fixed by the SDK spec — deliberately no
 * NotFound, since a 404 surfaces as the base PaymosError.
 *
 * $message is the server's already client-safe reason; $status is the HTTP code that produced
 * it, or null when the error did not come from a response.
 */
class PaymosError extends \RuntimeException
{
    public function __construct(string $message, public readonly ?int $status = null)
    {
        parent::__construct($message);
    }
}

/** 401 — the API key is missing, malformed, or not recognised. */
class AuthError extends PaymosError {}

/** 403 — the key is valid but lacks the scope this call requires. */
class Forbidden extends PaymosError {}

/** 400 — the vault's available balance cannot cover the amount plus fees. */
class InsufficientFunds extends PaymosError {}

/** 400 — the referenced quote has expired; fetch a fresh one and retry. */
class QuoteExpired extends PaymosError {}

/** 400 — the delivered amount fell outside the accepted slippage bound. */
class SlippageExceeded extends PaymosError {}

/** 400 — a withdraw changed the asset; that is a swap, and it is rejected here. */
class CrossAssetWithdrawNotAllowed extends PaymosError {}

/** 400 — no route could be quoted for this pair right now; retry shortly. */
class RouteUnavailable extends PaymosError {}

/** 429 — too many requests. $retryAfter is the Retry-After seconds when the server sent one. */
class RateLimited extends PaymosError
{
    public function __construct(string $message, ?int $status = null, public readonly ?int $retryAfter = null)
    {
        parent::__construct($message, $status);
    }
}

/** 409 — idempotency-key conflict: reused with a different request, or a quote still forming. */
class Conflict extends PaymosError {}
