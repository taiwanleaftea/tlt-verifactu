# Changelog

All notable changes to `tlt-verifactu` will be documented in this file.

## 2.3.0 - 2026-06-30

### Added

- Add multiple VAT breakdown detail support through `invoiceData['breakdown']`, including XML generation for multiple `DetalleDesglose` nodes and a 12-detail validation limit.
- Add `submitSimplifiedInvoice()` helper for `F2` invoices without recipient data.
- Add `VerifactuRecordVariant` and store each registry row as `standard`, `subsanacion`, or `rectificativa`.
- Add recipient columns and casts to the local registry table/model (`recipient_name`, `recipient_id`, `recipient_country_code`, `recipient_id_type`).
- Add `ResponseAeat::$csv` and keep registry model access through `ResponseAeat::$registryRecord`.
- Add NO VERIFACTU QR verification endpoints for sandbox and production.
- Add shared `EnumValues` trait to all enum classes.

### Changed

- Store recipient data, registry variants, and VAT breakdown details in `verifactu_records.invoice_payload`.
- Preserve VAT breakdown details when building difference rectification invoices from a stored registry payload.
- Generate QR URLs with the correct VERIFACTU or NO VERIFACTU endpoint and RFC 3986 query encoding.

### Fixed

- Do not store rejected online `RegistroAlta` responses (`EstadoRegistro=Incorrecto`) in `verifactu_records`, avoiding local chain links to records that AEAT did not accept.
- Allow simplified rectification invoices (`R5`) to be submitted without recipient data.
- Use NO VERIFACTU QR verification URLs when no VERIFACTU mode is active.

## 2.2.4 - 2026-06-26

### Fixed

- Handle certificate signature metadata failures as `ResponseAeat` errors instead of leaking exceptions.
- Document `createSoapClient()` and `signatureMetadata()` exception contracts and cover their error paths in tests.

## 2.2.3 - 2026-06-26

### Added

- Add `VerifactuRecordType` enum and `VerifactuRecord` Eloquent model for the local registry table.
- Add `VerifactuRecord` chain helpers and allow registry-backed APIs to receive either a record id or model instance.

### Changed

- Use the `VerifactuRecord` Eloquent model for local registry persistence and lookup inside the VERIFACTU service.
- Simplify `cancelInvoice()` so it builds `RegistroAnulacion` from a local `VerifactuRecord` or record id.
- Simplify `submitRectificationInvoice()` so it can build a difference rectification from `invoice_payload`.

## 2.2.2 - 2026-06-26

### Fixed

- GitHub workflow permissions added

## 2.2.1 - 2026-06-26

### Fixed

- Fix PHP 8.4 compatibility by avoiding `unset()` on `InvoiceSubmission::$exemptOperation`.
- Update the test workflow to use `actions/checkout@v5` and explicitly install SQLite extensions.

## 2.2.0 - 2026-06-26

### Added

- Add a local VERIFACTU registry table with generated record XML, chain hashes, AEAT response fields, QR URL data, timestamps, and XAdES metadata columns.
- Add package migration loading and the `tlt-verifactu-migrations` publish tag.
- Add operating modes: `online` and `no_verifactu`.
- Add `registry_scope` to support separate local registry chains for different SIF instances.
- Add `online_sign_records` config flag to optionally sign online VERIFACTU records before SOAP submission.
- Add XAdES-EPES signing for `RegistroAlta`, including AGE signature policy metadata.
- Add `ExemptOperationType::E7` and `ExemptOperationType::E8`.
- Add no VERIFACTU mode that requires a signing certificate, stores unsigned `request_xml`, stores signed `signed_xml`, and records signature/certificate metadata.
- Add online/no VERIFACTU tests covering migrations, chained records, signing, and configuration.
- Add `provider_id_type` config key and keep `provider_certificate_type` as a backward-compatible fallback.
- Add `allow_representative_certificate` config flag and issuer/certificate NIF validation for online and no VERIFACTU signing paths.
- Add local registry persistence for online VERIFACTU submissions, including a signed record copy and AEAT response metadata.
- Add `subsanateInvoice()` and `submitRectificationInvoice()` helpers backed by `verifactu_records`.
- Add `enable_cancel_invoice_in_production` / `VERIFACTU_ENABLE_CANCEL_INVOICE_IN_PRODUCTION` guard for `cancelInvoice()`.
- Add `cancelInvoiceByRecordId()` as a registry-backed wrapper around `cancelInvoice()`.
- Add `getPreviousId()` and `getPreviousHash()` helpers for issuer/scope registry chains.
- Add explicit `RechazoPrevio` support for `RegistroAlta` and `SinRegistroPrevio` / `RechazoPrevio` support for `RegistroAnulacion`.

### Changed

- Stop signing online VERIFACTU records by default; online records are only signed when `online_sign_records` is enabled.
- Store local registry records from `submitInvoice()` and sandbox/fallback `cancelInvoice()` in all VERIFACTU modes.
- Document that `submitRectificationInvoice()` supports only `TipoRectificativa=I` (`por diferencias`) and expects signed difference amounts.
- Use a source-aware hash cache so invoice hashes are recalculated when timestamp or previous hash input changes.

### Fixed

- Avoid adding a non-XAdES XMLDSig signature to online VERIFACTU submissions by default.
- Preserve the generated timestamp consistently across XML generation, hash generation, and stored registry records.
- Generate exempt operations with `OperacionExenta` only, without requiring `CalificacionOperacion` and without adding `TipoImpositivo` or `CuotaRepercutida`.
- Use production WSDL and production QR verification URLs when production mode is enabled.

## 2.0.0 - 2026-06-25

### Breaking Changes

- Require Laravel 12+.
- Register Verifactu and VAT validator container bindings against their concrete support services instead of facade classes.

### Changed

- Target Laravel 12+ by declaring Laravel 12 component dependencies.
- Register Verifactu and VAT validator service bindings against their concrete support services.
- Reorganize tests into VAT Validator and Verifactu suites.
- Format the codebase with Laravel Pint.

### Added

- GitHub Actions workflow for Composer validation, style checks, static analysis, and tests.
- Laravel Pint and Larastan configuration.
- Composer suggestions for optional QR image rendering extensions.
- Local AEAT XSD fixtures for offline XML validation tests.
- Expanded VAT Validator tests for the full public API.

### Fixed

- Use the provided timestamp when generating invoice submission hashes.
- Use the cancelled invoice hash when generating cancellation hashes.

### Removed

- Empty Composer autoload and package discovery entries.
