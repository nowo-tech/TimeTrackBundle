# Meetings API (host guide, TimeTrack 1.14)

`GET /api/time-track/me/meetings` gives TimeTrack clients (TimeTrack Desktop ≥ 0.6 reminders) the caller's **own**
upcoming meetings, from **your** meeting read model. Contract: `timetrack-next` v1.1 §22; Beacon spec 210.

## Table of contents

- [Who owns what](#who-owns-what)
- [Enable it](#enable-it)
- [Implement the provider](#implement-the-provider)
- [What the bundle enforces on the way out](#what-the-bundle-enforces-on-the-way-out)
- [Your privacy duties](#your-privacy-duties)
- [Clients](#clients)

## Who owns what

| Owner | Owns |
| --- | --- |
| **TimeTrackBundle** | The route, query validation, the response schema ([`schemas/meetings.v1.json`](schemas/meetings.v1.json)), privacy filters on the way out, the `meetings:read` scope, rate limits, `GET me` flags, the provider interface. **No table, cache or log of meetings** |
| **Host** | OAuth and tokens, the calendars switch, sync (push or polling inside working hours), the meeting read model with minimal fields, internal-attendee matching, retention (spec 210: 7 days after the end), the organization switch, task links, web pages, export and erasure, legal texts |

## Enable it

```yaml
nowo_time_track:
  meetings:
    enabled: true
    provider: App\TimeTrack\MeetingProvider   # implements Nowo\TimeTrackBundle\Meeting\MeetingProviderInterface
    max_range_days: 14                        # 1..14
    max_past_days: 1                          # 0..7 (your retention)
    default_limit: 100                        # 1..200
    refresh_after_seconds: 300                # 60..3600, default poll-gap hint
    rate_limit: { per_hour: 60, per_minute: 6 }
    jitsi_hosts: []                           # extra self-hosted Jitsi hosts
    links_calendars_route: app_account_calendars   # GET me links.calendars (absolute https URL), or ~
```

No migration: the feature adds no table. Clients need the **`meetings:read`** scope, which is never granted by default
(tokens without stored scopes keep the pre-1.13 set): the desktop requests it in the device flow, so existing desktops
re-consent once.

## Implement the provider

```php
final class MeetingProvider implements MeetingProviderInterface
{
    public function meetingsFor(UserInterface $user, MeetingQuery $query): MeetingPage
    {
        // Own meetings of $user overlapping [$query->from, $query->to) (UTC), at most $query->limit, from your store.
        // Declined and cancelled events are left out. Never another person's meetings.
        return new MeetingPage($views, $this->stateFor($user), $truncated);
    }

    public function stateFor(UserInterface $user): MeetingSyncState
    {
        // Disabled (organization switch) → the route answers 404 and GET me says features.meetings: false.
        return new MeetingSyncState(MeetingSyncStatus::Connected, $lastSyncAt, $refreshAfterSeconds);
    }
}
```

`MeetingView` fields: `id` (`mtg_` + 16–64 of `[A-Za-z0-9_-]`, stable per occurrence across reschedules: HMAC of
provider ‖ calendar ‖ event id ‖ original start — never the provider id), `start` / `end`, `timeZone`, `allDay`, `busy`
(false for free / transparent), `private`, `title`, `joinUrl` (raw candidate), `organizerIsMe`, `attendees`
(`MeetingAttendeeView{id, name}` of **internal** attendees who share an organization with the caller), `externalCount`,
`taskLinks` (`MeetingTaskLink{taskId, key}`), `updatedAt`. `MeetingView` refuses serialization: build it per request,
never cache it in a shared pool. Exceptions from the provider answer `503 meetings_unavailable` (`Retry-After: 60`);
their message never leaves the bundle and only the exception class is logged.

## What the bundle enforces on the way out

- Field whitelist in a fixed order; no e-mail, avatar, response status, external name or domain, body or location.
- `private: true` → `title: null`, `attendees: []`, `externalCount: null`, `taskLinks: []`; the owner keeps `joinUrl`.
- Titles: control and bidi characters removed, whitespace folded, ≤ 200 characters.
- `joinUrl` / `joinProvider` only for `https` links without user information on the allow-list: Teams
  (`teams.microsoft.com`, `teams.live.com`), Meet (`meet.google.com`), Zoom (`zoom.us`, `*.zoom.us`, `zoomgov.com`,
  `*.zoomgov.com`), Jitsi (`meet.jit.si` + `jitsi_hosts`), Webex (`*.webex.com`); ≤ 2048 characters.
- At most 50 attendees, never the caller; task links only for tasks the caller can track (`TaskAccessCheckerInterface`).
- Rows with a malformed id, `end ≤ start`, longer than 24 h or outside the window are dropped (counted in the log).
- Sorted by `start`, then `id`; cut at `limit` (`truncated: true`).
- Headers on every answer, errors included: `Cache-Control: no-store` (Symfony adds `private`), `Pragma: no-cache`,
  `Vary: Authorization`, `X-Content-Type-Options: nosniff`; no `ETag` / `Last-Modified`.
- Logs: route action, status, count, dropped and duration only.

## Your privacy duties

Keep only the minimal fields (spec 210), honour private events, match internal attendees by verified e-mail and expose
only people the caller may already see (Q-B18), purge 7 days after the end, include the meetings in *Download my data*
and delete them on erasure and on disconnect. One OAuth grant serves calendar suggestions and meetings (Microsoft
`Calendars.ReadBasic` + `offline_access`, Google `calendar.events.readonly`); bump the consent version when the notice
lists both purposes.

## Clients

Poll no more often than `sync.refreshAfterSeconds` (≥ 60). Dedupe reminders by `id` + `start`. *Track the meeting*
uses `POST timer/switch {taskId, metadata: {origin: "meeting"}}` (no new endpoint, Q-B22). See
[CLIENT-API.md](CLIENT-API.md#get-memeetings-114).
