<?php

return [
    'reports' => [
        'storage_disk' => env('REPORTS_STORAGE_DISK', 'local'),
        'signed_url_minutes' => env('REPORTS_SIGNED_URL_MINUTES', 60),
        'weekly_template' => env('WEEKLY_REPORT_TEMPLATE', 'reports.weekly.default'),
        // When true, the weekly report generator includes AI-generated insights.
        // Centralized here so runtime code can rely on config() instead of env(),
        // which is required for `php artisan config:cache` to take effect.
        'ai_enabled' => env('REPORTS_AI_ENABLED', false),
    ],

    'pbxware' => [
        'aws_region' => env('PBXWARE_AWS_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-2')),
        'timeout' => env('PBXWARE_TIMEOUT', 30),
        // Master switch for the PBXware ingest scheduler (Kernel.php).
        'ingest_enabled' => env('PBXWARE_INGEST_ENABLED', true),
        // When true, PBXware client returns mock data instead of contacting the
        // upstream PBX. Use only for local development/test.
        'mock_mode' => env('PBXWARE_MOCK_MODE', false),
        // When true, the PBXware client bypasses the in-memory secret cache.
        // Useful when rotating credentials in development.
        'disable_secrets_cache' => env('PBXWARE_DISABLE_SECRETS_CACHE', false),
    ],

    'aws' => [
        // When true, AwsSecretsService logs verbose secret resolution traces.
        // Never enable in production; secret values may appear in logs.
        'secrets_debug' => env('AWS_SECRETS_DEBUG', false),
    ],

    'scheduler' => [
        'token' => env('SCHEDULER_WEBHOOK_TOKEN'),
    ],

];
