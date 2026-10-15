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

    'gemini' => [
        'api_key'    => env('GEMINI_API_KEY'),
        'model'      => env('GOOGLE_AI_MODEL', 'gemini-2.0-flash'),
        // Served instead when the primary model is overloaded (503) or out of
        // quota (429) before any text was streamed. Null disables fallback.
        'fallback_model' => env('GOOGLE_AI_FALLBACK_MODEL'),
        'timeout_ms' => (int) env('GOOGLE_AI_TIMEOUT_MS', 25000),
        // Gemini 3 only: minimal | low | medium | high. Lower = faster first token.
        'thinking_level' => env('GOOGLE_AI_THINKING_LEVEL', 'minimal'),
        // Most recent messages forwarded to the model per request.
        'max_history'    => (int) env('GOOGLE_AI_MAX_HISTORY', 20),
    ],

    'cloudinary' => [
        'cloud_name' => env('CLOUDINARY_CLOUD_NAME'),
        'api_key'    => env('CLOUDINARY_API_KEY'),
        'api_secret' => env('CLOUDINARY_API_SECRET'),
        'folder'     => env('CLOUDINARY_FOLDER'),
    ],

];
