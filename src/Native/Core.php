<?php

declare(strict_types=1);

namespace Paymos\Wallet\Native;

use FFI;
use Paymos\Wallet\PaymosError;

/**
 * The SDK's half of the 2-of-2 co-sign, over the same Rust core the Python SDK embeds and the
 * .NET server calls. Nobody reimplements FROST: this is a JSON op in, a JSON op out, and the
 * bytes signed here are the bytes those two would sign.
 *
 * Only two operations run client-side in production: commit (round one, per movement source)
 * and sign (round two, one share per signing package). build_signing_package and aggregate are
 * server-side operations and are deliberately absent.
 *
 * The binary comes from three places, in this order:
 *   1. PAYMOS_NATIVE_LIB — a full path to a local binary. The offline escape hatch.
 *   2. The per-user cache, where a previous run already left a verified binary.
 *   3. The paymos-wallet-core GitHub release — downloaded, SHA-256-checked against the release's
 *      checksum file, then cached. A mismatch refuses: a substituted binary never crosses the
 *      FFI boundary.
 * Whatever the source, the loaded binary must self-report the version this SDK is pinned to
 * (fail-closed), and only then do its exports serve calls.
 */
final class Core
{
    /** The paymos-wallet-core release this SDK build is pinned to. Bump when the core releases. */
    public const CORE_VERSION = '0.1.0';

    private const RELEASE_BASE = 'https://github.com/paymos-labs/paymos-wallet-core/releases/download';

    private const CDEF = <<<'CDEF'
        typedef unsigned char u8;
        typedef unsigned long long usize_t;
        u8* mpc_call(const u8* req, usize_t req_len, usize_t* out_len);
        void mpc_free(u8* ptr, usize_t len);
        CDEF;

    private static ?FFI $ffi = null;

    /** Round one for one signing session. Returns [commitments, nonces]; keep nonces private. */
    public static function commit(array $keyPackage): array
    {
        $r = self::callOp(['op' => 'commit', 'key_package' => $keyPackage]);
        return [$r['commitments'], $r['nonces']];
    }

    /** Round two: this side's signature share, which the server aggregates with its own. */
    public static function sign(array $signingPackage, array $nonces, array $keyPackage): array
    {
        $r = self::callOp([
            'op' => 'sign',
            'signing_package' => $signingPackage,
            'nonces' => $nonces,
            'key_package' => $keyPackage,
        ]);
        return $r['signature_share'];
    }

    /** The loaded core's self-reported version. */
    public static function version(): string
    {
        return self::callOp(['op' => 'version'])['version'];
    }

    /** Run one JSON op against the core; unwraps the {ok, …} envelope. */
    public static function callOp(array $request): array
    {
        $ffi = self::ensureCore();
        $req = json_encode($request);

        $cReq = $ffi->new('u8[' . strlen($req) . ']');
        FFI::memcpy($cReq, $req, strlen($req));
        $outLen = $ffi->new('usize_t');
        $outPtr = $ffi->mpc_call(FFI::addr($cReq[0]), strlen($req), FFI::addr($outLen));
        $len = (int)$outLen->cdata;
        try {
            $raw = FFI::string($outPtr, $len);
        } finally {
            $ffi->mpc_free($outPtr, $len);
        }

        $resp = json_decode($raw, true);
        if (!is_array($resp)) {
            throw new PaymosError('mpc call returned non-JSON');
        }
        if (($resp['ok'] ?? false) !== true) {
            $msg = $resp['error'] ?? null;
            throw new PaymosError(is_string($msg) && $msg !== '' ? $msg : 'mpc call failed');
        }
        return $resp;
    }

    private static function ensureCore(): FFI
    {
        if (self::$ffi !== null) {
            return self::$ffi;
        }
        if (!extension_loaded('ffi')) {
            throw new PaymosError(
                'the co-signing core needs ext-ffi: set ffi.enable=true (reads work without it; signing does not)');
        }
        $path = self::resolvePath();
        try {
            $ffi = FFI::cdef(self::CDEF, $path);
        } catch (\Throwable $e) {
            throw new PaymosError("failed to load the co-signing core from $path: {$e->getMessage()}");
        }

        // The version check goes through the freshly bound library — the core answers for itself
        // before it serves anyone.
        $req = '{"op":"version"}';
        $cReq = $ffi->new('u8[' . strlen($req) . ']');
        FFI::memcpy($cReq, $req, strlen($req));
        $outLen = $ffi->new('usize_t');
        $outPtr = $ffi->mpc_call(FFI::addr($cReq[0]), strlen($req), FFI::addr($outLen));
        $len = (int)$outLen->cdata;
        try {
            $resp = json_decode(FFI::string($outPtr, $len), true);
        } finally {
            $ffi->mpc_free($outPtr, $len);
        }
        $reported = is_array($resp) ? (string)($resp['version'] ?? '') : '';
        if ($reported === '') {
            throw new PaymosError('the loaded co-signing core does not report a version — too old for this SDK');
        }
        if ($reported !== self::CORE_VERSION) {
            throw new PaymosError(
                "co-signing core version mismatch: $path reports v$reported, this SDK is pinned to v"
                    . self::CORE_VERSION
                    . '. Set PAYMOS_NATIVE_LIB to a matching build or clear the paymos native cache');
        }

        self::$ffi = $ffi;
        return $ffi;
    }

