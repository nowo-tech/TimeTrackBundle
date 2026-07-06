# TimeTrack browser extension

Chrome/Firefox MV3 extension for timer control.

## Setup

1. Enable `nowo_time_track.clients.enabled: true` on your Symfony app.
2. Open `chrome/` in `chrome://extensions` → Load unpacked.
3. Set server URL in extension storage (default `http://localhost:8024`).

## Flow

1. Popup login → `POST /api/time-track/login`
2. List tasks → `GET /api/time-track/tasks`
3. Start/stop → `POST /api/time-track/timer/start|stop`
