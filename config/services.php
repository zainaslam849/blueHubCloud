<?php

return [
    'reports' => [
        'storage_disk' => env('REPORTS_STORAGE_DISK', 'local'),
        'signed_url_minutes' => env('REPORTS_SIGNED_URL_MINUTES', 60),
        'weekly_template' => env('WEEKLY_REPORT_TEMPLATE', 'reports.weekly.default'),
    ],

    'pbxware' => [
        'aws_region' => env('PBXWARE_AWS_REGION', env('AWS_DEFAULT_REGION', 'ap-southeast-2')),
        'timeout' => env('PBXWARE_TIMEOUT', 30),
    ],

];
