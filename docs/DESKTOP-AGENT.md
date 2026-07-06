# Desktop agent (Tauri)

Native system-tray client under `desktop/` (Tauri 2 + Vite).

## Features

- Login against `/api/time-track/login` with `clientType: desktop`
- System tray with Show / Hide / Quit
- Task selector + start/stop timer
- Heartbeat every 30s with OS idle detection (Linux: `xprintidle`)

## Build

```bash
cd desktop
pnpm install
pnpm tauri dev      # native tray + window
pnpm tauri build    # release binary
```

Web-only fallback (no Rust): `pnpm dev` → http://localhost:1420

Configure idle threshold via `VITE_IDLE_THRESHOLD_SECONDS` (default 300).

See [desktop/README.md](../desktop/README.md).
