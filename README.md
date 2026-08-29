# paymos/wallet — PHP SDK

Send, swap and bridge **USDT, USDC, BTC, ETH** and the rest of the wallet's catalogue from
PHP — no browser, no wallet extension, and no seed phrase anywhere in your environment. It is
built for the services that pay people out: marketplaces, exchanges, payroll and treasury jobs.

Your process holds **one half** of the signing key; the Paymos server holds the other.
**Neither side alone can move funds** — every swap and withdrawal is co-signed, and your half
never reaches the server.

- **Non-custodial** — a leaked API key can't sign; your co-signing share stays in your process.
- **Fee-transparent** — every quote is a dry preview with a full `platform / network / route`
  fee breakdown you see *before* signing anything.
- **Human amounts, no floats** — you pass decimal strings like `"100"` / `"0.5"`; responses come
  back as raw integer strings.
- **PHP 8.1+**, zero composer dependencies (ext-ffi, ext-json, ext-curl only).

The SDK talks in plain asset terms (`USDC@base`, `ETH@arb`) and hides the routing and the
2-of-2 signing behind a small, safe surface.

## Install

```bash
composer require paymos/wallet
```

Signing (not reads) needs `ffi.enable=true` in php.ini — reads and quotes work without it.

The native co-signing core is **not** inside the package. On first use the SDK downloads the
pinned, platform-matching build from the `paymos-wallet-core` releases, verifies its SHA-256 against
the release's checksum, and caches it per user.

A prebuilt core exists for **Linux x86-64**, **Linux arm64** and **Windows x64**. The SDK also
recognises macOS on both architectures, but no macOS build is published yet — there is no Mac to
produce one on, and cross-compiling an Apple dylib elsewhere is not the same thing. On any platform
without a prebuilt core, build the crate yourself and set `PAYMOS_NATIVE_LIB` to the resulting
`libwallet_mpc.so` / `.dylib` / `wallet_mpc.dll`. The same variable skips the download when you want
it skipped: an offline host, or a corporate proxy that will not reach GitHub.

## Get access & your API key

The SDK controls a **Paymos vault**, which you own from the **Paymos wallet** — a Telegram-only
Mini App. Open **@Ox0000000000000000000Bot**, tap **Open Wallet**, then **Settings → Vault API**
and mint a key:

| Key | Can do | Carries |
|---|---|---|
| **read** | `assets`, `balances`, `quoteSwap`, `quoteWithdraw`, `movement`, `movements` | API key only |
| **full** | everything a read key does, **plus** `swap` and `withdraw` | API key **+** your co-signing share |

The key is a `vs_live_…` string. A full key's co-signing share is decrypted on your device and
**never leaves your process**. Treat a full secret like a private key.

```bash
export PAYMOS_VAULT_SECRET="vs_live_…"
# optional — defaults to https://wallet.paymos.io
# export PAYMOS_BASE_URL="https://wallet.paymos.io"
```

## Quickstart

```php
<?php
require 'vendor/autoload.php';

use Paymos\Wallet\Wallet;

$w = Wallet::fromEnv();                      // reads PAYMOS_VAULT_SECRET

// Balances: per-asset, raw amount strings + a nullable USD value.
foreach ($w->balances() as $b) {
    echo "$b->asset $b->amountRaw $b->usd\n";
}

// Preview a withdraw — a dry, money-safe quote. Headline is the fee breakdown.
$q = $w->quoteWithdraw('USDC@base', '25', '0xRecipient…');
echo "{$q->fees->platform} {$q->fees->network} {$q->fees->total}\n";

// Move money (full key only). Amounts are human decimal strings.
$mv = $w->swap('USDC@base', 'ETH@arb', '100');
$settled = $w->wait($mv->id);                // poll to a terminal status
echo $settled->status, "\n";
```

## Core concepts

**Amounts: human in, raw out.** You pass human decimal strings; the server owns each asset's
decimals and scales them to raw. Responses come back as raw integer strings. Never a float, in
either direction — PHP integers are 64-bit and floats round, so the SDK compares and adds raw
amounts as digit strings. `Amounts::parseUnits` / `Amounts::formatUnits` convert.

