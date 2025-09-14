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

Extensibility
- Plugins can add richer metadata via `add_filter('churchkite_connector_manifest', ...)` keyed by plugin slug

Release & Updates
- Tag as `vX.Y.Z` to build `dist/churchkite-connector.zip` and create a GitHub Release.
- CI publishes version metadata to ChurchKite Admin and uploads the ZIP to Admin Blobs.
- CI verifies Admin download by running `unzip -t` on the served ZIP.
- Admin functions are configured to return ZIPs in binary mode to avoid corruption.
