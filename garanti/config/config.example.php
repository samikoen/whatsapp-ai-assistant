<?php
// Gercek degerleri config.php'ye kopyalayin (config.php gitignore'da).
return [
    'oauth' => [
        'token_url'     => 'https://apis.garantibbva.com.tr/auth/oauth/v2/token',
        'client_id'     => 'BURAYA_CLIENT_ID',
        'client_secret' => 'BURAYA_CLIENT_SECRET',
        'redirect_uri'  => 'https://partner.trek-turkey.com/garanti/callback.php',
        'single_use'    => true, // Garanti dokumani: access token tek kullanimlik — her istekte taze token
    ],
    'api' => [
        'base_url'         => 'https://apis.garantibbva.com.tr',
        'consent_id'       => 'BURAYA_CONSENT_ID',
        'default_currency' => 'TL', // banka 'TL' kullaniyor; currencyCode null gelirse fallback
    ],
    'db' => [
        'dsn'  => 'mysql:host=localhost;dbname=garanti;charset=utf8mb4',
        'user' => 'db_user',
        'pass' => 'db_pass',
    ],
    'dashboard' => [
        // Sifre hash'i: php -r "echo password_hash('sifreniz', PASSWORD_DEFAULT);"
        'username'      => 'admin',
        'password_hash' => 'BURAYA_HASH',
    ],
    'sync' => [
        'lookback_days' => 7,
    ],
];
