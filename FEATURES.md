# Junction features

Each feature of the brief, marked:

- **Teme already does this**: Teme provides it, so Junction uses it and builds nothing.
- **Built**: Junction 1.0.0 does it.
- **Planned**: written and tested, then left out of 1.0.0 to keep it lean; it returns in the version named (see ROADMAP.md).
- **Can't yet**: Teme has no socket for it from outside; the missing socket is named, and FINDINGS.md has the detail.

## 1. Endpoints

| Feature | State |
|---|---|
| Junction's own area in the admin (Overview, Endpoints, Delivery log) | Built, through Teme's plugin-area socket |
| Add an endpoint: name, address, on or off | Built |
| Choose which events it receives | Built |
| Filter by content type | Built |
| Filter by category or tag | Planned, 1.1 |
| The administrator agrees to each outside host | Teme already does this (`hostsFrom`): an address is listed under Settings › Junction, where saving a new host asks for agreement. The endpoint form accepts only a host already agreed to. Teme's area forms can't take `hostsFrom` themselves, so that is two steps instead of one (FINDINGS W7). |
| A secret for each endpoint, made by Junction | Built |
| Pause, copy and delete endpoints | Built |

## 2. Events

| Event | State |
|---|---|
| An item published, updated, unpublished, scheduled, trashed, restored, deleted | Built |
| A comment received, a comment approved | Built |
| Media uploaded | Built |
| Settings changed, as group and key names only, never values | Built |
| Nothing fires inside a database transaction | Teme already does this: its hooks fire after commit |
| A person invited, a person joined | Can't yet: Teme has no hook for invitations or accepted accounts (FINDINGS W1) |
| Other plugins' events | Planned, 1.2. Written as a hook, `junction:CollectEvents`, that other plugins answer. Listening to every plugin's hooks, without naming each one, can't be done from outside (FINDINGS W3). |

## 3. Payloads

| Feature | State |
|---|---|
| JSON, documented for each event | Built (README.md) |
| An item's id, type, title, address, status, language, dates, terms | Built |
| An item's excerpt | Built: its excerpt field, or the first 55 words of its body |
| The author's name | Built for items that credit someone; otherwise `null`. Teme doesn't give the item's owner to plugins (FINDINGS W5). |
| The featured image address | Can't yet: no contract gives a media file's address, so `featured_image` is always `null` (FINDINGS W4) |
| Never sent: private fields, content of items readers can't see, passwords, readers' email addresses, secrets | Built, and tested |
| A field picker for each endpoint | Planned, 1.1 |
| Slack, Discord, Microsoft Teams and Google Chat formats | Planned, 1.1. Make, Zapier and n8n can turn the JSON into a chat message meanwhile. |

## 4. Signing

| Feature | State |
|---|---|
| A secret for each endpoint | Built, kept encrypted with the site's key |
| A timestamp and an HMAC-SHA256 signature on every delivery | Built |
| A delivery id, the same on every retry, so receivers can ignore duplicates | Built |
| Verification examples for Make, Zapier and n8n | Built (README.md) |

## 5. Delivery

| Feature | State |
|---|---|
| Queued jobs, never at page load | Built: a hook writes one small row; a job every minute sends it. Deliveries leave within about a minute (FINDINGS W6). |
| A time limit for each call | Built: 1 to 10 seconds, 10 by default, and never more than the outbound door allows |
| Retries with growing waits, up to a limit | Built: 1 minute, 5 minutes, 30 minutes, 2 hours, then 6 hours; 6 tries by default; the limit is a setting |
| Marked failed when the tries are used | Built |
| An endpoint paused after repeated failures | Built: 10 in a row by default; saving or turning on the endpoint starts it |
| A notice to administrators | Built through Teme's notices. It reaches the administrator the work runs as, since a plugin can't list everyone who manages plugins (FINDINGS W2). |
| Deliveries run as the administrator who enabled Junction | Teme already does this (scheduled jobs) |

## 6. The delivery log

| Feature | State |
|---|---|
| Each try with event, endpoint, status code, time and a short reply | Built |
| Resend a delivery | Built, as Duplicate on a log row: Teme's area has no custom row actions (FINDINGS W8) |
| Send a test event to an endpoint | Built: a button for every endpoint on, and a box on the endpoint form |
| Old entries pruned after a period the administrator sets | Built: 30 days by default, once a day |

## 7. The other direction

| Feature | State |
|---|---|
| Apps into Teme | Teme already does this: the management API at `/api/manage/v1`. Junction builds no inbound webhook. The README shows how a Make or Zapier scenario uses it. |

## 8. Import from WordPress

| Feature | State |
|---|---|
| Read a WordPress webhook plugin's settings during an import | Later: recorded in ROADMAP.md, not needed for 1.0.0 |

## Left for later versions

See ROADMAP.md for what is planned and what each item waits on. The overview chart (delivered and failed each day) is also planned; 1.0.0 shows the same totals as figures.
