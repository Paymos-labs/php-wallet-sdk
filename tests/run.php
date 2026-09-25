<?php

declare(strict_types=1);

// A zero-dependency test runner: the SDK itself is dependency-free, and its tests are too.
// Usage: php tests/run.php

// Tests load every src file eagerly — the shipped autoloading is composer's classmap.
foreach (array_merge(glob(__DIR__ . '/../src/*.php'), glob(__DIR__ . '/../src/*/*.php')) as $srcFile) {
    require $srcFile;
}

// The native core for the mpc test comes from PAYMOS_NATIVE_LIB (set by the release preflight)
// or, in a source checkout, from the core's own release build under mpc/wallet-mpc/target.
if (!getenv('PAYMOS_NATIVE_LIB')) {
    $root = dirname(__DIR__, 3);
    $lib = $root . DIRECTORY_SEPARATOR . 'mpc' . DIRECTORY_SEPARATOR . 'wallet-mpc'
        . DIRECTORY_SEPARATOR . 'target' . DIRECTORY_SEPARATOR . 'release' . DIRECTORY_SEPARATOR
        . match (PHP_OS_FAMILY) {
            'Windows' => 'wallet_mpc.dll',
            'Darwin' => 'libwallet_mpc.dylib',
            default => 'libwallet_mpc.so',
        };
    if (is_file($lib)) {
        putenv("PAYMOS_NATIVE_LIB=$lib");
    }
}

// A notice or deprecation is a failure here: "undefined array key" is how a wrong wire shape
// hides, and a deprecation printed on every call ends up in a merchant's response body.
error_reporting(E_ALL);
set_error_handler(function (int $no, string $msg, string $file, int $line): bool {
    throw new \ErrorException($msg, 0, $no, $file, $line);
});

$GLOBALS['tests'] = 0;
$GLOBALS['failures'] = 0;

function test(string $name, callable $fn): void
{
    $GLOBALS['tests']++;
    try {
        $fn();
        echo ".";
    } catch (\Throwable $e) {
        $GLOBALS['failures']++;
        echo "F";
        fwrite(STDERR, "\nFAIL: $name\n  " . get_class($e) . ': ' . $e->getMessage() . "\n  " .
            $e->getFile() . ':' . $e->getLine() . "\n");
    }
}

function ok(bool $cond, string $what = 'assertion'): void
{
    if (!$cond) {
        throw new \Exception("failed: $what");
    }
}

function eq(mixed $expected, mixed $actual, string $what = ''): void
{
    if ($expected !== $actual) {
        throw new \Exception("failed: $what — expected " . var_export($expected, true) .
            ', got ' . var_export($actual, true));
    }
}

function throws(callable $fn, string $class = \Paymos\Wallet\PaymosError::class): \Throwable
{
    try {
        $fn();
    } catch (\Throwable $e) {
        if ($e instanceof $class) {
            return $e;
        }
        throw new \Exception("failed: expected $class, got " . get_class($e) . ': ' . $e->getMessage());
    }
    throw new \Exception("failed: expected $class, nothing thrown");
}

foreach (glob(__DIR__ . '/*_test.php') as $file) {
    require $file;
}

echo "\n\n{$GLOBALS['tests']} tests, {$GLOBALS['failures']} failures\n";
exit($GLOBALS['failures'] > 0 ? 1 : 0);
