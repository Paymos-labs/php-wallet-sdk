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
 *   1. PAYMOS_NATIVE_LIB — a full path to a local binary. The offline escape hatch, and the
 *      operator's own choice: no hash below applies to it.
 *   2. The per-user cache, where a previous run already left a verified binary.
 *   3. The paymos-wallet-core GitHub release — downloaded, verified, then cached.
 *
 * How a download and a cached file are verified depends on PINNED_CORE_SHA256. With an entry for
 * this version and platform, that compiled-in hash is the ONLY one accepted: the release's own
 * .sha256 is not even fetched, the library extracted from the archive must hash to the pin before
 * it is written, and the cached file is re-hashed against it on every load — a mismatch anywhere
 * refuses, and nothing crosses the FFI boundary. Without an entry, the archive is checked against
 * the .sha256 published beside it in the same release, which catches a corrupted download but not
 * a replaced release, and a cached file is trusted as written.
 *
 * Whatever the source, the loaded binary must self-report the version this SDK is pinned to
 * (fail-closed), and only then do its exports serve calls.
 */
final class Core
{
    /** The paymos-wallet-core release this SDK build is pinned to. Bump when the core releases. */
    public const CORE_VERSION = '0.1.0';

    /**
     * Core version → platform ('linux-x64', 'linux-arm64', 'win-x64', 'win-arm64', 'osx-x64',
     * 'osx-arm64') → the lowercase hex SHA-256 of the LIBRARY FILE inside
     * paymos-wallet-native-v<version>-<platform>.tar.gz — libwallet_mpc.so, wallet_mpc.dll or
     * libwallet_mpc.dylib as extracted, not the archive. Empty in this build: until it is filled, a
     * download is checked only against the release's own .sha256. Fill it from a build you trust;
     * the Go, C#, Java and Ruby SDKs carry the same table.
     */
    private const PINNED_CORE_SHA256 = [
        // '0.1.0' => [
        //     'linux-x64' => '<sha256 of libwallet_mpc.so>',
        //     'linux-arm64' => '<sha256 of libwallet_mpc.so>',
        //     'win-x64' => '<sha256 of wallet_mpc.dll>',
        // ],
    ];

    private const RELEASE_BASE = 'https://github.com/paymos-labs/paymos-wallet-core/releases/download';

    /**
     * The archive holds one library of about a megabyte. These bounds stop a replaced release from
     * spending the integrator's memory on a huge download or a decompression bomb.
     */
    private const MAX_ARCHIVE_BYTES = 64 << 20;
    private const MAX_LIBRARY_BYTES = 64 << 20;

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

