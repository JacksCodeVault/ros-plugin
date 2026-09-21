# Security policy

## Private reporting

Email **ojangoh2@outlook.com** with the subject `ROS Plugin security report`. Do not open a public issue or PR containing an unpatched vulnerability, exploit, credentials, or customer data.

Include the affected version or commit, UISP/RouterOS/PHP versions, the affected component, impact, reproduction steps using synthetic data, and any suggested remediation. Remove secrets from attachments and keep evidence minimal. Coordinate any disclosure with the maintainer by email.

The maintainer will assess reports and coordinate fixes on a best-effort basis. There is no guaranteed response time, security support SLA, or bug bounty. If you receive no reply, send a follow-up email.

## Supported versions

Security fixes target the latest released version. Older releases are not guaranteed backports; upgrade after testing in a lab. This policy is not a claim that the application has passed a security audit.

## Deployment considerations

- Run on a trusted UISP installation and verify access controls for the plugin's public URL and mutation endpoints before exposing them.
- Restrict router management access to the UISP server or a private management network. RouterOS API port 8728 is unencrypted; use a protected network or VPN for this integration. Merely selecting port 8729 does not establish verified TLS support in the plugin.
- Use dedicated credentials, change sample secrets, and restrict database access. Keep UISP, RouterOS, PHP, and dependencies maintained.
- Treat `data/`, `ucrm.json`, backups, logs, and router exports as sensitive. Do not attach them to public issues without removing secrets and personal data.
- Back up the plugin database and router configuration before upgrades or provisioning changes.