**Moving money.** `swap` and `withdraw` (full key only) create a real movement and drive the
2-of-2 co-sign. Before any signature the SDK re-checks that the server's quote echoes exactly
the assets and amount you approved — a decimals bug, contract drift, or a lying server throws
and **signs nothing**.

```php
$mv = $w->swap('USDC@base', 'ETH@arb', '100', minReceive: '0.03');
$wd = $w->withdraw('USDC@base', '25', '0xRecipient…', maxDebit: '26');
```

**Safety caps.** `minReceive` floors the guaranteed receive (`SlippageExceeded` below it,
nothing signed); `maxDebit` ceilings the total vault debit — strongly recommended for
unattended `exact_out` payouts; `idempotencyKey` makes a retry after an ambiguous failure
replay the same movement instead of paying twice.

**Withdraw modes.** `"exact_out"` (default) — the recipient gets exactly `$amount`, the debit is
server-computed. `"total_in"` — `$amount` is the total debited; the recipient gets it minus
fees.

**Waiting.** `wait(id)` polls to a terminal status — `completed | failed | refunded | expired |
cancelled` — and throws on timeout.

## Errors

Every call throws a subclass of `PaymosError`, which carries `getMessage()` and `status` (the
HTTP status, or null for a transport-level failure). Branch on the class:

