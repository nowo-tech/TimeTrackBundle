# Browser extension

The browser extension (Chrome / Firefox / Edge) lives in its own repository,
[nowo-tech/time-track-extension](https://github.com/nowo-tech/time-track-extension) (private), with its builds and
store releases. It talks to the bundle through the [client API](CLIENT-API.md) (`clientType: extension`); list its
exact origin (`chrome-extension://<id>`, `moz-extension://<uuid>`) in `client_api.allowed_origins` /
`clients.cors_allowed_origins`.

Until 1.x this repository carried a prototype under `extension/`. 2.0 removed it: it no longer worked against the 2.0
client API (audit ZBH-L1).

Host setup: enable `clients.enabled`, route the API-only import and allow the routes publicly in `security.yaml`
(Bearer auth is enforced by the bundle); see [USAGE](USAGE.md#host-integration-client-api-19) and
[CONFIGURATION](CONFIGURATION.md#client-api-19).
