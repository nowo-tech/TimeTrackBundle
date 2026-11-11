# Meetings API (host guide, TimeTrack 1.14)

`GET /api/time-track/me/meetings` gives TimeTrack clients (TimeTrack Desktop ≥ 0.6 reminders) the caller's **own**
upcoming meetings, from **your** meeting read model. Contract: `timetrack-next` v1.1 §22; Beacon spec 210.

## Table of contents

- [Who owns what](#who-owns-what)
- [Enable it](#enable-it)
- [Implement the provider](#implement-the-provider)
- [What the bundle enforces on the way out](#what-the-bundle-enforces-on-the-way-out)
- [Reading the calendars in your sync](#reading-the-calendars-in-your-sync)
  - [Scopes](#scopes)
  - [OAuth helpers](#oauth-helpers)
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

## Reading the calendars in your sync

Since 1.19 (Beacon request 2026-11-09) your sync does not need its own Google / Graph readers. Inject
`Nowo\TimeTrackBundle\Calendar\GoogleCalendarEventSource` and `MicrosoftCalendarEventSource` (both
`CalendarReaderInterface`, registered even with `calendar.enabled: false`) and give them a `CalendarCredential` built
from your stored access token:

```php
$credential = new CalendarCredential($accessToken);            // never stored, logged or serialized by the bundle

foreach ($reader->calendars($credential) as $calendar) {       // CalendarDescriptor {id, name, primary}
    // mirror your calendars switch; the primary one on by default. Store these ids per connection and read only them.
}

$batch = $reader->meetings($credential, $from, $to, $calendar->id);   // CalendarMeetingBatch
foreach ($batch->meetings as $meeting) {                       // CalendarMeetingView, in memory only
    // properties: ->start / ->end, ->timeZone (IANA or null), ->allDay, ->cancelled, ->declined, ->free, ->private,
    //   ->organizerSelf
    // methods (ids and personal data, never printed by json_encode / logs / dumps):
    //   ->eventId(), ->seriesId(), ->originalStart() (stable occurrence id), ->organizerEmail(),
    //   ->attendees() (CalendarAttendee: ->email(), ->self, ->declined, ->resource — match and drop),
    //   ->joinCandidates() (raw links: validate them, as MeetingLinkDetector does on the way out),
    //   ->title(), ->description(), ->location() (raw provider text; read once for task keys and links, never keep)
}
if (!$batch->complete) {
    // the page cap (calendar.max_pages) stopped the read: keep the stored meetings you did not see
}
```

- Non-meeting entries are skipped: Google event types other than `default` (focus time, out of office, working
  location, birthdays, Gmail reservations) and Graph series masters. Declined and cancelled occurrences come back
  flagged, so you can remove them from your model.
- Graph gives no per-attendee `self` flag (always false) and often a Windows zone name in `originalStartTimeZone`:
  `timeZone` is then null. Times are UTC instants.
- `events($credential, $from, $to, $calendarId)` gives the narrower suggestion fields of any calendar.
- **Pass only calendar ids that `calendars()` returned for the same connection** (audit LZ-I2). A Google token can
  read any calendar the user can see (shared, public, another person's address), so an id taken from a request could
  read more than the user switched on. Ids made only of dots are refused.
- **Escape before display** (audit LZ-I8): title, description and location are raw provider text (a Google
  description may hold HTML), and join links are raw candidates. The bundle never renders them.
- Errors are `CalendarSyncException` codes (`token_expired`, `rate_limited`, `forbidden`, `provider_error`,
  `invalid_response`, `too_large`, `no_http_client`; since 1.20 `not_configured` from the OAuth helpers), never
  provider messages. A bad calendar id (empty, > 512 bytes,
  control characters) throws `InvalidArgumentException`.
- `CalendarMeetingView`, `CalendarAttendee` and `CalendarMeetingBatch` keep ids and personal data behind methods:
  `json_encode()`, casts, `var_export()`, `var_dump()` / `print_r()`, Symfony VarDumper (`dump()`, the dump panel),
  Symfony Serializer and Monolog see `[redacted]`, and serialization
  throws (audit LZ-L1). Build your read model from them inside the sync and keep only your minimal fields.

### Scopes

| Provider | Scopes | What it gives |
| --- | --- | --- |
| Google | `calendar.events.readonly` | events and meetings of any calendar the user can read |
| Google | `calendar.calendarlist.readonly` | `calendars()` (new for grants made only for suggestions) |
| Microsoft | `offline_access` + `Calendars.ReadBasic` (default `calendar.microsoft_scope`) | everything but the body: task keys from **titles only**; the meeting `description` is empty |
| Microsoft | `offline_access` + `Calendars.Read` (`calendar.microsoft_scope: Calendars.Read`) | also the body as text, for task keys in the description (Beacon spec 210 links a meeting to a task by the key in its description) |

`calendar.microsoft_scope` drives both the reader (`body` is selected only with `Calendars.Read`) and the OAuth helper.
Changing it does not widen existing grants: bump your consent version so users reconnect.

### OAuth helpers

Optional: `GoogleCalendarOAuthClient` and `MicrosoftCalendarOAuthClient` (`CalendarOAuthClientInterface`), configured
under `calendar.oauth` (empty client id or secret = `isConfigured()` false: hide the provider). Since 1.20 the apps can
come from your database at runtime (`calendar.oauth.app_provider`, read on every call; without an app the token calls
fail with `not_configured`, Google `revoke()` still works): see [USAGE.md](USAGE.md#calendar-oauth-apps-at-runtime-120).

```php
$pkce  = CalendarPkce::create();
$state = CalendarOAuthState::create();                           // keep $pkce->verifier() and $state in the session, bound to the user
$url   = $oauth->authorizationUrl($redirectUri, $state, $pkce->challenge);  // https redirect URI, no fragment (http only on loopback)

// callback: one use only, then drop both from the session
if (!CalendarOAuthState::matches($session->get('calendar_state'), $request->query->get('state'))) {
    throw new AccessDeniedHttpException();
}
$tokens = $oauth->exchangeCode($code, $verifier, $redirectUri); // CalendarTokens: ->accessToken(), ->refreshToken(), ->expiresAt
// store the tokens encrypted; later:
$tokens = $oauth->refresh($refreshToken);                       // token_expired when the grant is gone: reconnect
$oauth->revoke($token);                                         // best effort, never throws
```

- Token calls are `POST`s to constant URLs (10 s, no redirects, 64 KB at most); `invalid_grant` becomes
  `token_expired`, any other refusal `provider_error`. `expiresAt` is one minute early.
- Google sends no new refresh token on refresh (keep the stored one); Microsoft rotates it (store the new one).
- **Microsoft Graph has no per-grant revoke** (`revokeSignInSessions` would sign the user out everywhere):
  `supportsRevoke()` is false and `revoke()` returns false. Delete your tokens and tell the user to remove the app at
  https://myapps.microsoft.com.
- `authorizationUrl()` refuses a state shorter than 32 unreserved characters (use `CalendarOAuthState::create()`) and a
  redirect URI with a fragment.
- `CalendarTokens` and `CalendarPkce` keep the secrets behind `accessToken()` / `refreshToken()` / `verifier()`:
  `json_encode()`, casts, `var_export()`, `var_dump()` / `print_r()`, VarDumper, Serializer and log formatters see
  `[redacted]`, and serialization
  throws. Store the strings, not the objects.

With the readers and the helpers, a host keeps only its token store (`CalendarCredentialProviderInterface`), its
meeting normalizer (attendee matching, minimal fields) and a test stub; its copied parsing can go.

## Your privacy duties

Keep only the minimal fields (spec 210), honour private events, match internal attendees by verified e-mail and expose
only people the caller may already see (Q-B18), purge 7 days after the end, include the meetings in *Download my data*
and delete them on erasure and on disconnect. One OAuth grant serves calendar suggestions and meetings (Microsoft
`Calendars.ReadBasic` or `Calendars.Read`, plus `offline_access`; Google `calendar.events.readonly` and, for the
calendars switch, `calendar.calendarlist.readonly`); bump the consent version when the notice lists both purposes.

## Clients

Poll no more often than `sync.refreshAfterSeconds` (≥ 60). Dedupe reminders by `id` + `start`. *Track the meeting*
uses `POST timer/switch {taskId, metadata: {origin: "meeting"}}` (no new endpoint, Q-B22). See
[CLIENT-API.md](CLIENT-API.md#get-memeetings-114).
