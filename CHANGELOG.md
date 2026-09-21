# Changelog

Changes are grouped by release. Release notes describe verified changes; historical details should not be reconstructed from version numbers alone.

## 2.4.0 - 2026-09-21

### Added

- Open-source contribution, development, release, support, security, and community documentation.
- MIT terms for Dev-Jack's original contributions and third-party provenance notices.
- GitHub issue forms and pull request checklist.
- Ignore rule for UISP-generated root `ucrm.json` metadata.
- Durable SQLite provisioning outbox with immutable retry targets, backoff, locking, and manual retry controls.
- Verified RouterOS API TLS settings, read-only capability diagnostics, and FastTrack warnings.
- Automated regression, migration, storage, transport, and CI checks.

### Changed

- Require PHP 8.0 or newer and align Composer, manifest, and installation versions.
- Require UISP plugin-administrator access and CSRF protection for panel mutations.
- Require an explicit token for webhook deliveries.
- Validate database restores and quarantine legacy publicly copied backups.
- Process RADIUS changes transactionally and verify Disconnect ACK/NAK authenticators.

### Fixed

- Preserve API values containing equals signs and handle `!empty`, EOF, timeout, fatal, partial read, and partial write conditions.
- Preserve the original device/account identity during retries and prevent replacement provisioning before old-account cleanup.
- Include queue, profile, pool, parent, and account operations in service-level success accounting.
- Route Hotspot RADIUS profiles correctly, clear stale suspension/IP attributes, and disconnect every matching active session.
- Preserve passwords and numeric-looking secrets when storing configuration.

### Documentation

- Corrected frontend descriptions to reflect local CSS and vanilla JavaScript.
- Documented the maintainer-confirmed upstream distribution license and third-party brand-artwork terms.

## 2.3.12

Existing baseline recorded in `manifest.json`. Earlier changes are not yet represented by a verified release history in this repository.
