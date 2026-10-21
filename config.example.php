<?php
return [
    'db' => [
        'host' => 'localhost',
        'name' => 'shorturl',
        'user' => 'shorturl',
        'pass' => 'CHANGE_ME',
        'charset' => 'utf8mb4',
    ],
    'app' => [
        'name' => 'ShortURL Manager',
        'base_url' => 'https://short.example.com',
        'timezone' => 'Europe/Berlin',
        'session_name' => 'shorturl_admin',
        // Generate a long random value for production.
        'api_secret' => 'CHANGE_ME_TO_A_LONG_RANDOM_SECRET',
    ],
];
