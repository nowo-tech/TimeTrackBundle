# Browser extension

Minimal Chrome/Firefox extension under `extension/`.

## API

Enable clients in config and expose routes publicly in `security.yaml` (Bearer auth is enforced by the bundle).

## Build

```bash
cd extension/chrome
# Load unpacked in chrome://extensions
```

See `extension/README.md` for popup flow: login → list tasks → start/stop timer.
