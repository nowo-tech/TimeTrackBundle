# Desktop agent

The desktop app (Tauri, system tray, idle detection, offline queue) lives in its own repository,
[nowo-tech/time-track-desktop](https://github.com/nowo-tech/time-track-desktop) (private), with its builds, releases and
roadmap. It talks to the bundle through the [client API](CLIENT-API.md) (`clientType: desktop`, device flow or app
password; the Tauri HTTP plugin sends no `Origin`).

Until 1.x this repository carried a prototype under `desktop/`. 2.0 removed it: it called the removed `POST heartbeat`
and fetched from the Tauri webview origin, which 2.0 no longer allows, so it no longer worked against the bundle (audit
ZBH-L1).

Host setup: enable `clients.enabled` (and `client_api.device_flow.enabled` for the device flow), route the API-only
import and allow the routes publicly in `security.yaml` (Bearer auth is enforced by the bundle); see
[USAGE](USAGE.md#host-integration-client-api-19) and [CONFIGURATION](CONFIGURATION.md#client-api-19).
