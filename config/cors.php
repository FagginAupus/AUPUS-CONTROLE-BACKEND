<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Cross-Origin Resource Sharing (CORS) Configuration
    |--------------------------------------------------------------------------
    |
    | Here you may configure your settings for cross-origin resource sharing
    | or "CORS". This determines what cross-origin operations may execute
    | in web browsers. You are free to adjust these settings as needed.
    |
    | To learn more: https://developer.mozilla.org/en-US/docs/Web/HTTP/CORS
    |
    */

    'paths' => [
        'api/*', 
        'sanctum/csrf-cookie',
        'storage/templates/*', 
        'documentos/*',
        'webhook/*',
        'auth/*',
        'health',
        'health-check'
    ],

    'allowed_methods' => ['*'],

    'allowed_origins' => [
        'http://localhost:3000',
        'http://127.0.0.1:3000',
        'https://localhost:3000',
        'http://localhost:3001',
        'http://127.0.0.1:3001',
         env('APP_FRONTEND_URL', 'http://localhost:3000'),
    ],

    'allowed_origins_patterns' => [
        '*localhost*',
        '*127.0.0.1*'
    ],

    'allowed_headers' => [
        'Accept',
        'Content-Type',
        'Authorization',
        'Content-Type',
        'X-Requested-With',
        'X-CSRF-Token',
        'Origin',
        'Cache-Control',
        'Pragma',
        'X-New-Token',        
        'X-Token-Refreshed',
        'X-Token-Warning',    
        'X-Token-Expires-In',
    ],

    'exposed_headers' => [
        'Authorization',
        'X-Total-Count',
        'X-Page-Count',
        'X-New-Token',       
        'X-Token-Refreshed', 
        'X-Token-Warning',   
        'X-Token-Expires-In',
    ],

    'max_age' => 0,

    'supports_credentials' => true,

];