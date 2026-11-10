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
 * path never catches a raw curl error. A body that is not JSON — on any status — is a typed error
 * too, naming the status and content type; it is never a null handed to the caller.
 */
final class Http
{
    /** The sign/aggregate round can trigger a relay that takes a while. */
    private const DEFAULT_TIMEOUT = 60;

    /** How much of an unexpected body a message quotes: enough to recognise a proxy page. */
    private const SNIPPET_CHARS = 200;

    /** @var null|callable(string $method, string $url, array $headers, ?string $body): array{0: int, 1: array, 2: string} */
    private $transport;

    public function __construct(
        private readonly string $baseUrl,
        #[\SensitiveParameter] private readonly string $apiKey,
        private readonly int $timeout = self::DEFAULT_TIMEOUT,
        ?callable $transport = null,
    ) {
        $this->transport = $transport;
    }

    /** var_dump and print_r show where the transport points, and the key's prefix only. */
    public function __debugInfo(): array
    {
        return ['baseUrl' => $this->baseUrl, 'apiKey' => VaultSecret::mask($this->apiKey)];
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
        try {
            $json = json_encode($body, JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            // Invalid UTF-8 in an address, say. Refuse before anything reaches the wire.
            throw new PaymosError('request body is not encodable as JSON: ' . $e->getMessage());
        }
        return $this->request('POST', $this->baseUrl . $path,
            array_merge(['content-type' => 'application/json'], $extraHeaders), $json);
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
        $status = (int)$status;
        $text = (string)$text;
        $respHeaders = array_change_key_case(is_array($respHeaders) ? $respHeaders : [], CASE_LOWER);
        $contentType = isset($respHeaders['content-type']) ? (string)$respHeaders['content-type'] : null;

        // The Vault API answers JSON on every status, errors included. A body that is not JSON is
        // almost always something standing in front of it — a proxy's HTML 404, a CDN page, the
        // wrong base URL — and it must surface as a typed error, never as a parse failure or null.
        $parsed = self::decode($text, $isJson);
        if ($status >= 200 && $status < 300) {
            if (!$isJson) {
                throw new PaymosError(self::notJsonMessage($status, $contentType, $text), $status);
            }
            return $parsed;
        }
        $message = $isJson
            ? self::messageOf(is_array($parsed) ? $parsed : null, $status, $text)
            : self::notJsonMessage($status, $contentType, $text);
        $retryAfter = $respHeaders['retry-after'] ?? null;
        throw self::raiseFor($status, $message, is_scalar($retryAfter) ? (string)$retryAfter : null);
    }

    /** JSON-decode without ever throwing; $isJson says whether $text was JSON at all. */
    private static function decode(string $text, ?bool &$isJson): mixed
    {
        try {
            // Big integers stay strings: a raw amount must never pass through a float.
            $parsed = json_decode($text, true, 512, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
            $isJson = true;
            return $parsed;
        } catch (\JsonException) {
            $isJson = false;
            return null;
        }
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
            CURLOPT_CONNECTTIMEOUT => min(10, $this->timeout),
            // Never follow a redirect: the API does not issue one, a 3xx here means something in
            // front of it answered, and following it would replay the bearer token and the POST
            // body somewhere else. It surfaces as a typed error instead, like the Python SDK.
            CURLOPT_FOLLOWLOCATION => false,
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
        // No curl_close(): it has done nothing since PHP 8.0 and is deprecated in 8.5, where every
        // call would print a deprecation notice into the caller's output. The handle frees itself.
        if ($text === false) {
            throw new PaymosError('transport error: ' . curl_error($ch));
        }
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
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
        $snippet = self::snippet($text);
        return $snippet === '' ? "request failed with status $status" : $snippet;
    }

    /** Same words as the Python SDK, so a support ticket reads the same whichever SDK sent it. */
    private static function notJsonMessage(int $status, ?string $contentType, string $text): string
    {
        $ctype = trim(explode(';', (string)$contentType)[0]);
        $msg = "unexpected non-JSON response (HTTP $status, " . ($ctype === '' ? 'no content-type' : $ctype)
            . ') — check that the base URL (PAYMOS_BASE_URL) points at the Paymos wallet API';
        $snippet = self::snippet($text);
        return $snippet === '' ? $msg : "$msg: $snippet";
    }

    /** The start of a body, whitespace collapsed and cut short — never a whole HTML page. */
    private static function snippet(string $text): string
    {
        $flat = trim((string)preg_replace('/\s+/', ' ', $text));
        if (strlen($flat) <= self::SNIPPET_CHARS) {
            return $flat;
        }
        // Cut on bytes, then drop a trailing multi-byte sequence the cut may have split.
        $cut = (string)preg_replace('/[\x80-\xBF]*[\xC0-\xFF]?$/', '', substr($flat, 0, self::SNIPPET_CHARS));
        return $cut . '…';
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

    private static function raiseFor(int $status, string $message, ?string $retryAfterHeader): PaymosError
    {
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
