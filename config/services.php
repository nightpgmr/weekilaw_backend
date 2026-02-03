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

    'kavenegar' => [
        'api_key' => env('KAVENEGAR_API_KEY'),
        'template' => env('KAVENEGAR_TEMPLATE', 'weekilaw'),
        'dev_code' => env('OTP_DEV_CODE', '1234'),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/api/auth/google/callback'),
    ],

    'sep' => [
        'terminal_id' => env('SEP_TERMINAL_ID'),
        'merchant_id' => env('SEP_MERCHANT_ID'),
        'sandbox' => env('SEP_SANDBOX', true),
        'callback_url' => env('SEP_CALLBACK_URL'),
        // If callback_path is not set and callback_url is set, defaults to /api/wallet/callback (direct backend)
        // If callback_path is set, uses that path (for proxy scenarios)
        'callback_path' => env('SEP_CALLBACK_PATH'), // Optional: defaults to /api/wallet/callback if not set
        'callback_domain_only' => env('SEP_CALLBACK_DOMAIN_ONLY', false), // Set to true if SEP only whitelists domain (not full path)
    ],

];
