# TLT Verifactu

A Laravel package for EU VAT validation and VERIFACTU support. This package can be used with any
invoicing system (SIF). You must submit the declaration of responsibility (declaración responsable) for
your system yourself.

**Please note:** The tax regimes for the Canary Islands, Ceuta, and Melilla are not supported.

## Installation

### Prerequisites

PHP 8.3 or above with the dom, json, libxml, openssl, and soap extensions installed and enabled.
Laravel 12 or above is required.

### Requirements for QR Code Generator

- [`ext-gd`](https://www.php.net/manual/book.image) for `GDLib`-based output **or**
- [`ext-imagick`](https://github.com/Imagick/imagick) with [ImageMagick](https://imagemagick.org) installed
- [`ext-fileinfo`](https://www.php.net/manual/book.fileinfo.php), required by `Imagick` output

### OpenSSL Configuration

The Spanish FNMT certification authority uses outdated encryption algorithms that are not supported by
OpenSSL 3.0 and above.

For OpenSSL 3.0 or later, you must enable legacy encryption methods. To do this, open your
`openssl.conf` (e.g., `/etc/ssl/openssl.cnf` on Ubuntu/Debian) and add the following:

```
[openssl_init]
providers = provider_sect

# List of providers to load
[provider_sect]
default = default_sect
legacy = legacy_sect

[default_sect]
activate = 1

[legacy_sect]
activate = 1
```

To install the package, run:

```bash
composer require taiwanleaftea/tlt-verifactu
```

```bash
php artisan vendor:publish --tag=tlt-verifactu --ansi --force
```

The package will be installed, and `config/tlt-verifactu.php` will be published.

Edit the published config file and replace the software provider and system values (`provider_name`, `provider_nif`,
`provider_country`, `provider_id_type`, `system_name`, etc.) with the values for your invoicing system. These values
are intentionally plain Laravel config values because each application should commit the SIF/provider configuration it uses.

If you use the local registry (`registry` or `no_verifactu` mode), run the package migration:

```bash
php artisan migrate
```

You can also publish the migration first:

```bash
php artisan vendor:publish --tag=tlt-verifactu-migrations --ansi
```

Open your `.env` file and add `VERIFACTU_PRODUCTION` (set it to `true` to use the production AEAT server)
and `VERIFACTU_DISK` (the disk where certificates are stored). `VERIFACTU_MODE` can be used to select the operating
mode, and `VERIFACTU_REGISTRY_SCOPE` can be used to separate local registry chains for different SIF instances.

### VERIFACTU Configuration

The package supports three operating modes:

```php
'mode' => env('VERIFACTU_MODE', VerifactuMode::ONLINE->value),
```

- `online`: sends records to AEAT immediately. Records are not XAdES-signed by default.
- `registry`: creates and stores unsigned local records in `verifactu_records`.
- `no_verifactu`: creates local records, signs each record with XAdES-EPES, and stores both unsigned and signed XML.

Optional local registry scope:

```php
'registry_scope' => env('VERIFACTU_REGISTRY_SCOPE'),
```

Leave it `null` for a single SIF chain. Set a stable value when one backend must keep separate local chains for
different SIF instances.

Optional online record signing:

```php
'online_sign_records' => false,
```

This is intentionally a config-only option. Set it to `true` only if you want online VERIFACTU records to be signed
with XAdES-EPES before SOAP submission.

Representative/apoderado certificates:

```php
'allow_representative_certificate' => false,
```

By default, the package checks that the certificate subject NIF matches the invoice issuer NIF before online submission
or no VERIFACTU signing. Set this config-only option to `true` only when the certificate belongs to an authorized
representative (`apoderado` or `colaborador social`) for that issuer.

### Local Registry Database

The package provides a `verifactu_records` table for local registry storage. It stores:

- invoice identity and issuer data;
- record type (`alta` or `anulacion`) and invoice type;
- local chain data (`hash`, `previous_hash`, `previous_record_id`, `registry_scope`);
- generated unsigned XML in `request_xml`;
- XAdES-signed XML in `signed_xml` for `no_verifactu` mode;
- signature policy and certificate metadata;
- AEAT response fields for future export/submission workflows;
- `created_at` and `updated_at` timestamps.

If the table already exists, the migration fails with a clear message instead of overwriting an existing registry.

## Usage

### VAT Number Validator

```php
use Taiwanleaftea\TltVerifactu\Support\Facades\VatValidator;

// Offline validation by format
echo VatValidator::formatValid('ES', 'B12345678');

// Online validation via the VIES service
$response = VatValidator::online('ES', 'B12345678');
if ($response->success) {
    // VAT number is present in the VIES database
    echo $response->valid;
    // Data returned from the VIES database (varies per country)
    echo $response->vatNumber;
    echo $response->countryCode;
    echo $response->requestDate;
    echo $response->name;
    echo $response->address;
} else {
    foreach ($response->errors as $error) {
        echo $error . PHP_EOL;
    }
}
```

### VERIFACTU Service

#### Register Invoice
```php
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Classes\Recipient;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Support\Facades\Verifactu;

$certificate = new Certificate('certificate.p12', 'password');
$issuer = new LegalPerson('XYZ SA', 'A12345678', 'ES', IdType::NIF);
$recipient = new Recipient('ABC SL', 'B12345678', 'ES', IdType::NIF);
Verifactu::config($certificate);

$previous = [
    'number' => '2025/1',
    'date' => Carbon::createFromFormat('Y-m-d', '2025-01-10'),
    'hash' => '8B709172FA124AC15D8F8570F941EBA70F99088628D4A59BF675627A7E250F15',
];

$invoice = [
    'number' => '2025/2',
    'date' => Carbon::createFromFormat('Y-m-d', '2025-01-12'),
    'description' => 'Invoice description',
    'type' => InvoiceType::STANDARD,
    'amount' => 121,
    'base' => 100,
    'vat' => 21,
    'rate' => 21,
];

try {
    $result = Verifactu::submitInvoice(
        issuer: $issuer,
        invoiceData: $invoice,
        options: [], // 'subsanacion', 'rectificado'
        operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
        previous: $previous, // or null for the first invoice
        recipient: $recipient,
    );
} catch (CertificateException $e) {
    $this->error($e->getMessage());
    exit();
}

echo $result->hash;
echo $result->json; // JSON response from the SOAP client

if ($result->success) {
    echo 'Success';
    echo $result->csv; // CSV code from AEAT
    echo $result->qrSVG; // QR code as SVG string
    echo $result->qrURI; // Fully qualified URI for further QR code generation
} else {
    if ($result->status == EstadoRegistro::ACCEPTED_ERRORES) {
        // Invoice was registered with errors
        echo $result->status->name;
        echo $result->csv;
    }

    echo 'Errors:' . PHP_EOL;
    foreach ($result->errors as $error) {
        echo $error;
    }
}
```

#### Cancel Invoice
```php
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Classes\LegalPerson;
use Taiwanleaftea\TltVerifactu\Enums\EstadoRegistro;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Exceptions\CertificateException;
use Taiwanleaftea\TltVerifactu\Support\Facades\Verifactu;

Verifactu::config($certificate);
$issuer = new LegalPerson('XYZ SA', 'A12345678', 'ES', IdType::NIF);

$previous = [
    'number' => '2025/2',
    'date' => Carbon::createFromFormat('Y-m-d', '2025-01-12'),
    'hash' => '8B709172FA124AC15D8F8570F941EBA70F99088628D4A59BF675627A7E250F15',
];

$invoice = [
    'number' => '2025/2',
    'date' => Carbon::createFromFormat('Y-m-d', '2025-01-12'),
];

try {
    $result = Verifactu::cancelInvoice(
        issuer: $issuer,
        invoiceData: $invoice,
        previous: $previous,
    );
} catch (CertificateException $e) {
    echo $e->getMessage();
    exit();
}

echo $result->hash;
echo $result->json;

if ($result->success) {
    echo 'Success';
    echo $result->csv;
} else {
    if ($result->status == EstadoRegistro::ACCEPTED_ERRORES) {
        echo $result->csv;
    }

    echo 'Errors:' . PHP_EOL;
    foreach ($result->errors as $error) {
        echo $error;
    }
}
```

#### Local Registry and No VERIFACTU

For a local unsigned registry, set:

```php
'mode' => VerifactuMode::REGISTRY->value,
```

`submitInvoice()` and `cancelInvoice()` will generate the VERIFACTU record XML and save it in `verifactu_records`
without calling AEAT. The response includes:

```php
echo $result->registryRecordId;
echo $result->hash;
echo $result->request; // unsigned RegistroAlta or RegistroAnulacion XML
```

For a no VERIFACTU registry, set:

```php
'mode' => VerifactuMode::NO_VERIFACTU->value,
```

Then configure a signing certificate before generating records:

```php
use Taiwanleaftea\TltVerifactu\Classes\Certificate;
use Taiwanleaftea\TltVerifactu\Support\Facades\Verifactu;

$certificate = new Certificate('certificate.p12', 'password');
Verifactu::config($certificate);
```

In `no_verifactu` mode, every `RegistroAlta` and `RegistroAnulacion` is signed immediately with XAdES-EPES and stored
with signature metadata:

```php
echo $result->request; // unsigned XML
echo $result->signedRequest; // XAdES-EPES signed XML
```

The package currently stores the no VERIFACTU registry but does not yet provide an export/remisión por requerimiento
builder.

## QR Code Generation

```php
use Illuminate\Support\Carbon;
use Taiwanleaftea\TltVerifactu\Support\Facades\Verifactu;
use Taiwanleaftea\TltVerifactu\Exceptions\QRGeneratorException;

// Base64-encoded PNG
try {
    $qrcode = Verifactu::generateQrPNG(
        issuerNIF: 'A12345678',
        invoiceDate: Carbon::createFromFormat('Y-m-d', '2025-01-12'),
        number: '2025/2',
        totalAmount: '121.00',
    );
} catch (QRGeneratorException $e) {
    echo $e->getMessage();
}

// QR code in SVG format
$qrcode = Verifactu::generateQrSVG(
    issuerNIF: 'A12345678',
    invoiceDate: Carbon::createFromFormat('Y-m-d', '2025-01-12'),
    number: '2025/2',
    totalAmount: '121.00',
);
```

# License
TLT Verifactu is licensed under the MIT License. See the LICENSE file for more information.
