<?php

return [
    'production' => env('VERIFACTU_PRODUCTION',false),

    // Software information
    'system_name' => 'Invoicing Software',
    'installation_number' => 1,
    'verifactu_only' => true,
    'multiple_tax_payers' => true,
    'single_tax_payer_mode' => false,
    'provider_name' => 'Software Provider Ltd',
    'provider_nif' => '12312367X',
    'provider_country' => 'ES',
    'provider_certificate_type' => \Taiwanleaftea\TltVerifactu\Enums\IdType::NIF,
];
