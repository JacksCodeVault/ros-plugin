# Upgrading to 2.4.0

Back up the UISP plugin data directory and router configuration before upgrading. Test the exact archive on a non-production UISP installation first.

## Required changes

1. Confirm UISP's plugin runtime uses PHP 8.0 or newer with `gmp`, `json`, `curl`, `sqlite3`, and `openssl`.
2. Generate a random webhook token of at least 32 characters and save it in the plugin's UISP settings.
3. Configure the same token on the UISP webhook as the `X-ROS-Webhook-Token` header. If the UISP version cannot set headers, append `?webhook_token=<URL-encoded-token>` to the plugin webhook URL. Never place that URL in logs, tickets, or screenshots.
4. Open each router and choose its API transport. Existing routers remain on plain TCP after migration to avoid silently breaking connectivity. Use plain TCP only through a trusted management VPN. For API-SSL, select verified TLS, normally use port 8729, and provide the certificate hostname and private CA path when the CA is not in the UISP host trust store.
5. Review System → Retry queue. Legacy `queue.json` entries are imported as **held** because the old format cannot prove the original router and username. Reconcile those services manually; do not blindly replay old deletions.

## Security and data behavior

- Panel access now requires a logged-in UISP administrator with edit permission for plugins. Mutating requests carry a session-bound token.
- Backups can no longer be copied into the public directory. Any old `public/backup-0` through `public/backup-6` files are moved to `data/quarantine/` during setup and remain recoverable by the server administrator.
- Restores accept only compatible SQLite databases, reject triggers/views and oversized/corrupt files, create `data/pre-restore.db`, and hold existing retry jobs for reconciliation.
- RADIUS mode now requires transactional InnoDB tables. A non-default shared secret of at least 16 characters is required for Disconnect requests.

## RouterOS support target

| Target | Expected transport support | Required validation |
| --- | --- | --- |
| RouterOS 6.49.x long-term/stable | Native API, legacy/current login | PPP, Hotspot, DHCP, queues, IPv6 if enabled |
| RouterOS 7.x maintained releases | Native API, current login, verified API-SSL | Same workflows plus package/device-mode permissions |
| RouterOS 7.18+ | Native API with `!empty` responses | Empty-result operations and all provisioning workflows |

The **Check API** action performs read-only version, menu-access, and FastTrack checks. It is a preflight, not certification. Confirm resulting accounts, leases, queues, RADIUS rows, disconnections, and actual shaped traffic in the lab.

RouterOS is Linux-kernel-based, but RouterOS v7 container support does not make this UISP plugin an arbitrary on-router application. Keep the plugin hosted by UISP.
