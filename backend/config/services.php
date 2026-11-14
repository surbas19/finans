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

    // Yapay zekâ ile ekstre analizi (varsayılan kapalı: anahtar boşsa özellik yok). PDF + temizlenmiş sayfa metni,
    // yalnızca kullanıcının her analizdeki onayıyla; store:false. Model PDF görüntüsünü okuyabilmeli.
    'openai' => [
        'key' => env('OPENAI_API_KEY'),
        'model' => env('OPENAI_MODEL', 'gpt-4.1'),
        'pdf_detail' => env('OPENAI_PDF_DETAIL', 'high'),
        'max_output_tokens' => (int) env('OPENAI_MAX_OUTPUT_TOKENS', 32000),
        // Senkron çağrı (cron) için bekleme; arka plan modunda istekler kısa sürer
        'timeout' => (int) env('OPENAI_TIMEOUT', 300),
        'arka_plan' => (bool) env('OPENAI_ARKA_PLAN', true),
        'url' => env('OPENAI_URL', 'https://api.openai.com/v1/responses'),
    ],

];
