<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * The exact 32 bytes the vault's signature is computed over — a byte-for-byte port of the
 * Python SDK's digest, which is itself pinned to the server by a cross-language golden vector.
 *
 * The SDK uses this to VERIFY, before a single FROST commitment leaves the process, that the
 * server disclosed the true message it is asking the vault to sign. Never blind-sign.
 *
 * digest = sha256 of:
 *   u32   tag                 fixed domain separation; must byte-match the server
 *   string message            u32-LE length prefix + utf-8
 *   bytes32 nonce             raw, exactly 32
 *   string recipient          u32-LE length prefix + utf-8
 *   option<string> callback   0x00 = none, else 0x01 + string
 */
final class Digest
{
    /** Part of the wire contract. Change it and the client and server stop agreeing forever. */
    private const MSG_TAG = 2147484061;

    /** The 32-byte sha256 digest the vault's ed25519 signature is over. $nonce must be exactly 32. */
    public static function payloadDigest(string $message, string $nonce, string $recipient, ?string $callbackUrl = null): string
    {
        if (strlen($nonce) !== 32) {
            throw new PaymosError('nonce must be 32 bytes, got ' . strlen($nonce));
        }
        $w = pack('V', self::MSG_TAG);
        $w .= self::lenPrefixedUtf8($message);
        $w .= $nonce;
        $w .= self::lenPrefixedUtf8($recipient);
        $w .= $callbackUrl === null ? "\x00" : "\x01" . self::lenPrefixedUtf8($callbackUrl);
        return hash('sha256', $w, true);
    }

    /** The digest as lowercase hex — the form the FROST signing package carries, so the two compare. */
    public static function payloadDigestHex(string $message, string $nonce, string $recipient, ?string $callbackUrl = null): string
    {
        return bin2hex(self::payloadDigest($message, $nonce, $recipient, $callbackUrl));
    }

    private static function lenPrefixedUtf8(string $s): string
    {
        return pack('V', strlen($s)) . $s;
    }
}
