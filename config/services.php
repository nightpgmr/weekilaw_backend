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
        'dev_code' => env('OTP_DEV_CODE', '12345'),
        // When false: always use Kavenegar SMS (even if APP_ENV is not production). Set to false on server.
        'otp_dev_mode' => filter_var(env('OTP_DEV_MODE', 'true'), FILTER_VALIDATE_BOOLEAN),
    ],

    'google' => [
        'client_id' => env('GOOGLE_CLIENT_ID'),
        'client_secret' => env('GOOGLE_CLIENT_SECRET'),
        'redirect' => env('GOOGLE_REDIRECT_URI', '/api/auth/google/callback'),
    ],

    'sep' => [
        'terminal_id' => env('SEP_TERMINAL_ID'),
        'merchant_id' => env('SEP_MERCHANT_ID'),
        'callback_url' => env('SEP_CALLBACK_URL', 'https://weekilaw.com/api/payment/payment-listener'), // Publicly accessible callback URL
        'base_url' => env('SEP_BASE_URL', 'https://weekilaw.com'), // Base URL for payment gateway redirects
        'verify_url' => env('SEP_VERIFY_URL', 'https://weekilaw.com/api/payment/verify-callback'), // Verify callback URL
        'web_app_url' => env('SEP_WEB_APP_URL', 'https://weekilaw.com'), // Web app URL for redirects
    ],

];
