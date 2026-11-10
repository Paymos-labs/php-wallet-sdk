<?php

declare(strict_types=1);

namespace Paymos\Wallet;

/**
 * The "vs_live_…" vault secret: an API key, the vault id, and — for a full key — this side's
 * FROST signing share. Ported from the Python SDK, same envelope, same field names, so one
 * secret works with any of the SDKs.
 *
 * var_dump, print_r, (string) and json_encode show the key's vk_live_ prefix, the vault id and
 * whether there is a share — never the key or the share; a stack trace shows neither as an
 * argument (#[\SensitiveParameter], PHP 8.2+). What PHP offers no hook for: var_export, an
 * (array) cast, serialize() and reflection still read the public properties.
 */
final class VaultSecret implements \JsonSerializable
{
    private const PREFIX = 'vs_live_';

    private function __construct(
        #[\SensitiveParameter] public readonly string $apiKey,
        public readonly int $vaultId,
        /** This side's FROST key package JSON. Null on a read-only secret. Never rendered. */
        #[\SensitiveParameter] public readonly ?string $share,
    ) {}

    public function hasShare(): bool
    {
        return $this->share !== null;
    }

    public static function pack(#[\SensitiveParameter] string $apiKey, int $vaultId,
                                #[\SensitiveParameter] ?string $share): string
    {
        $payload = json_encode(['k' => $apiKey, 'v' => $vaultId, 's' => $share]);
        return self::PREFIX . rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public static function parse(#[\SensitiveParameter] string $secret): self
    {
        if (!str_starts_with($secret, self::PREFIX)) {
            throw new PaymosError('not a vs_live_ vault secret');
        }
        $b64 = substr($secret, strlen(self::PREFIX));
        // base64_decode wants canonical padding; the envelope drops it.
        $b64 .= str_repeat('=', (4 - strlen($b64) % 4) % 4);
        $raw = base64_decode(strtr($b64, '-_', '+/'), true);
        $o = $raw === false ? null : json_decode($raw, true);
        if (!is_array($o)) {
            throw new PaymosError('malformed vault secret');
        }
        $apiKey = $o['k'] ?? null;
        if (!is_string($apiKey) || !str_starts_with($apiKey, 'vk_live_')) {
            throw new PaymosError('vault secret carries no valid api key');
        }
        $vaultId = $o['v'] ?? null;
        if (!is_int($vaultId)) {
            throw new PaymosError('vault secret carries no valid vault id');
        }
        $share = $o['s'] ?? null;
        return new self($apiKey, $vaultId, is_string($share) ? $share : null);
    }

    /** Never renders the share, and masks the key: a stray log line must not leak either. */
    public function __toString(): string
    {
        return 'VaultSecret(apiKey=' . self::mask($this->apiKey) . ", vaultId={$this->vaultId}, hasShare="
            . var_export($this->hasShare(), true) . ')';
    }

    /** What var_dump, print_r and Symfony's VarDumper show: the same masked fields. */
    public function __debugInfo(): array
    {
        return ['apiKey' => self::mask($this->apiKey), 'vaultId' => $this->vaultId, 'hasShare' => $this->hasShare()];
    }

    /** json_encode — and Monolog, which serializes context with it — gets the masked fields too. */
    public function jsonSerialize(): array
    {
        return $this->__debugInfo();
    }

    /** Only the non-secret vk_live_ prefix of a key. */
    public static function mask(#[\SensitiveParameter] string $key): string
    {
        return $key === '' ? '…' : substr($key, 0, 8) . '…';
    }
}
