<?php

return [
    /*
    |--------------------------------------------------------------------------
    | PBX Integration Configuration
    |--------------------------------------------------------------------------
    |
    | Central configuration for PBX providers and runtime mode. Keep this
    | vendor-agnostic: add providers under the `providers` key and reference
    | them by slug from code. `mode` can be `real` or `mock` to enable local
    | testing without contacting external PBX systems.
    |
    */

    'mode' => env('PBX_MODE', 'real'), // 'real' or 'mock'

    // Default provider to use when code doesn't specify one
    'default_provider' => env('PBX_DEFAULT_PROVIDER', 'pbxware'),

    /*
    |--------------------------------------------------------------------------
    | Debug payload logging
    |--------------------------------------------------------------------------
    |
    | When true (and the app is NOT running in production) the PBX client and
    | adapter will log full PBX response bodies. Default is false. Production
    | logs always exclude raw payloads regardless of this flag, since PBX
    | responses can contain call/transcription content.
    |
    */
    'debug_payloads' => env('PBX_DEBUG_PAYLOADS', false),

    // Provider-specific configuration. Keep keys vendor-agnostic (slug => config).
    'providers' => [
        'pbxware' => [
            // PBX base URL is intentionally centralized in Secrets Manager.
            'timeout' => env('PBXWARE_TIMEOUT', 30),
            'aws_region' => env('PBXWARE_AWS_REGION', env('AWS_DEFAULT_REGION')),

            // PBXware authoritative flow:
            // - Fetch CDR records via pbxware.cdr.download (start/end/status=8/server)
            // - Fetch transcription per call via pbxware.transcription.get (server/uniqueid)
        ],
    ],
];
