# TimeTrack Desktop (Tauri 2)

Native system-tray agent for the TimeTrackBundle REST API.

## Prerequisites

- [Node.js](https://nodejs.org/) 20+ and [pnpm](https://pnpm.io/)
- [Rust](https://www.rust-lang.org/tools/install) stable
- [Tauri prerequisites](https://tauri.app/start/prerequisites/) for your OS
- Linux idle detection (optional): `xprintidle` (`apt install xprintidle`)

## Quick start

```bash
cd desktop
pnpm install
cp .env.example .env   # optional
pnpm tauri dev
```

Default API server: `http://localhost:8024` (integrated demo). Login with `demo@example.com` / `demo`.

## Scripts

| Command | Description |
|---------|-------------|
| `pnpm dev` | Vite web UI only (browser) |
| `pnpm tauri dev` | Native window + system tray |
| `pnpm tauri build` | Release binary in `src-tauri/target/release/` |

## Environment

| Variable | Default | Description |
|----------|---------|-------------|
| `VITE_IDLE_THRESHOLD_SECONDS` | `300` | Seconds before heartbeat marks user idle |

## Behaviour

- **Login** via `POST /api/time-track/login` with `clientType: desktop`
- **Tray icon** with Show / Hide / Quit menu; close button hides to tray
- **Heartbeat** every 30s with `isIdle` from OS (`xprintidle` on Linux)
- **Timer** start/stop against bundle tasks (wire `TaskBoardBundle` in the host app)

## Web-only fallback

Without Rust installed, use the Vite shell:

```bash
pnpm install && pnpm dev
# open http://localhost:1420
```
