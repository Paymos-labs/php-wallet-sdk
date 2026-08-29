<?php

// Proves the PHP SDK drives the same co-signing core the Python SDK and the server drive: a
// full 2-of-2 round — two parties, distributed key generation, commit, sign, aggregate — and
// the resulting ed25519 signature verifies. If this passes, the halves agree on what a
// signature is.
//
// The server-side operations are driven directly here rather than through the SDK, because the
// SDK deliberately cannot perform them: it holds one half and no more.

declare(strict_types=1);

use Paymos\Wallet\Native\Core;
use Paymos\Wallet\PaymosError;

function packageFor(array $packages, int $id): array
{
    foreach ($packages as $pair) {
        if ($pair[0] === $id) {
            return $pair[1];
        }
    }
    throw new \Exception("no round2 package for $id");
}

test('co-signs a real 2-of-2 signature with the server half', function () {
    $a1 = Core::callOp(['op' => 'dkg_part1', 'id' => 1, 'max' => 2, 'min' => 2]);
    $b1 = Core::callOp(['op' => 'dkg_part1', 'id' => 2, 'max' => 2, 'min' => 2]);
    $a2 = Core::callOp(['op' => 'dkg_part2', 'secret' => $a1['secret'],
        'round1_packages' => [[2, $b1['package']]]]);
    $b2 = Core::callOp(['op' => 'dkg_part2', 'secret' => $b1['secret'],
        'round1_packages' => [[1, $a1['package']]]]);
    $a3 = Core::callOp(['op' => 'dkg_part3', 'secret' => $a2['secret'],
        'round1_packages' => [[2, $b1['package']]],
        'round2_packages' => [[2, packageFor($b2['packages'], 1)]]]);
    $b3 = Core::callOp(['op' => 'dkg_part3', 'secret' => $b2['secret'],
        'round1_packages' => [[1, $a1['package']]],
        'round2_packages' => [[1, packageFor($a2['packages'], 2)]]]);
    eq($a3['group_pubkey_hex'], $b3['group_pubkey_hex'], 'both halves derive one group key');

    // The client half goes through the SDK; the server half is driven directly, because the SDK
    // has no way to perform it and that is the point.
    [$clientCommitments, $clientNonces] = Core::commit($a3['key_package']);
    $server = Core::callOp(['op' => 'commit', 'key_package' => $b3['key_package']]);

    $pkg = Core::callOp(['op' => 'build_signing_package',
        'commitments' => [[1, $clientCommitments], [2, $server['commitments']]],
        'message_hex' => 'deadbeef']);

    $clientShare = Core::sign($pkg['signing_package'], $clientNonces, $a3['key_package']);
    $serverShare = Core::callOp(['op' => 'sign', 'signing_package' => $pkg['signing_package'],
        'nonces' => $server['nonces'], 'key_package' => $b3['key_package']]);

    $agg = Core::callOp(['op' => 'aggregate', 'signing_package' => $pkg['signing_package'],
        'signature_shares' => [[1, $clientShare], [2, $serverShare['signature_share']]],
        'public_key_package' => $a3['public_key_package']]);
    eq(true, $agg['verified'], 'aggregate verifies');
});

test('reports a failed op instead of returning nonsense', function () {
    throws(fn () => Core::sign([], [], []), PaymosError::class);
});

test('reports the pinned core version', function () {
    eq(Core::CORE_VERSION, Core::version());
});
