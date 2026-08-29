<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * Authenticated transport, ported from the Python SDK so every language fails the same way.
 *
 * Attaches "Authorization: Bearer <api key>" to every request, returns parsed JSON on a 2xx, and
 * maps anything else onto a typed error through the server's envelope. The server's contract:
 * business errors are {"error": "…"} and the sign flow answers
 * {"ok":false,"status":"failed","error":"…"} — both carry the message under "error". Those
 * messages arrive already client-safe, so this layer maps them and never rewrites them, and it
 * never logs or echoes a request body.
 *
 * Transport failures are wrapped into a typed error with no status, so a caller on the money
 * path never catches a raw curl error.
 */
final class Http
{
    /** The sign/aggregate round can trigger a relay that takes a while. */
    private const DEFAULT_TIMEOUT = 60;

    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0: int, 1: array, 2: string} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        private readonly string $apiKey,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /** @return mixed parsed JSON body */
    public function get(string $path, array $query = []): mixed
    {
        $query = array_filter($query, fn ($v) => $v !== null);
        $url = $this->baseUrl . $path . ($query === [] ? '' : '?' . http_build_query($query));
        return $this->request('GET', $url, [], null);
    }

    public function post(string $path, array $body, array $extraHeaders = []): mixed
    {
        return $this->request('POST', $this->baseUrl . $path,
            array_merge(['content-type' => 'application/json'], $extraHeaders), json_encode($body));
    }

    private function request(string $method, string $url, array $headers, ?string $body): mixed
    {
        $headers = array_merge(['authorization' => 'Bearer ' . $this->apiKey], $headers);

        try {
            if ($this->transport !== null) {
                [$status, $respHeaders, $text] = ($this->transport)($method, $url, $headers, $body);
            } else {
                [$status, $respHeaders, $text] = $this->curl($method, $url, $headers, $body);
            }
        } catch (PaymosError $e) {
            throw $e;
        } catch (\Throwable $e) {
            throw new PaymosError('transport error: ' . $e->getMessage());
        }

        $parsed = $text === '' ? null : json_decode($text, true);
        if ($status >= 200 && $status < 300) {
            return $parsed;
        }
        throw self::raiseFor($status, is_array($parsed) ? $parsed : null, $text, $respHeaders['retry-after'] ?? null);
    }

    /** @return array{0: int, 1: array, 2: string} */
    private function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        $headerLines = [];
        foreach ($headers as $k => $v) {
            $headerLines[] = "$k: $v";
        }
        $respHeaders = [];
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $this->timeout,
            CURLOPT_FOLLOWLOCATION => true, // GitHub releases redirect to a CDN
            CURLOPT_HTTPHEADER => $headerLines,
            CURLOPT_HEADERFUNCTION => function ($ch, $line) use (&$respHeaders) {
                $parts = explode(':', $line, 2);
                if (count($parts) === 2) {
                    $respHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                }
                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $text = curl_exec($ch);
        if ($text === false) {
            $err = curl_error($ch);
            curl_close($ch);
            throw new PaymosError("transport error: $err");
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return [$status, $respHeaders, (string)$text];
    }

    private static function messageOf(?array $body, int $status, string $text): string
    {
        if ($body !== null) {
            foreach (['error', 'detail'] as $key) {
                $msg = $body[$key] ?? null;
                if (is_string($msg) && $msg !== '') {
                    return $msg;
                }
            }
        }
        $trimmed = trim($text);
        return $trimmed === '' ? "request failed with status $status" : $trimmed;
    }

    /** Map a 400's message to a type. First match wins, in this exact order. */
    private static function classify400(string $message, int $status): PaymosError
    {
        $m = strtolower($message);
        if (str_contains($m, 'cross-asset')) {
            return new CrossAssetWithdrawNotAllowed($message, $status);
        }
        if (str_contains($m, 'slippage')) {
            return new SlippageExceeded($message, $status);
        }
        if (str_contains($m, 'expired')) {
            return new QuoteExpired($message, $status);
        }
        foreach (['insufficient', 'not enough balance', 'exceeds the vault', 'available balance'] as $t) {
            if (str_contains($m, $t)) {
                return new InsufficientFunds($message, $status);
            }
        }
        // The server's route and liquidity rejections come back sounding transient — "No liquidity
        // available … try again in a moment", "route can't be quoted". That whole family is one type.
        foreach (['route', "can't be quoted", 'liquidity', 'try again in a moment'] as $t) {
            if (str_contains($m, $t)) {
                return new RouteUnavailable($message, $status);
            }
        }
        return new PaymosError($message, $status);
    }

    private static function raiseFor(int $status, ?array $body, string $text, ?string $retryAfterHeader): PaymosError
    {
        $message = self::messageOf($body, $status, $text);
        return match ($status) {
            401 => new AuthError($message, $status),
            403 => new Forbidden($message, $status),
            409 => new Conflict($message, $status),
            429 => new RateLimited($message, $status,
                $retryAfterHeader !== null && ctype_digit(trim($retryAfterHeader))
                    ? (int)trim($retryAfterHeader) : null),
            400 => self::classify400($message, $status),
            // 404 has no type of its own by design; it and every other non-2xx land on the base.
            default => new PaymosError($message, $status),
        };
    }
}
