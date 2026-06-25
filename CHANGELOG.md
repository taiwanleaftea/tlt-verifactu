# Changelog

All notable changes to `tlt-verifactu` will be documented in this file.

## 2.1.0 - 2026-06-25

### Added

- Add a local VERIFACTU registry table with generated record XML, chain hashes, AEAT response fields, QR URL data, timestamps, and XAdES metadata columns.
- Add package migration loading and the `tlt-verifactu-migrations` publish tag.
- Add operating modes: `online`, `registry`, and `no_verifactu`.
- Add `registry_scope` to support separate local registry chains for different SIF instances.
- Add `online_sign_records` config flag to optionally sign online VERIFACTU records before SOAP submission.
- Add XAdES-EPES signing for `RegistroAlta` and `RegistroAnulacion`, including AGE signature policy metadata.
- Add no VERIFACTU mode that requires a signing certificate, stores unsigned `request_xml`, stores signed `signed_xml`, and records signature/certificate metadata.
- Add registry/no VERIFACTU tests covering migrations, chained records, cancellations, signing, and configuration.
- Add `provider_id_type` config key and keep `provider_certificate_type` as a backward-compatible fallback.
- Add `allow_representative_certificate` config flag and issuer/certificate NIF validation for online and no VERIFACTU signing paths.

### Changed

- Stop signing online VERIFACTU records by default; online records are only signed when `online_sign_records` is enabled.
- Store local registry records from `submitInvoice()` and `cancelInvoice()` when using `registry` or `no_verifactu` mode.
- Use a source-aware hash cache so invoice hashes are recalculated when timestamp or previous hash input changes.
- Generate cancellation hashes from the previous registry record hash used in `RegistroAnterior/Huella`.

### Fixed

- Avoid adding a non-XAdES XMLDSig signature to online VERIFACTU submissions by default.
- Preserve the generated timestamp consistently across XML generation, hash generation, and stored registry records.

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
