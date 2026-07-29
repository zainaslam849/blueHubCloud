<?php

namespace App\Services\Pbx;

use App\Models\PbxProvider;
use App\Services\PbxwareClient;

/**
 * Resolve a PBX client implementation based on configuration.
 * Returns MockPbxwareClient when `config('pbx.mode') === 'mock'`, otherwise
 * returns the real `App\Services\PbxwareClient` bound to the given server
 * (null = the legacy/default server).
 */
class PbxClientResolver
{
    public static function resolve(?PbxProvider $server = null)
    {
        // Prefer explicit runtime config override when present (e.g. CLI --mock),
        // otherwise fall back to PBXWARE_MOCK_MODE env var.
        $mock = (string) config('pbx.mode') === 'mock'
            || filter_var(config('services.pbxware.mock_mode', false), FILTER_VALIDATE_BOOLEAN);
        if ($mock) {
            return new MockPbxwareClient();
        }

        return new PbxwareClient($server);
    }
}
