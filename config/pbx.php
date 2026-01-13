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

    // Provider-specific configuration. Keep keys vendor-agnostic (slug => config).
    'providers' => [
        'pbxware' => [
            // PBX base URL is intentionally centralized in Secrets Manager.
            'timeout' => env('PBXWARE_TIMEOUT', 30),
            'aws_region' => env('PBXWARE_AWS_REGION', env('AWS_DEFAULT_REGION')),
        ],
    ],
];
