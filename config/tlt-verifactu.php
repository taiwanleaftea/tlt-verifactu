<?php

use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\VerifactuMode;

return [
    // "online" sends records to AEAT. "registry" stores unsigned records. "no_verifactu" stores signed records.
    'mode' => env('VERIFACTU_MODE', VerifactuMode::ONLINE->value),

    // Optional local registry chain scope. Leave null for a single SIF chain.
    'registry_scope' => env('VERIFACTU_REGISTRY_SCOPE'),

    // Optional XAdES-EPES signature for online VERIFACTU records before SOAP submission.
    'online_sign_records' => false,

    // Allow certificates issued to an authorized representative (apoderado/colaborador social)
    // instead of requiring the certificate NIF to match the invoice issuer NIF.
    'allow_representative_certificate' => false,

    'production' => env('VERIFACTU_PRODUCTION', false),

    // Disk with certificates
    'disk' => env('VERIFACTU_DISK', 'local'),

    // Software information
    'system_name' => 'Invoicing Software',
    'installation_number' => 1,
    'verifactu_only' => true,
    'multiple_tax_payers' => true,
    'single_tax_payer_mode' => false,
    'provider_name' => 'Software Provider Ltd',
    'provider_nif' => '12312367X',
    'provider_country' => 'ES',
    'provider_id_type' => IdType::NIF,

    // Return values
    'generate_svg' => false,
];
