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

Run the package migration before generating VERIFACTU records:

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
`VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION` is available as an emergency/fallback switch for
`RegistroAnulacion` in production and defaults to `false`.

### VERIFACTU Configuration

The package supports two operating modes:

```php
'mode' => env('VERIFACTU_MODE', VerifactuMode::ONLINE->value),
```

- `online`: sends records to AEAT immediately and stores the local registry row. The SOAP record is not XAdES-signed by default, but a signed copy is stored in `signed_xml`.
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

Cancel invoice fallback:

```php
'enable_cancel_invoice_in_production' => env('VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION', false),
```

`cancelInvoice()` / `RegistroAnulacion` is available for sandbox fallback scenarios. It receives the local
`VerifactuRecord` model or `verifactu_records.id` and builds the cancellation record from the local registry. In
production it is blocked unless this option is explicitly enabled. Normal corrections should use `subsanateInvoice()`
or `submitRectificationInvoice()`.

Use `cancelInvoice()` with the local `verifactu_records.id` or a `VerifactuRecord` model:

```php
$result = Verifactu::cancelInvoice(record: $invoice->verifactu_record_id);
```

`cancelInvoiceByRecordId()` remains as a backward-compatible alias. `cancelInvoice()` also accepts explicit
`sin_registro_previo` and `rechazo_previo` options for documented `RegistroAnulacion` edge cases.

### Local Registry Database

The package provides a `verifactu_records` table for local registry storage. It stores:

- invoice identity and issuer data;
- enum-backed record type (`alta`/`anulacion`) and invoice type;
- local chain data (`hash`, `previous_hash`, `previous_record_id`, `registry_scope`);
- generated unsigned record XML in `request_xml`;
- XAdES-signed record XML in `signed_xml` for `online` and `no_verifactu` modes;
- normalized invoice snapshot in `invoice_payload` for registry-backed operations;
- signature policy and certificate metadata;
- AEAT response fields for future export/submission workflows;
- `created_at` and `updated_at` timestamps.

If the table already exists, the migration fails with a clear message instead of overwriting an existing registry.
The `Taiwanleaftea\TltVerifactu\Models\VerifactuRecord` Eloquent model is available with casts for
`record_type` (`VerifactuRecordType`), `invoice_type` (`InvoiceType`), `invoice_payload`, and `response_json`. It also provides
`getPreviousRecord()`, `getPreviousRecordId()`, and `getPreviousHash()` helpers for the record's
`issuer_nif` / `registry_scope` chain.

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
use Taiwanleaftea\TltVerifactu\Enums\ExemptOperationType;
use Taiwanleaftea\TltVerifactu\Enums\IdType;
use Taiwanleaftea\TltVerifactu\Enums\InvoiceType;
use Taiwanleaftea\TltVerifactu\Enums\OperationQualificationType;
use Taiwanleaftea\TltVerifactu\Enums\RejectionStatus;
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
        options: [], // 'exempt_operation' => ExemptOperationType::E1
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

#### Subsanation and Rectification

Use `subsanateInvoice()` when an accepted registry record must be corrected with `Subsanacion=S`. Pass the local
`verifactu_records.id` or a `VerifactuRecord` model of the record being corrected; the method reads the registry record
and the latest chain record from the local registry:

```php
$result = Verifactu::subsanateInvoice(
    issuer: $issuer,
    recordId: $invoice->verifactu_record_id,
    invoiceData: $correctedInvoice,
    operationQualificationType: OperationQualificationType::SUBJECT_DIRECT,
    recipient: $recipient,
);
```

For special AEAT operativa after a previous rejection or when the record is not in AEAT, pass `rechazo_previo`
explicitly to `submitInvoice()` together with `subsanacion`:

```php
$result = Verifactu::submitInvoice(
    issuer: $issuer,
    invoiceData: $invoice,
    options: [
        'subsanacion' => true,
        'rechazo_previo' => RejectionStatus::NOT_IN_AEAT, // X
    ],
    previous: $previous,
    recipient: $recipient,
);
```

Use `submitRectificationInvoice()` for a factura rectificativa. Pass the local `verifactu_records.id` or a
`VerifactuRecord` model of the invoice being rectified. The method reads the issuer, recipient, original invoice
identity, original amounts, and latest chain record from the local registry's `invoice_payload`. Pass the new
rectification invoice number in `invoiceData`; other values can be overridden when necessary:

```php
$result = Verifactu::submitRectificationInvoice(
    rectifiedRecordId: $originalResult->registryRecordId,
    invoiceData: ['number' => 'R-2026-001'],
);
```

Rectifying invoices are generated only as `TipoRectificativa=I` (`por diferencias`). By default the package derives a
credit note with negative base, VAT, and total values from `invoice_payload`. `TipoRectificativa=S`
(`por sustitución`) is intentionally not implemented. The rectification invoice type defaults to `R4`, or `R5` when
the rectified invoice was simplified; pass `type` in `invoiceData` to use another `R1`-`R5` value.

Your ERP invoice table should store a foreign key to `verifactu_records.id` for the generated VERIFACTU record. That
key is what later ties the ERP invoice to `subsanateInvoice()` and `submitRectificationInvoice()` without relying on
invoice number/date lookups.

To inspect the current chain head for a registry sequence, use:

```php
$previousId = Verifactu::getPreviousRecordId(recordId: $invoice->verifactu_record_id);
$previousHash = Verifactu::getPreviousHash(recordId: $invoice->verifactu_record_id);
$previousRecord = Verifactu::getPreviousRecord(recordId: $invoice->verifactuRecord);

// Backward-compatible alias:
$previousId = Verifactu::getPreviousId(recordId: $invoice->verifactu_record_id);
```

Passing a `recordId` makes the package derive `issuer_nif` and `registry_scope` from that registry row. You can also
select the chain explicitly:

```php
$previousHash = Verifactu::getPreviousHash(
    issuerNif: 'A12345678',
    registryScope: 'main-backend',
);
```

#### Local Registry and No VERIFACTU

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

In `no_verifactu` mode, every `RegistroAlta` is signed immediately with XAdES-EPES and stored with signature metadata:

```php
echo $result->request; // unsigned XML
echo $result->signedRequest; // XAdES-EPES signed XML
echo $result->registryRecordId;
echo $result->registryRecord?->id;
```

The package currently stores the no VERIFACTU registry but does not yet provide an export/remisión por requerimiento
builder.

In `online` mode, `submitInvoice()` also creates a local `verifactu_records` row after AEAT responds. The table stores
the unsigned record XML, a XAdES-EPES signed copy, CSV/status/error data, raw AEAT response, and presentation timestamps
when available. The XML sent to AEAT remains unsigned unless `online_sign_records` is set to `true`.

Correct accepted records with `subsanateInvoice()` when the change belongs to the VERIFACTU record itself, or issue a
factura rectificativa with `submitRectificationInvoice()` when the invoice content must be corrected.

`cancelInvoice()` remains available as a sandbox fallback API for `RegistroAnulacion`. In production it returns an
error unless `VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION=true` is set. `cancelInvoiceByRecordId()` is kept as a
backward-compatible alias.

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
