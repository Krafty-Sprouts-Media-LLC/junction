# Junction roadmap

What 1.0.0 leaves for later, and why. 1.0.0 is a complete, usable webhook plugin on its own: an administrator adds endpoints, picks events, and receives signed JSON deliveries, with retries, pausing, a log, resend and test events. Everything below adds to that; none of it is needed to use it.

Each item says what it is, where its code is if it was already written, and what it waits on. Everything marked "already written" was built and tested before the 1.0.0 scope was cut, and is whole in commit `f85f8d5`: bringing it back starts from working, tested code, not a rewrite.

## Planned for 1.1

### Chat app formats

A simple message in the shape Slack, Discord, Microsoft Teams (Adaptive Card) and Google Chat incoming webhooks take, chosen per endpoint. JSON stays the default.

- Already written and tested; removed before 1.0.0 to keep the first release small. Restore `src/Payload/ChatFormat.php`, the endpoint form's `format` field and table column, and its tests from commit `f85f8d5`.
- The `format` column is already in `junction_endpoints` (always `json` in 1.0.0), so 1.1 needs no migration.
- Until then, a chat app can be reached through Make, Zapier or n8n, which turn the JSON into a message.

### A field picker per endpoint

Choose which item fields an endpoint receives, to send less.

- Already written and tested (`PayloadBuilder::pick()`); removed before 1.0.0. The `fields` column is already in the table.

### Category and tag filters

Limit an endpoint to items in some categories or with some tags. 1.0.0 keeps the content-type filter.

- Already written and tested; removed before 1.0.0. The `categories` and `tags` columns are already in the table.
- Known limit to fix first: Teme's area `terms` field holds term IDs while `ItemSnapshot` gives slugs, so the 1.0.0-era form took slugs as typed text (FINDINGS.md). Use a real term picker once Teme offers one that answers slugs, or a reader that maps IDs to slugs.

## Planned for 1.2

### Other plugins' events

A hook other plugins listen to, `junction:CollectEvents`, so their own events travel to the endpoints that take them.

- Already written and tested, with an add-on test fixture; removed before 1.0.0 because it is Junction's one public API, and a pull-once-a-minute hook is a workaround (FINDINGS.md, wall W3). Ship it when Teme lets a plugin hear any plugin's declared hooks, or keep the pull design and document it as stable.

### An overview chart

Delivered and failed deliveries for each of the last 14 days. 1.0.0 shows the same numbers as figures.

- Already written and tested (`DeliveryStore::daily()`, `JunctionArea::chart()`); removed before 1.0.0.

## Waiting on Teme

These can't be built from outside Teme yet. Each is a wall in FINDINGS.md, with what Junction does meanwhile.

| Feature | What Teme needs to offer | Meanwhile |
|---|---|---|
| A person invited, a person joined | Hooks for invitations and accepted accounts | Not sent |
| Featured image address, media file address | A media reader that answers a file's public address | `featured_image` is always `null`; `media.uploaded` has no address |
| The author when nobody is credited | The item's owner in `ItemSnapshot` | `author` is `null` unless the item credits someone |
| Every plugin-manager gets the "paused" notice | A way to list the accounts that manage plugins | The notice goes to the administrator who enabled Junction |
| Endpoints' hosts agreed from the endpoint form | `hostsFrom` for an area record's `url` field | Addresses are listed under Settings › Junction first |
| A per-row "Resend" or "Send test" action | Custom row actions in a plugin's area | Duplicate on a log row resends; a box on the endpoint form sends a test |
| Sending within seconds | A job queued from a hook listener for a visitor's request | Sent within a minute, by the every-minute schedule |

## Before 1.0.0 ships

- [ ] Show an endpoint's secret once, when it is created or made again, then only "set" with a "make a new secret" box (DECISIONS 12).
- [ ] README.md, CHANGELOG.md (1.0.0) and CONTRIBUTING.md; then the final REPORT.md (see its "Where work stopped").

## Tasks

- [ ] Try Junction on the development site once no other session is changing it: add its folder to `TEME_PLUGIN_PATHS`, enable it with `--as`, run `schedule:work` and `queue:work`, send a test to a free endpoint (README.md).
- [ ] Read the admin area in a browser: the tests call `JunctionArea` directly, since Teme's testing kit can't open a plugin's area.
- [ ] Drop the placeholder `junction.invalid` from `hosts` when Teme accepts `hostsFrom` alone (FINDINGS H1).
- [ ] **Check the paused-endpoint notice on the dev site**: pause an endpoint (point it at an address that answers 500), then look for the notice in the administrator's inbox and mail. If it doesn't arrive, find out why (FINDINGS K4); if it does, follow its "Open Junction" button (FINDINGS H5).
- [ ] Translations: copy `lang/en.json` to `lang/<locale>.json` and translate the values.
- [ ] WordPress import: read a WordPress webhook plugin's settings during an import and offer its addresses as endpoints (the brief marked this as not needed for 1.0.0).
