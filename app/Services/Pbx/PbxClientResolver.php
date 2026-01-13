<?php

namespace App\Services\Pbx;

use App\Services\PbxwareClient;

/**
 * Resolve a PBX client implementation based on configuration.
 * Returns MockPbxwareClient when `config('pbx.mode') === 'mock'`, otherwise
 * returns the real `App\Services\PbxwareClient`.
 */
class PbxClientResolver
{
    public static function resolve()
    {
        // Prefer explicit runtime config override when present (e.g. CLI --mock),
        // otherwise fall back to PBXWARE_MOCK_MODE env var.
        $mock = (string) config('pbx.mode') === 'mock'
            || filter_var(env('PBXWARE_MOCK_MODE', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            return new MockPbxwareClient();
        }

        return new PbxwareClient();
    }
}
