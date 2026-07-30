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

    'resend' => [
        'key' => env('RESEND_KEY'),
    ],

    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],

    'recall' => [
        'region' => env('RECALL_REGION', 'us-west-2'),
        'base_url' => env('RECALL_BASE_URL', 'https://us-west-2.recall.ai'),
        'webhook_url' => env('RECALL_WEBHOOK_URL'),
        'tunnel_host' => env('RECALL_TUNNEL_HOST'),
        'ingress_only' => (bool) env('RECALL_INGRESS_ONLY', false),
        'api_key' => env('RECALL_API_KEY'),
        'webhook_secret' => env('RECALL_WEBHOOK_SECRET'),
        'connect_timeout' => (int) env('RECALL_CONNECT_TIMEOUT', 5),
        'timeout' => (int) env('RECALL_TIMEOUT', 15),
        'webhook_tolerance_seconds' => (int) env('RECALL_WEBHOOK_TOLERANCE_SECONDS', 300),
        'webhook_max_bytes' => (int) env('RECALL_WEBHOOK_MAX_BYTES', 1048576),
        'partial_transcripts' => (bool) env('RECALL_PARTIAL_TRANSCRIPTS', false),
        'analysis_lease_seconds' => (int) env('RECALL_ANALYSIS_LEASE_SECONDS', 30),
    ],

];