    // ── where the binary comes from ──────────────────────────────────────────

    private static function resolvePath(): string
    {
        $rid = self::currentRid();
        $override = getenv('PAYMOS_NATIVE_LIB');
        if (is_string($override) && $override !== '') {
            if (!is_file($override)) {
                throw new PaymosError("PAYMOS_NATIVE_LIB points at a missing file: $override");
            }
            return $override;
        }

        $cacheDir = self::cacheRoot() . DIRECTORY_SEPARATOR . 'v' . self::CORE_VERSION . DIRECTORY_SEPARATOR . $rid;
        $cached = $cacheDir . DIRECTORY_SEPARATOR . self::libName($rid);
        if (is_file($cached)) {
            return $cached;
        }

        $archiveName = 'paymos-wallet-native-v' . self::CORE_VERSION . '-' . $rid . '.tar.gz';
        $archive = self::download(self::RELEASE_BASE . '/v' . self::CORE_VERSION . '/' . $archiveName);
        $checksum = self::download(self::RELEASE_BASE . '/v' . self::CORE_VERSION . '/' . $archiveName . '.sha256');
        self::verifySha256($archive, $checksum, $archiveName);
        self::extractSingleFileTarGz($archive, $cached);
        return $cached;
    }

    private static function currentRid(): string
    {
        $os = match (PHP_OS_FAMILY) {
            'Windows' => 'win',
            'Darwin' => 'osx',
            'Linux' => 'linux',
            default => throw new PaymosError(
                'unsupported OS: ' . PHP_OS_FAMILY . '. Supported: linux/macos/windows on x64 and arm64'),
        };
        $machine = strtolower((string)php_uname('m'));
        $arch = match (true) {
            in_array($machine, ['amd64', 'x86_64'], true) => 'x64',
            in_array($machine, ['arm64', 'aarch64'], true) => 'arm64',
            default => throw new PaymosError(
                "unsupported CPU architecture: $machine. Supported: x64, arm64"),
        };
        return "$os-$arch";
    }

    private static function libName(string $rid): string
    {
        return match (true) {
            str_starts_with($rid, 'win') => 'wallet_mpc.dll',
            str_starts_with($rid, 'osx') => 'libwallet_mpc.dylib',
            default => 'libwallet_mpc.so',
        };
    }

    private static function cacheRoot(): string
    {
        $home = getenv('LOCALAPPDATA') ?: getenv('HOME');
        if (!is_string($home) || $home === '') {
            throw new PaymosError('cannot locate a user cache directory');
        }
        return $home . DIRECTORY_SEPARATOR . 'paymos' . DIRECTORY_SEPARATOR . 'native';
    }

    private static function download(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5.
        if ($body === false || $status !== 200) {
            throw new PaymosError(
                "failed to download the co-signing core: $url answered $status $err. "
                    . 'Set PAYMOS_NATIVE_LIB to a local libwallet_mpc build to run offline');
        }
        return (string)$body;
    }

    private static function verifySha256(string $payload, string $checksumText, string $archiveName): void
    {
        // Format produced by `sha256sum`: "<hex>  <name>".
        $fields = preg_split('/\s+/', trim($checksumText));
        $expected = strtolower($fields[0] ?? '');
        if ($expected === '') {
            throw new PaymosError('empty checksum file for the co-signing core download');
        }
        $actual = hash('sha256', $payload);
        if ($actual !== $expected) {
            throw new PaymosError(
                "SHA-256 mismatch for $archiveName: expected $expected, got $actual. "
                    . 'The download was refused and nothing was loaded.');
        }
    }

    /** Minimal tar reader for our own single-file archives (ustar, type '0'), over gzip. */
    private static function extractSingleFileTarGz(string $archive, string $destination): void
    {
        $tar = gzdecode($archive);
        if ($tar === false) {
            throw new PaymosError('the downloaded core archive is not gzip');
        }
        $offset = 0;
        $len = strlen($tar);
        while ($offset + 512 <= $len) {
            if (substr($tar, $offset, 512) === str_repeat("\0", 512)) {
                break;
            }
            $size = octdec(rtrim(substr($tar, $offset + 124, 12), "\0 "));
            $type = $tar[$offset + 156];
            if ($type === '0' || $type === "\0") {
                $dir = dirname($destination);
                if (!is_dir($dir)) {
                    mkdir($dir, 0o755, true);
                }
                $tmp = $destination . '.tmp';
                file_put_contents($tmp, substr($tar, $offset + 512, $size));
                if (is_file($destination)) {
                    unlink($destination);
                }
                rename($tmp, $destination);
                return;
            }
            $offset += 512 + intdiv($size + 511, 512) * 512;
        }
        throw new PaymosError('the downloaded core archive carried no file — refusing to install it');
    }
}
