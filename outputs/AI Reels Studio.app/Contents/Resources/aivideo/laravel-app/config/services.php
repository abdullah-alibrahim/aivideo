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

    'gemini' => [
        'keys_json' => env('AIVIDEO_GEMINI_KEYS_JSON', '[]'),
        'api_key' => env('GEMINI_API_KEY'),
        'google_api_key' => env('GOOGLE_API_KEY'),
        'models' => ['gemini-2.5-flash', 'gemini-3-flash-preview', 'gemini-3.5-flash'],
    ],

    'elevenlabs' => [
        'api_key' => env('ELEVENLABS_API_KEY'),
        'voice_ids' => [
            'ar' => env('ELEVENLABS_VOICE_ID_AR'),
            'en' => env('ELEVENLABS_VOICE_ID_EN'),
        ],
    ],

];
