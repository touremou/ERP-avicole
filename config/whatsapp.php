<?php

return [

    /*
    |--------------------------------------------------------------------------
    | WhatsApp Driver
    |--------------------------------------------------------------------------
    |
    | Drivers disponibles : log, callmebot, ultramsg, wati, twilio
    |
    | 'log' = mode développement (messages dans storage/logs)
    | 'callmebot' = gratuit, parfait pour tester
    |
    */
    'driver' => env('WHATSAPP_DRIVER', 'log'),

    /*
    |--------------------------------------------------------------------------
    | API Key (selon le provider)
    |--------------------------------------------------------------------------
    */
    'api_key' => env('WHATSAPP_API_KEY'),

    /*
    |--------------------------------------------------------------------------
    | Instance ID (UltraMsg)
    |--------------------------------------------------------------------------
    */
    'instance_id' => env('WHATSAPP_INSTANCE_ID'),

    /*
    |--------------------------------------------------------------------------
    | WATI
    |--------------------------------------------------------------------------
    */
    'wati_url' => env('WHATSAPP_WATI_URL', 'https://live-server-1.wati.io'),

    /*
    |--------------------------------------------------------------------------
    | Twilio
    |--------------------------------------------------------------------------
    */
    'twilio_sid'   => env('TWILIO_SID'),
    'twilio_token' => env('TWILIO_TOKEN'),
    'twilio_from'  => env('TWILIO_WHATSAPP_FROM', 'whatsapp:+14155238886'),

    /*
    |--------------------------------------------------------------------------
    | Résumé Quotidien
    |--------------------------------------------------------------------------
    */
    'daily_summary_time' => env('WHATSAPP_SUMMARY_TIME', '07:00'),

    /*
    |--------------------------------------------------------------------------
    | Nom de l'exploitation (apparaît dans les messages)
    |--------------------------------------------------------------------------
    */
    'farm_name' => env('WHATSAPP_FARM_NAME', 'AviSmart'),

    /*
    |--------------------------------------------------------------------------
    | Vérification SSL des appels providers
    |--------------------------------------------------------------------------
    |
    | Doit rester activée (true). Le bundle CA (composer/ca-bundle) gère déjà
    | les PHP sans curl.cainfo configuré (cause de « cURL error 60 »).
    | Le réglage Paramètres > WhatsApp > verify_ssl peut le désactiver en
    | dernier recours (déconseillé, non sécurisé).
    |
    */
    'verify_ssl' => env('WHATSAPP_VERIFY_SSL', true),

];
