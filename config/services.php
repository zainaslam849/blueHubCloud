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
        // PBXware credential ENV-fallback values. Authoritative source remains
        // AWS Secrets Manager; these are only consulted when the secret is
        // missing/incomplete. Centralized here so runtime code uses config()
        // (a hard requirement for `php artisan config:cache`).
        'api_key' => env('PBXWARE_API_KEY'),
        'base_url' => env('PBXWARE_BASE_URL'),
        'server_id' => env('PBXWARE_SERVER_ID'),
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
        // Default AWS region used when no PBXWARE_AWS_REGION override exists.
        'region' => env('AWS_DEFAULT_REGION'),
        // Static credentials (optional). When unset, the AWS SDK falls back to
        // its default provider chain (instance profile / shared config).
        'access_key_id' => env('AWS_ACCESS_KEY_ID'),
        'secret_access_key' => env('AWS_SECRET_ACCESS_KEY'),
        'session_token' => env('AWS_SESSION_TOKEN'),
        // Optional S3-compatible overrides.
        'url' => env('AWS_URL'),
        'endpoint' => env('AWS_ENDPOINT'),
        'use_path_style_endpoint' => env('AWS_USE_PATH_STYLE_ENDPOINT', false),
    ],

    'reporting' => [
        // Industry-average agent cost used by InsightDrivenReportService for
        // automation savings projections. Configure via AGENT_COST_PER_HOUR.
        'agent_cost_per_hour' => env('AGENT_COST_PER_HOUR', 35),
    ],

    'scheduler' => [
        'token' => env('SCHEDULER_WEBHOOK_TOKEN'),
    ],

];
