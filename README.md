# ChurchKite Connector

Installs on each WordPress site to:
- Register and verify the site with ChurchKite Admin (no secrets required)
- Send full plugin inventory (core + third‑party like MailPoet/Pods)
- Send daily heartbeats and refresh inventory on plugin changes

Install
- Upload/activate this plugin (no settings required)
- Defaults to admin at https://churchkite-plugin-admin.netlify.app
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
