# Changelog

All notable changes to `tlt-verifactu` will be documented in this file.

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
