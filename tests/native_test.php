<?php

// Where the co-signing core comes from, and what it must hash to. The release is an in-memory
// stand-in; nothing here downloads anything or loads a library.

declare(strict_types=1);

use Paymos\Wallet\Native\Core;
use Paymos\Wallet\PaymosError;

const NATIVE_RID = 'linux-x64'; // the resolver is told the platform; any one will do
const NATIVE_BASE = 'https://release.test';

function nativeArchiveName(): string
{
    return 'paymos-wallet-native-v' . Core::CORE_VERSION . '-' . NATIVE_RID . '.tar.gz';
}

/** A release archive in the shape native-artifacts.sh packs: one ustar file, no directory. */
function nativeArchive(string $library): string
{
    $header = str_pad('libwallet_mpc.so', 100, "\0") . str_repeat("\0", 24)
        . sprintf('%011o', strlen($library)) . "\0" . str_repeat("\0", 20) . '0';
    $header = str_pad($header, 512, "\0");
    $tar = $header . $library . str_repeat("\0", (512 - strlen($library) % 512) % 512) . str_repeat("\0", 1024);
    return gzencode($tar);
}

/** A stand-in release: url → body, and the urls asked for. */
final class NativeRelease
{
    /** @var array<string, string> */
    public array $files = [];
    /** @var list<string> */
    public array $asked = [];

    public static function serving(string $library, ?string $checksum = null): self
    {
        $r = new self();
        $archive = nativeArchive($library);
        $prefix = NATIVE_BASE . '/v' . Core::CORE_VERSION . '/' . nativeArchiveName();
        $r->files[$prefix] = $archive;
        $r->files[$prefix . '.sha256'] = ($checksum ?? hash('sha256', $archive)) . '  ' . nativeArchiveName() . "\n";
        return $r;
    }

    public function download(string $url, int $limit): string
    {
        $this->asked[] = $url;
        return $this->files[$url] ?? throw new PaymosError("404 $url");
    }
}

function nativeCache(): string
{
    $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'paymos-core-' . bin2hex(random_bytes(6));
    mkdir($dir);
    return $dir;
}

function nativeResolve(string $cache, NativeRelease $release, ?string $pin): string
{
    $pins = $pin === null ? [] : [Core::CORE_VERSION => [NATIVE_RID => $pin]];
    $m = new \ReflectionMethod(Core::class, 'resolveCachedOrDownloaded');
    return $m->invoke(null, $cache, NATIVE_RID, Core::CORE_VERSION, NATIVE_BASE, $release->download(...), $pins);
}

test('native: every shipped pin names a known platform and is a sha256', function () {
    // Empty until the owner fills it; a pasted entry with a typo fails here, not at a user's first payout.
    $platforms = ['linux-x64', 'linux-arm64', 'win-x64', 'win-arm64', 'osx-x64', 'osx-arm64'];
    foreach ((new \ReflectionClassConstant(Core::class, 'PINNED_CORE_SHA256'))->getValue() as $version => $byRid) {
        foreach ($byRid as $rid => $hash) {
            ok(in_array($rid, $platforms, true), "$version: unknown platform $rid");
            ok(is_string($hash) && preg_match('/^[0-9a-fA-F]{64}$/D', $hash) === 1, "$version/$rid: not a sha256");
        }
    }
});

test('native: a pinned hash is the only one accepted, and the cache is re-hashed on every load', function () {
    $cache = nativeCache();
    $library = 'the trusted co-signing core';
    $release = NativeRelease::serving($library, str_repeat('0', 64)); // wrong, and never read
    $pin = strtoupper(hash('sha256', $library));

    $path = nativeResolve($cache, $release, $pin);
    eq($library, file_get_contents($path));
    eq([], array_values(array_filter($release->asked, fn ($u) => str_ends_with($u, '.sha256'))), 'no .sha256 fetched');

    $asked = count($release->asked);
    eq($path, nativeResolve($cache, $release, $pin));
    eq($asked, count($release->asked), 'a cached core loads without a download');

    file_put_contents($path, 'a substituted library');
    $e = throws(fn () => nativeResolve($cache, $release, $pin));
    ok(str_contains($e->getMessage(), 'SHA-256 mismatch') && str_contains($e->getMessage(), $path), $e->getMessage());
    ok(is_file($path), 'the refused file is left where it is');
});

test('native: a download that misses the pin is refused before anything is written', function () {
    $cache = nativeCache();
    $release = NativeRelease::serving('a replaced release'); // its own .sha256 is right
    $e = throws(fn () => nativeResolve($cache, $release, hash('sha256', 'the trusted co-signing core')));
    ok(str_contains($e->getMessage(), 'SHA-256 mismatch'), $e->getMessage());
    eq([], glob($cache . '/*/*/*') ?: [], 'nothing written');
});

test('native: without a pin the release checksum decides, as it always did', function () {
    $cache = nativeCache();
    $library = 'a core this SDK build carries no hash for';
    $release = NativeRelease::serving($library);
    $path = nativeResolve($cache, $release, null);
    ok(count(array_filter($release->asked, fn ($u) => str_ends_with($u, '.sha256'))) === 1, 'the .sha256 is consulted');

    file_put_contents($path, 'changed on disk');
    eq($path, nativeResolve($cache, $release, null), 'an unpinned cache is trusted as written');

    unlink($path);
    $e = throws(fn () => nativeResolve($cache, NativeRelease::serving($library, str_repeat('0', 64)), null));
    ok(str_contains($e->getMessage(), 'SHA-256 mismatch'), $e->getMessage());
});

test('native: a release cannot make the SDK inflate a decompression bomb', function () {
    $read = new \ReflectionMethod(Core::class, 'readSingleFileTarGz');
    $e = throws(fn () => $read->invoke(null, nativeArchive(str_repeat("\0", 200_000)), 1024));
    ok(str_contains($e->getMessage(), 'over 1024 bytes'), $e->getMessage());
    $e = throws(fn () => $read->invoke(null, nativeArchive(str_repeat("\0", 4096)), 1024));
    ok(str_contains($e->getMessage(), 'over 1024 bytes'), $e->getMessage());
    eq(4096, strlen($read->invoke(null, nativeArchive(str_repeat("\0", 4096)), 4096)));
});
