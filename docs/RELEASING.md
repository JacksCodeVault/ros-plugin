# Release guide

Dev-Jack maintains releases for [JacksCodeVault/ros-plugin](https://github.com/JacksCodeVault/ros-plugin). Publication and GitHub settings are separate maintainer actions; adding these docs does not publish the repository or enable reporting features.

## Before the first public release

Retain evidence of the inherited-code license confirmed by the maintainer and preserve the attribution in [THIRD_PARTY_NOTICES.md](../THIRD_PARTY_NOTICES.md). The maintainer has approved inclusion of the supplied MikroTik artwork under MikroTik's permission, but the artwork remains third-party material and must retain its notice. The project's MIT declarations apply only as stated in `LICENSE`.

Inspect the tracked files and Git history for credentials, UISP-generated `ucrm.json`, customer information, logs, router exports, and database backups. `.gitignore` does not remove already tracked material or history. If a secret was committed, rotate it and coordinate history cleanup before publication.

The required icon is explicitly exempted from the broad image ignore rule. Confirm it is tracked and present in both a clean clone and the release archive.

## Prepare a release

1. Complete the [development checks and affected lab workflows](DEVELOPMENT.md).
2. Select a version using `major.minor.patch`: major for incompatible changes, minor for compatible features, patch for compatible fixes. Update `manifest.json`, the setup/install version, the changelog, and upgrade documentation together. Composer package metadata intentionally has no independent runtime version.
3. Move verified Unreleased entries into a dated changelog section. State tested UISP, RouterOS, and PHP versions, migrations, known limitations, and rollback requirements.
4. Build from a clean checkout. Install locked production dependencies with `composer install --no-dev --prefer-dist --optimize-autoloader`, then run `composer check-platform-reqs` and `composer audit`. Assess dependency findings before release.
5. Create a new ZIP outside the source directory using the explicit source allowlist below. Include dependency and attribution license files.

PowerShell with 7-Zip, from a clean checkout whose dependencies are installed:

```powershell
$version = (Get-Content -Raw manifest.json | ConvertFrom-Json).information.version
$archive = Join-Path (Split-Path (Get-Location).Path -Parent) "ros-plugin-$version.zip"
if (Test-Path -LiteralPath $archive) { throw "Archive already exists: $archive" }
& 'C:\Program Files\7-Zip\7z.exe' a -tzip $archive manifest.json composer.json composer.lock main.php public.php hook_install.php hook_update.php lib includes public vendor README.md LICENSE THIRD_PARTY_NOTICES.md CHANGELOG.md CONTRIBUTING.md SECURITY.md CODE_OF_CONDUCT.md SUPPORT.md docs -xr!.git -xr!.github -xr!tests -xr!Tests -xr!IMPLEMENTATION_CHECKLIST.md
if ($LASTEXITCODE -ne 0) { throw 'Packaging failed' }
& 'C:\Program Files\7-Zip\7z.exe' t $archive
if ($LASTEXITCODE -ne 0) { throw 'Archive verification failed' }
& 'C:\Program Files\7-Zip\7z.exe' l $archive
Get-FileHash -Algorithm SHA256 -LiteralPath $archive
```

The ZIP must contain `manifest.json` at its root, not inside another directory. Never include `.git/`, `.github/`, `.env`, `ucrm.json`, `test.json`, `data/`, QA browser profiles, credentials, or nested archives. If new lifecycle hooks or required assets are added, update the allowlist.

## Verify and publish

Install the exact archive in lab UISP. Test both fresh install and upgrade from the previous released package, including preservation of configuration and subscriber mappings. Verify the panel and packaged artwork. Back up plugin data and router configuration before testing restore or migration.

After verification, tag the reviewed commit `vX.Y.Z` and publish a GitHub Release containing the ZIP, SHA-256 checksum, and release notes. Do not tag an unreviewed working tree. Keep the previous archive and compatible data backup available: a downgrade alone may not reverse a database migration or router-side changes.
