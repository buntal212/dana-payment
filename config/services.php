<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Third Party Services
    |--------------------------------------------------------------------------
    |
    | This file is for storing the credentials for third party services such
    | as Resend, Postmark, AWS, and more. This file provides the de facto
    | location for this type of information, allowing packages to have
    | a conventional file to locate the various service credentials.
    |
    */

    'postmark' => [
        'key' => env('POSTMARK_API_KEY'),
    ],

    'resend' => [
        'key' => env('RESEND_API_KEY'),
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

    'dana' => [
        'base_url' => env('DANA_SANDBOX_BASE_URL', 'https://api.sandbox.dana.id'),
        'timeout' => env('DANA_TIMEOUT', 8),
        'connect_timeout' => env('DANA_CONNECT_TIMEOUT', 3),
        'webhook_relative_path' => env('DANA_WEBHOOK_RELATIVE_PATH', '/api/v1/webhooks/dana'),
        'diagnostics' => env('DANA_DIAGNOSTICS', false),
    ],

    'udev_internal' => [
        'enabled' => env('UDEV_INTERNAL_ENDPOINTS_ENABLED', false),
        'service_id' => env('UDEV_INTERNAL_SERVICE_ID', 'udev-billing'),
        'token' => env('UDEV_INTERNAL_SERVICE_TOKEN'),
        'abilities' => [
            'merchants:read',
            'merchants:write',
            'merchants:readiness',
            'merchants:credentials:write',
            'merchants:api-keys:read',
            'merchants:api-keys:write',
            'merchants:api-keys:issue',
        ],
        'api_key_issuance_pending_ttl_minutes' => (int) env('UDEV_INTERNAL_API_KEY_ISSUANCE_PENDING_TTL_MINUTES', 10),
    ],

];
