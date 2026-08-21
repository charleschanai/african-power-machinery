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

    'whatsapp' => [
        'driver' => env('WHATSAPP_DRIVER', 'meta'),
        'token' => env('WHATSAPP_API_TOKEN'),
        'app_secret' => env('WHATSAPP_APP_SECRET'),
        'phone_number_id' => env('WHATSAPP_PHONE_NUMBER_ID'),
        'business_account_id' => env('WHATSAPP_BUSINESS_ACCOUNT_ID'),
        'webhook_verify_token' => env('WHATSAPP_WEBHOOK_VERIFY_TOKEN'),
        'graph_api_version' => env('WHATSAPP_GRAPH_API_VERSION', 'v21.0'),
        'queue_async' => env('WHATSAPP_QUEUE_ASYNC', false),

        // Africa's Talking WhatsApp channel
        'at_api_key' => env('AT_WHATSAPP_API_KEY'),
        'at_username' => env('AT_WHATSAPP_USERNAME'),
        'at_wa_number' => env('AT_WHATSAPP_WA_NUMBER'),
        'at_base_url' => env('AT_WHATSAPP_BASE_URL', 'https://chat.africastalking.com/whatsapp/message/send'),
    ],

    'sms' => [
        'driver' => env('SMS_DRIVER', 'nextsms'),
        'nextsms_username' => env('NEXTSMS_USERNAME'),
        'nextsms_password' => env('NEXTSMS_PASSWORD'),
        'nextsms_sender_id' => env('NEXTSMS_SENDER_ID', 'NEXTSMS'),
    ],

    'ai' => [
        'key' => env('AI_API_KEY'),
        'base_url' => env('AI_BASE_URL', 'https://api.openai.com/v1'),
        'model' => env('AI_MODEL', 'gpt-4o-mini'),
        'timeout' => (int) env('AI_TIMEOUT', 30),
        'temperature' => (float) env('AI_TEMPERATURE', 0.3),
    ],

];