        return self::resolveCachedOrDownloaded(self::cacheRoot(), $rid, self::CORE_VERSION, self::RELEASE_BASE,
            self::download(...), self::PINNED_CORE_SHA256);
    }

    /** The compiled-in hash for $version on $rid, lowercased, or null. */
    private static function pinnedHash(array $pins, string $version, string $rid): ?string
    {
        $hash = $pins[$version][$rid] ?? null;
        $hash = is_string($hash) ? strtolower(trim($hash)) : '';
        return $hash === '' ? null : $hash;
    }

    /**
     * The cached core for $rid, downloading and installing it first when there is none — verified
     * against $pins when they cover the platform (and then re-hashed on every call), otherwise
     * against the release's own checksum. Parameterised so the tests can drive it without a
     * network.
     *
     * @param callable(string $url, int $limit): string $download
     */
    private static function resolveCachedOrDownloaded(string $cacheRoot, string $rid, string $version,
                                                      string $releaseBase, callable $download, array $pins): string
    {
        $cached = $cacheRoot . DIRECTORY_SEPARATOR . 'v' . $version . DIRECTORY_SEPARATOR . $rid
            . DIRECTORY_SEPARATOR . self::libName($rid);
        $pinned = self::pinnedHash($pins, $version, $rid);

        if (!is_file($cached)) {
            $archiveName = 'paymos-wallet-native-v' . $version . '-' . $rid . '.tar.gz';
            $archive = $download($releaseBase . '/v' . $version . '/' . $archiveName, self::MAX_ARCHIVE_BYTES);
            if ($pinned === null) {
                // No compiled-in hash: the release's own checksum, which proves the download intact
                // and nothing more.
                $checksum = $download($releaseBase . '/v' . $version . '/' . $archiveName . '.sha256', 4096);
                self::verifySha256($archive, $checksum, $archiveName);
            }
            $library = self::readSingleFileTarGz($archive, self::MAX_LIBRARY_BYTES);
            if ($pinned !== null) {
                self::checkPinned($library, $pinned, $archiveName); // refused before a byte is written
            }
            self::install($library, $cached);
        }

        // A pinned core is re-hashed on every load, cached or just installed: the cache is only a
        // file on disk, and the pin is the only thing that says what may run.
        if ($pinned !== null) {
            $onDisk = @file_get_contents($cached);
            if ($onDisk === false) {
                throw new PaymosError("cannot read the cached co-signing core $cached");
            }
            try {
                self::checkPinned($onDisk, $pinned, $cached);
            } catch (PaymosError $e) {
                throw new PaymosError($e->getMessage() . ". Nothing was loaded; delete $cached to fetch the core"
                    . ' again — the download is held to the same hash.');
            }
        }
        return $cached;
    }

    private static function checkPinned(string $payload, string $want, string $what): void
    {
        $got = hash('sha256', $payload);
        if ($got !== $want) {
            throw new PaymosError("SHA-256 mismatch for $what: this SDK pins $want, got $got");
        }
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

    private static function download(string $url, int $limit): string
    {
        $ch = curl_init($url);
        $body = '';
        $over = false;
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => 120,
            // Collected here rather than by RETURNTRANSFER so a body past $limit stops the transfer
            // instead of growing in memory: answering short of the chunk length aborts it.
            CURLOPT_WRITEFUNCTION => function ($ch, string $chunk) use (&$body, &$over, $limit): int {
                if (strlen($body) + strlen($chunk) > $limit) {
                    $over = true;
                    return 0;
                }
                $body .= $chunk;
                return strlen($chunk);
            },
        ]);
        $ok = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $err = curl_error($ch);
        // No curl_close(): a no-op since PHP 8.0, deprecated in 8.5.
        if ($over) {
            throw new PaymosError("failed to download the co-signing core: $url is over $limit bytes — refusing it");
        }
        if ($ok === false || $status !== 200) {
            throw new PaymosError(
                "failed to download the co-signing core: $url answered $status $err. "
                    . 'Set PAYMOS_NATIVE_LIB to a local libwallet_mpc build to run offline');
        }
        return $body;
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

    /**
     * The first regular file of one of our own single-file archives (ustar, type '0', over gzip),
     * refused once it — or the tar around it — decompresses past $limit.
     */
    private static function readSingleFileTarGz(string $archive, int $limit): string
    {
        // Inflated a chunk at a time, so a bomb stops at the bound instead of after it. A tar header
        // per file and two zero blocks at the end: 64 KiB is room to spare.
        $max = $limit + 65536;
        $inflate = inflate_init(ZLIB_ENCODING_GZIP);
        $tar = '';
        foreach (str_split($archive, 8192) as $chunk) {
            $out = inflate_add($inflate, $chunk, ZLIB_SYNC_FLUSH);
            if ($out === false) {
                throw new PaymosError('the downloaded core archive is not gzip');
            }
            $tar .= $out;
            if (strlen($tar) > $max) {
                throw new PaymosError("the downloaded core archive holds a file over $limit bytes — refusing to install it");
            }
        }
        $offset = 0;
        $len = strlen($tar);
        while ($offset + 512 <= $len) {
            if (substr($tar, $offset, 512) === str_repeat("\0", 512)) {
                break;
            }
            $size = (int)octdec(rtrim(substr($tar, $offset + 124, 12), "\0 "));
            $type = $tar[$offset + 156];
            if ($size > $limit || $offset + 512 + $size > $len) {
                throw new PaymosError("the downloaded core archive holds a file over $limit bytes, or a truncated one"
                    . ' — refusing to install it');
            }
            if ($type === '0' || $type === "\0") {
                return substr($tar, $offset + 512, $size);
            }
            $offset += 512 + intdiv($size + 511, 512) * 512;
        }
        throw new PaymosError('the downloaded core archive carried no file — refusing to install it');
    }

    /** Writes the library through a temporary file, so a reader never meets half of it. */
    private static function install(string $library, string $destination): void
    {
        $dir = dirname($destination);
        if (!is_dir($dir)) {
            mkdir($dir, 0o755, true);
        }
        $tmp = $destination . '.tmp';
        if (file_put_contents($tmp, $library) === false) {
            throw new PaymosError("cannot write the co-signing core to $tmp");
        }
        if (is_file($destination)) {
            unlink($destination);
        }
        rename($tmp, $destination);
    }
}