| Exception | When |
|---|---|
| `AuthError` | `401` — API key missing, malformed, or unrecognized |
| `Forbidden` | `403` — the key is valid but lacks the scope this call needs |
| `InsufficientFunds` | `400` — the vault balance can't cover the amount (plus fees) |
| `QuoteExpired` | `400` — the referenced quote expired; fetch a fresh one |
| `SlippageExceeded` | `400` or local — delivered / guaranteed amount fell below your floor |
| `CrossAssetWithdrawNotAllowed` | `400` — a withdraw changed the asset (that's a swap) |
| `RouteUnavailable` | `400` — no route could be quoted right now; retry shortly |
| `RateLimited` | `429` — carries `retryAfter` (seconds, or null) |
| `Conflict` | `409` — idempotency key reused with a different request |
| `PaymosError` | base — `404`, any other 4xx/5xx, local refusals, transport errors |

```php
use Paymos\Wallet\{PaymosError, SlippageExceeded, InsufficientFunds, RateLimited};

try {
    $mv = $w->swap('USDC@base', 'ETH@arb', '100', minReceive: '0.03');
} catch (SlippageExceeded) {
    // guaranteed receive below your floor — nothing signed
} catch (InsufficientFunds) {
    // not enough balance for amount + fees
} catch (RateLimited $e) {
    sleep($e->retryAfter ?? 1);
} catch (PaymosError $e) {
    error_log("{$e->status} {$e->getMessage()}");
}
```

Calling `swap` / `withdraw` with a **read-only** key throws *before* any movement is created —
a read key can never sign.

## Security

- A leaked **API key alone cannot move funds** — no share, no signature (and the key can be
  revoked).
- A leaked **share alone cannot move funds** — no server co-sign.
- Both are required, on purpose. The SDK additionally verifies every message it co-signs, and
  that the server's quote matches exactly what you approved — so a compromised server can't
  redirect funds or inflate an amount past your caps.

Treat a full `vs_live_…` secret like a private key, and back up the share — losing it means
losing your ability to co-sign.

## License

MIT for this wrapper — see `LICENSE`. The co-signing core it downloads is proprietary; its
binary distribution grants no rights to its source.

---

## FAQ

### How do I send USDT (TRC20) from code?

Install the SDK, point it at your vault, and ask for a withdrawal with the asset written the way the
wallet writes it — `USDT@tron`. The same call sends USDT on BNB Chain (`USDT@bsc`), Ethereum
(`USDT@eth`), Polygon, Solana, Arbitrum, Avalanche or TON: the asset string is the only difference.

```
composer require paymos/wallet
```

### Is this custodial? Who can move the money?

Nobody alone. The vault is **2-of-2**: your process holds one signing share, the server holds the
other, and a transfer needs both. A stolen API key cannot sign, and the server cannot sign without
you. Your share never leaves your process.

### What is the difference between a quote and a transfer?

A quote is a dry run. It prices the route, separates platform, network and route fees, and reserves
nothing — ask for as many as you like. A transfer is the same numbers, signed. Nothing moves until
your half of the signature exists.

### Does it blind-sign whatever the server asks for?

No, and this is the part worth reading twice. The server discloses the exact message it wants
signed; the SDK recomputes the digest itself and refuses unless that message belongs to your vault,
moves no more than the debit you approved, and matches the request you made. A server asking for
anything else gets a refusal, not a signature.

### Which networks and assets are supported?

USDT moves on nine chains — Tron, Ethereum, BNB Chain, Polygon, Solana, Arbitrum, Optimism,
Avalanche and TON — and USDC on eight: that same list without Tron and TON, with Base instead. Add
the native coin of each of those chains, and BTC, LTC, DOGE, XRP, BCH, DASH, ZEC and ADA, and the
catalogue is 21 assets across 18 chains. It is also the authority: an asset it does not list cannot
be quoted or sent, in any SDK.

### How are fees reported?

Every quote breaks the cost into platform, network and route, and amounts come back as raw integer
strings rather than floats: money that has been through a float is money with a rounding error in
it. You pass human amounts in (`"100"`, `"0.5"`) and read exact integers back.

### Can I use it for automated payouts?

That is what it is for: headless, no browser, no wallet extension, no seed phrase in the environment.
A worker holding one share can pay out continuously, and losing that machine costs the ability to
sign — not the funds.

### What happens if a transfer is interrupted?

Every money-moving call takes an idempotency key. Repeating a call with the same key returns the
original operation instead of starting a second one, so a retry after a timeout cannot pay twice.

## The same wallet, in eight languages

Every SDK here speaks to the same vault API and enforces the same rules; pick the one your service is
written in. The signing core is shared, so a fix there reaches all of them.

| Language | Package | Repository |
|---|---|---|
| Python | `pip install paymos-wallet` | [python-wallet-sdk](https://github.com/paymos-labs/python-wallet-sdk) |
| TypeScript / Node.js | `npm install @paymos/wallet` | [typescript-wallet-sdk](https://github.com/paymos-labs/typescript-wallet-sdk) |
| Go | `go get github.com/paymos-labs/go-wallet-sdk` | [go-wallet-sdk](https://github.com/paymos-labs/go-wallet-sdk) |
| Rust | `cargo add paymos-wallet` | [rust-wallet-sdk](https://github.com/paymos-labs/rust-wallet-sdk) |
| C# / .NET | `dotnet add package Paymos.Wallet` | [csharp-wallet-sdk](https://github.com/paymos-labs/csharp-wallet-sdk) |
| Java | `io.paymos:wallet` | [java-wallet-sdk](https://github.com/paymos-labs/java-wallet-sdk) |
| Ruby | `gem install paymos-wallet` | [ruby-wallet-sdk](https://github.com/paymos-labs/ruby-wallet-sdk) |
| PHP *(this one)* | `composer require paymos/wallet` | [php-wallet-sdk](https://github.com/paymos-labs/php-wallet-sdk) |
| Signing core | `paymos-wallet-core` | [paymos-wallet-core](https://github.com/paymos-labs/paymos-wallet-core) |

## Documentation

- [wallet.paymos.io](https://wallet.paymos.io) — the product, the supported networks, and guides for
  the routes people ask about most: USDT BEP20 to TRC20, USDC between chains, and the rest.
- [Networks and assets](https://wallet.paymos.io/networks) — every chain and token the wallet
  carries, with the standard each one is named by.
- [USDT BEP20 → TRC20](https://wallet.paymos.io/guides/usdt-bep20-to-trc20) — a worked route, priced
  live; the other pairs are linked from it.
