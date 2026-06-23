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

    /*
    |--------------------------------------------------------------------------
    | Météo (Open-Meteo)
    |--------------------------------------------------------------------------
    | Service météo public, gratuit et sans clé. Alimente automatiquement les
    | relevés (weather_readings) et pré-remplit la météo du pointage volaille.
    | Le géocodage convertit la ville/région de la ferme en coordonnées GPS.
    */
    'weather' => [
        'enabled'      => env('WEATHER_ENABLED', true),
        'forecast_url' => env('WEATHER_FORECAST_URL', 'https://api.open-meteo.com/v1/forecast'),
        'geocode_url'  => env('WEATHER_GEOCODE_URL', 'https://geocoding-api.open-meteo.com/v1/search'),
        'timeout'      => (int) env('WEATHER_TIMEOUT', 12),
        'country'      => env('WEATHER_COUNTRY', 'GN'), // biais géocodage (Guinée)
    ],

];
