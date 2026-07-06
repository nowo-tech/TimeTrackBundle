# Security

## Client API

- Bearer tokens stored hashed (SHA-256) in `{prefix}_client_tokens`
- Rate limit login attempts per IP + username (`cache.app`)
- CORS: `chrome-extension://`, `moz-extension://`, `tauri://` by default; no `*` in production
- Purge expired tokens: `php bin/console nowo:time-track:client-tokens:purge`

## Reports

- Managers access team data via `TeamContextProviderInterface`
- `TimeEntryAccessCheckEvent` for custom ACL

## Checklist

- [ ] `clients.cors_allowed_origins` restricted in production
- [ ] HTTPS only
- [ ] Cron for token purge
- [ ] Wire TaskBoardBundle ACL in `task_provider`
