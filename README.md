# TLT Verifactu
Laravel library for EU VAT validation and VERIFACTU support

## OpenSSL Config
For OpenSSL version after 3.0 `openssl.conf` (i.e. `/etc/ssl/openssl.cnf` in Ubuntu/Debian) must contain following:

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
