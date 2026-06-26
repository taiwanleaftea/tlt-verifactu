# Changelog

All notable changes to `tlt-verifactu` will be documented in this file.

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
