# ChurchKite Connector

**Version:** 0.3.0

Installs on each WordPress site to:
- Register and verify the site with ChurchKite Admin (no secrets required)
- Send full plugin inventory (core + third‑party like MailPoet/Pods)
- Send daily heartbeats and refresh inventory on plugin changes

## Configuration

The plugin connects to the ChurchKite Admin server to register the site and report plugin inventory.

- **Admin URL**: Defaults to `https://phpstack-962122-6023915.cloudwaysapps.com`
- **Registry Key**: Optional authentication key for registry endpoints (define `CHURCHKITE_REGISTRY_KEY` constant)
- Optionally override via `CHURCHKITE_ADMIN_URL` in wp-config.php

How it works
- On activation, generates a one‑time token and exposes GET `/wp-json/churchkite/v1/proof?token=...`
- Registers with Admin and posts plugin inventory to `/api/registry/inventory`
- Daily heartbeat plus inventory refresh on plugin installs/updates/activations/deactivations

Site title included
-------------------

The connector now includes the WordPress site title (from `get_bloginfo('name')`) in all registry-related payloads so the Admin dashboard can display a friendly name alongside the site URL. The following fields are now sent to Admin where applicable:

- `siteUrl` — the site URL (unchanged)
- `siteTitle` — the site title (new)

Example proof response (GET `/wp-json/churchkite/v1/proof?token=...`):

```
{
	"ok": true,
	"siteUrl": "https://example.com",
	"siteTitle": "Example Church"
}
```

Example inventory payload keys include `siteUrl`, `siteTitle`, `wpVersion`, `phpVersion`, `token`, `proofEndpoint`, and `plugins`.

Extensibility
- Plugins can add richer metadata via `add_filter('churchkite_connector_manifest', ...)` keyed by plugin slug

Release & Updates
- Tag as `vX.Y.Z` to build `dist/churchkite-connector.zip` and create a GitHub Release.
- CI publishes version metadata to ChurchKite Admin and uploads the ZIP to Admin Blobs.
- CI verifies Admin download by running `unzip -t` on the served ZIP.
- Admin functions are configured to return ZIPs in binary mode to avoid corruption.

### Release workflow secrets

The GitHub Actions release workflow publishes to the ChurchKite Admin update server. Configure these repository secrets in GitHub (do not commit secret values):

- `CK_PUBLISH_URL`: `https://<admin-host>/api/updates/publish`
- `CK_PUBLISH_KEY`: must match the Admin server's `PUBLISH_API_KEY`
- `CK_ADMIN_VERIFY_URL`: `https://<admin-host>` (no trailing path like `/public`)
