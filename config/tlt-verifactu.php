<?php

return [
    'type-certificate' => env('VERIFACTU_CERTIFICATE',true),
    'production' => env('VERIFACTU_PRODUCTION',false),
    'path' => env('VERIFACTU_PATH',''),
    'password' => env('VERIFACTU_PASSWORD',''),

    // Software information
    'system_name' => 'Invoicing Software',
    'provider_name' => 'Software Provider Ltd',
    'provider_nif' => '12312367X'
];
