<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Mailgun, Postmark, AWS and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'openai' => [
        'api_key' => env('OPENAI_API_KEY'),
        'whisper' => [
            'model' => env('OPENAI_WHISPER_MODEL', 'whisper-1'),
            'timeout' => env('OPENAI_WHISPER_TIMEOUT', 120),
            'connect_timeout' => env('OPENAI_WHISPER_CONNECT_TIMEOUT', 10),
            'max_bytes' => env('OPENAI_WHISPER_MAX_BYTES', 25 * 1024 * 1024),
        ],
    ],

    'recordings' => [
        'delete_audio_after_transcription' => env('DELETE_AUDIO_AFTER_TRANSCRIPTION', false),
    ],

    'transcription' => [
        'currency' => env('TRANSCRIPTION_CURRENCY', 'USD'),
        'pricing_per_minute' => [
            // Configure per provider in env/secrets; defaults to 0 for safety.
            'openai_whisper' => env('TRANSCRIPTION_COST_PER_MINUTE_OPENAI_WHISPER', 0),
            'default' => env('TRANSCRIPTION_COST_PER_MINUTE_DEFAULT', 0),
        ],
    ],

    'reports' => [
        'storage_disk' => env('REPORTS_STORAGE_DISK', 's3'),
        'signed_url_minutes' => env('REPORTS_SIGNED_URL_MINUTES', 60),
        'weekly_template' => env('WEEKLY_REPORT_TEMPLATE', 'reports.weekly.default'),
    ],

];
