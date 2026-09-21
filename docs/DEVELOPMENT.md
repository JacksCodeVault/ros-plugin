# Development guide

## Environment

Use Git, Composer 2, PHP 8.0+ CLI with `gmp`, `json`, `curl`, `openssl`, and `sqlite3`, and Node.js for JavaScript syntax checks. Composer resolves dependencies against PHP 8.0 while CI checks PHP 8.2 through 8.4. Use the PHP version supplied by your supported UISP installation for integration testing and report that exact version.

FreeRADIUS development also needs a lab MySQL/MariaDB database and FreeRADIUS server. The backend tries `pdo_mysql` first and has a socket-client fallback; test the path your change affects. Installing a PHP extension does not replace testing that path.

```sh
git clone https://github.com/JacksCodeVault/ros-plugin.git
cd ros-plugin
composer install
composer check-platform-reqs
```

Use the committed lockfile. Do not run `composer update` unless changing dependencies is part of the contribution.

## How the application runs

The UI is PHP templates plus vanilla JavaScript and local CSS. It has no npm build step or Tailwind CDN dependency. `public.php` serves the panel and JSON requests; `main.php` runs scheduled work; `hook_install.php` initializes the plugin in UISP. The `public/admin-zone.js` extension modifies the plugin's UISP menu icon.

Install a development ZIP into an isolated, self-hosted UISP instance following the [release guide](RELEASING.md). Configure a dedicated lab router and synthetic subscribers. UISP supplies installation metadata and runtime configuration; opening the panel through `php -S` alone does not reproduce authentication, database setup, webhook delivery, or provisioning.

The panel routes are `#devices`, `#services`, `#plans`, `#attributes`, `#settings`, and `#system`. Keep API requests, field IDs, hash routes, and persisted settings compatible when changing presentation.

## Local checks

From the repository root, PowerShell:

```powershell
composer validate --no-check-publish
composer check-platform-reqs
$testFiles = @('tests/regression.php', 'tests/storage.php', 'tests/transport.php')
foreach ($test in $testFiles) {
    php $test
    if ($LASTEXITCODE -ne 0) { throw "Test failed: $test" }
}
$phpFiles = @('public.php', 'main.php', 'hook_install.php') + @(Get-ChildItem lib,public,includes -Recurse -Filter '*.php' -File | Select-Object -ExpandProperty FullName)
foreach ($file in $phpFiles) {
    php -l $file
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $file" }
}
node --check public/admin-zone.js
if ($LASTEXITCODE -ne 0) { throw 'Admin-zone JavaScript syntax check failed' }
node --check public/panel/assets/app.js
if ($LASTEXITCODE -ne 0) { throw 'Panel JavaScript syntax check failed' }
Get-ChildItem includes -Recurse -Filter '*.json' -File | ForEach-Object {
    Get-Content -Raw -LiteralPath $_.FullName | ConvertFrom-Json -ErrorAction Stop | Out-Null
}
Get-Content -Raw manifest.json | ConvertFrom-Json -ErrorAction Stop | Out-Null
```

On Linux/macOS, use `find lib public includes -name '*.php' -exec php -l {} \;` and lint the three root PHP entrypoints separately. Run the same Composer and Node checks above. Investigate warnings and record existing failures; do not silently call them passing checks.

## Manual integration checks

The automated suite covers isolated transport framing, TLS verification, durable job behavior, storage migrations, backup validation, request security, and provisioning regressions. It does not replace live UISP or RouterOS integration testing. For affected workflows, record pass/fail/not-tested and the exact environment:

| Surface | Checks |
| --- | --- |
| Installation and upgrade | ZIP layout, enable/install hook, existing data retained, scheduler and webhook delivery |
| Routers | Add/edit/remove, retained password, connection failure, search, API/RADIUS mode |
| Plans and services | QoS values, contention/burst configuration, filters, pagination, lifecycle provisioning |
| Settings and attributes | Load/save, retained values on failure, database connection test, standard attributes |
| System | Backup, restore in lab only, retry after outage, clear queue/log, cache rebuild |
| UI | Every tab, empty/loading/error states, dialogs, keyboard focus, desktop/narrow layouts, theme, sidebar icon |

For provisioning changes, verify the resulting RouterOS accounts/leases/queues or RADIUS rows, not just a success toast. Verify retries do not duplicate resources. Remove secrets from test evidence.
