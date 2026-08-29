<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * Human decimal ↔ raw integer, ported from the Python SDK.
 *
 * Raw amounts are strings rather than numbers on purpose: PHP integers are 64-bit and floats
 * round silently — the one failure nobody notices until it is somebody's balance. These
 * functions are string-only, so no amount is too long to parse exactly. The same string
 * discipline powers cmpRaw/addRaw, which the money path uses instead of a numeric cast.
 */
final class Amounts
{
    /** "1.5", 6 → "1500000". Throws on anything that is not a non-negative decimal. */
    public static function parseUnits(string $human, int $decimals): string
    {
        if ($decimals < 0) {
            throw new PaymosError('decimals must be >= 0');
        }
        $s = trim($human);
        if (!preg_match('/^\d+(\.\d+)?$/', $s)) {
            throw new PaymosError("not a non-negative decimal: " . var_export($human, true));
        }
        [$whole, $frac] = array_pad(explode('.', $s, 2), 2, '');
        if (strlen($frac) > $decimals) {
            throw new PaymosError("more than $decimals fractional digits: " . var_export($human, true));
        }
        $raw = $whole . $frac . str_repeat('0', $decimals - strlen($frac));
        return self::stripLeadingZeros($raw);
    }

    /** "1500000", 6 → "1.5". Trailing zeros in the fraction are dropped. */
    public static function formatUnits(string $raw, int $decimals): string
    {
        $sign = '';
        $digits = $raw;
        if (str_starts_with($digits, '-')) {
            $sign = '-';
            $digits = substr($digits, 1);
        }
        if (!preg_match('/^\d+$/', $digits)) {
            throw new PaymosError("not a raw integer amount: " . var_export($raw, true));
        }
        $digits = self::stripLeadingZeros($digits);
        if ($digits === '0') {
            $sign = ''; // the sign of nothing is nothing
        }
        if ($decimals === 0) {
            return $sign . $digits;
        }
        $padded = str_pad($digits, $decimals + 1, '0', STR_PAD_LEFT);
        $whole = substr($padded, 0, -$decimals);
        $frac = rtrim(substr($padded, -$decimals), '0');
        return $frac === '' ? $sign . $whole : "$sign$whole.$frac";
    }

    /** Compare two raw integer amounts: -1/0/1. Non-digit input throws — fail-closed. */
    public static function cmpRaw(string $a, string $b): int
    {
        $a = self::checked($a);
        $b = self::checked($b);
        $a = self::stripLeadingZeros($a);
        $b = self::stripLeadingZeros($b);
        if (strlen($a) !== strlen($b)) {
            return strlen($a) < strlen($b) ? -1 : 1;
        }
        return $a <=> $b;
    }

    /** Add two raw integer amounts, digit by digit — money path never casts to int. */
    public static function addRaw(string $a, string $b): string
    {
        $a = strrev(self::stripLeadingZeros(self::checked($a)));
        $b = strrev(self::stripLeadingZeros(self::checked($b)));
        $carry = 0;
        $out = '';
        $n = max(strlen($a), strlen($b));
        for ($i = 0; $i < $n; $i++) {
            $sum = $carry + (int)($a[$i] ?? '0') + (int)($b[$i] ?? '0');
            $out .= (string)($sum % 10);
            $carry = intdiv($sum, 10);
        }
        if ($carry > 0) {
            $out .= (string)$carry;
        }
        return strrev($out);
    }

    private static function checked(string $raw): string
    {
        if (!preg_match('/^\d+$/', $raw)) {
            throw new PaymosError("bad raw amount: " . var_export($raw, true));
        }
        return $raw;
    }

    private static function stripLeadingZeros(string $s): string
    {
        $stripped = ltrim($s, '0');
        return $stripped === '' ? '0' : $stripped;
    }
}
