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
    /*
     * SatGo, the SAT gateway. The API key is exchanged for a short-lived JWT;
     * it is not sent on document requests. `version` is the path segment of the
     * consultar endpoints, kept in config because SatGo versions per route.
     */
    'satgo' => [
        'base_url' => env('SATGO_BASE_URL', 'https://api.sat-go.com'),
        'api_key' => env('SATGO_API_KEY'),
        'version' => env('SATGO_API_VERSION', 'v2'),
        // The SAT is slow and SatGo retries on our behalf, so this is generous.
        'timeout' => (int) env('SATGO_TIMEOUT', 120),
    ],

    'whatsapp' => [
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'access_token' => env('WHATSAPP_ACCESS_TOKEN'),
        'api_version' => env('WHATSAPP_API_VERSION', 'v21.0'),
        'verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        // How long the signed URL handed to Meta for a document stays valid.
        'document_link_ttl' => (int) env('WHATSAPP_DOCUMENT_LINK_TTL', 10),
    ],
];
