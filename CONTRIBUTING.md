# Contributing

Contributions are welcome through [GitHub pull requests](https://github.com/JacksCodeVault/ros-plugin/pulls). Please follow our [code of conduct](CODE_OF_CONDUCT.md). Report vulnerabilities privately using [SECURITY.md](SECURITY.md).

## Start here

- Check existing issues before reporting a bug or proposing a feature.
- Open an issue before a large change, dependency replacement, schema change, or provisioning behavior change so maintainers can discuss compatibility.
- Documentation corrections and small fixes can go directly to a pull request.
- Use a test UISP instance and a lab router. Never develop against paying subscribers or publish customer records, credentials, tokens, backups, or unredacted logs.

## Development workflow

1. Fork the repository and clone your fork.
2. Create a descriptive branch, such as `fix/router-filter` or `docs/setup`.
3. Follow [development setup and checks](docs/DEVELOPMENT.md).
4. Keep the change focused and preserve existing API payloads, field IDs, plugin lifecycle behavior, and persisted data unless the issue explicitly calls for a compatible migration.
5. Update relevant documentation and add an entry to the Unreleased section of [CHANGELOG.md](CHANGELOG.md).
6. Open a pull request describing the problem, solution, verification, and limitations. Include redacted screenshots for UI changes and exact UISP, RouterOS, and PHP versions used for integration checks.

## Coding conventions

- Use the existing PHP and vanilla JavaScript architecture. Match nearby formatting; avoid unrelated formatting churn.
- Keep UI colors, spacing, and controls in the shared CSS system. Favor compact UISP-style tables and forms.
- Validate inputs on the server, escape rendered content, and never log passwords or tokens. Preserve existing third-party author headers.
- Database changes must preserve installed data and document upgrade and recovery behavior.
- Do not commit `vendor/`, runtime data, `.env` files, `ucrm.json`, router exports, local test payloads, or distribution archives.
- Use clear imperative commit subjects. Maintainers handle release version bumps unless explicitly requested.

## Verification and review

Run the checks in the development guide. There is currently no automated integration test suite; do not describe linting as proof that provisioning works. For network changes, report the lab actions performed and actual results, including failure and retry behavior. If you cannot run a check, say so in the PR.

Maintainers review contributions for correctness, compatibility, security, usability, and scope. Reviews and releases are best effort, with no guaranteed turnaround. Address requested changes in the same PR.

## Translations

Edit values in `includes/l10n/fields_*.json` and `messages_*.json`, keeping keys and placeholders intact. The current panel contains English strings and does not automatically consume all legacy translation files; identify which surface your translation affects. See the [translation guide](includes/l10n/README.md).

## Contribution licensing

By submitting original contributions, you agree to license them under this project's MIT terms. You retain copyright. Only contribute material you have the right to submit; identify third-party material and its license in the PR and notices. Existing third-party terms continue to apply. No copyright assignment or separate CLA is required.
