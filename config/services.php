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

    'mailgun' => [
        'domain' => env('MAILGUN_DOMAIN'),
        'secret' => env('MAILGUN_SECRET'),
        'endpoint' => env('MAILGUN_ENDPOINT', 'api.mailgun.net'),
        // Platform inbound domain for company accounting inboxes: accounting@<company-slug>.<domain>.
        // Needs a wildcard MX on the domain. Unset → the company's own Mailgun domain.
        'accounting_inbox_domain' => env('MAILGUN_ACCOUNTING_INBOX_DOMAIN'),
    ],

    'postmark' => [
        'token' => env('POSTMARK_TOKEN'),
    ],

    'ses' => [
        'key' => env('AWS_ACCESS_KEY_ID'),
        'secret' => env('AWS_SECRET_ACCESS_KEY'),
        'region' => env('AWS_DEFAULT_REGION', 'us-east-1'),
    ],

    'github' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],

    'google' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],

    'facebook' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],

    'twitter-oauth-2' => [
        'client_id' => '',
        'client_secret' => '',
        'redirect' => '',
    ],
    'slack' => [
        'notifications' => [
            'bot_user_oauth_token' => env('SLACK_BOT_USER_OAUTH_TOKEN'),
            'channel' => env('SLACK_BOT_USER_DEFAULT_CHANNEL'),
        ],
    ],
    'stripe' => [
        'key' => env('STRIPE_KEY'),
        'secret' => env('STRIPE_SECRET'),
        'webhook' => [
            'secret' => env('STRIPE_WEBHOOK_SECRET'),
            'tolerance' => env('STRIPE_WEBHOOK_TOLERANCE', 300),
        ],
    ],

    // Alanube main-company tokens for `kanvas` account mode (tenants under our account). App setting wins.
    'alanube' => [
        'main_token_test' => env('ALANUBE_MAIN_TOKEN_TEST'),
        'main_token_production' => env('ALANUBE_MAIN_TOKEN_PRODUCTION'),
    ],

    // Official central-bank exchange rates (Banco Central RD buy rate), platform-wide.
    'microsoft' => [
        'client_id' => env('MICROSOFT_CLIENT_ID'),
        'client_secret' => env('MICROSOFT_CLIENT_SECRET'),
        'tenant_id' => env('MICROSOFT_TENANT_ID', 'common'),
    ],

    'allratestoday' => [
        'key' => env('ALLRATESTODAY_API_KEY'),
    ],

    'sightengine' => [
        'api_user' => env('SIGHTENGINE_API_USER'),
        'api_secret' => env('SIGHTENGINE_API_SECRET'),
        'image_moderation' => [
            'api_url' => env('SIGHTENGINE_IMAGE_MODERATION_API_URL'),
        ],
        'text_moderation' => [
            'api_url' => env('SIGHTENGINE_TEXT_MODERATION_API_URL'),
        ],
    ],
];
