# Decisions

Each decision, and why. Made without stopping to ask, as the brief said.

## Scope

**1. File headers and `@since` tags everywhere.** The brief said Teme's code standards want no file headers and `@since` only on what Junction publishes. The person who started the work then said to use proper file headers, comments and `@since` tags. Their words come later and are more specific, so every PHP file has a header (package, author, copyright, licence, `@since 1.0.0`) and every class, method and constant has a docblock with `@since 1.0.0`. Teme neither reads nor checks `@since` in a plugin, so this costs nothing. Pint's `phpdoc_no_package` and `phpdoc_separation` are off in `pint.json` so formatting keeps the headers.

**2. A lean 1.0.0.** The person said to leave some things for future versions and make sure 1.0.0 is usable first. Chat formats, the field picker, category and tag filters, other plugins' events and the overview chart were all built and tested, then taken out before the 1.0.0 commit. The database columns for them stay (`format`, `fields`, `categories`, `tags`, `other_events`), so 1.1 and 1.2 need no migration and a row saved today means the same later. ROADMAP.md lists each one, where its code is, and what it waits on.

**3. JSON only in 1.0.0.** Make, Zapier, n8n and Pipedream all take JSON, and each can turn it into a chat message. That covers the chat apps until 1.1.

## How it works

**4. A hook writes one row; a job sends it.** A hook listener runs inside a page load, and a visitor's request has no person to run a job as (Teme's `Jobs::queue()` refuses without one), so listeners can't queue a job. Each listener writes a small row to `junction_events` and returns. A job every minute turns rows into deliveries and sends them. The cost is up to a minute before a delivery leaves (FINDINGS W6).

**5. Listeners write only when an active endpoint takes the event.** A site with no endpoints pays for one small query per hook and writes nothing.

**6. The payload is frozen when the delivery is made.** A retry or a resend sends what the receiver was first told, not what the item says now. A late retry can't leak a later draft edit.

**7. Retries keep the delivery id; a resend gets a new one.** Receivers de-duplicate on `X-Junction-Delivery` for retries. A resend makes a new delivery with a new delivery id but the same `event_id` in the body, so a receiver can still tell it is the same event.

**8. Waits and limits.** Waits are 1 minute, 5 minutes, 30 minutes, 2 hours, then 6 hours, with 6 tries by default (a setting, 1 to 10). An endpoint is paused after 10 failures in a row (a setting, 1 to 100). The log is kept 30 days (a setting, 1 to 365). A delivery to a host that isn't agreed to fails at once and isn't retried, since retrying can't fix it. Each call has a time limit from 1 to 10 seconds, which is the most the outbound door allows outside a long job.

**9. Nothing is sent for a draft.** An excerpt, author and terms are read only when the revision that is public is the one the hook named, and only for published and updated events. An unpublished, trashed or deleted item carries only what its hook gave. A comment's text and the person who wrote it are never read; Teme's hook doesn't carry them.

**10. Settings changes carry names only.** Teme's hook gives the group and the keys, never values, and Junction asks for nothing more.

## Signing

**11. `HMAC-SHA256(secret, timestamp + "." + body)`, sent as `sha256=<hex>`.** The same shape Stripe and others use, so receiver code is easy to find and to trust. The timestamp is in the signature so a captured delivery can't be replayed later; receivers refuse a timestamp more than 5 minutes old. Headers are `X-Junction-Signature`, `X-Junction-Timestamp`, `X-Junction-Delivery` and `X-Junction-Event`.

**12. Secrets.** Each endpoint's secret is made by Junction (`jcsec_` and 48 hex characters, 192 bits from the system's random source) and kept encrypted in its row with the site's own key. Teme's secret setting type can't hold one secret per endpoint, and area forms have no secret field (FINDINGS W9), so the secret is shown in plain text on the endpoint form, to people who hold Junction's manage permission, so they can copy it into the receiving app. If the site's key is lost, the secret reads as empty and signing fails loudly rather than sending an unsigned delivery. No real secret or address is in any file or test; tests use made-up values.

## Hosts

**13. Addresses are agreed in Settings, endpoints use agreed hosts.** Teme asks the administrator to agree to a new host when they save a `urls` setting named in `hostsFrom`. Area forms can't do that, so an endpoint's address must name a host already listed under Settings › Junction › Endpoint addresses, and the form says so when it doesn't. That is two steps for the administrator and always an explicit yes.

**14. No real host is agreed for the administrator; one placeholder is declared because Teme requires one.** Teme refuses to enable a plugin that asks for `http` with `hostsFrom` and no `hosts` (FINDINGS H1). Junction therefore declares one host, `junction.invalid`, a reserved name that never resolves, so it can never carry traffic. The administrator sees it on the consent screen and in the README; every real destination, Zapier's included, is agreed to by the administrator under Settings › Junction. The placeholder is never counted as an agreed endpoint host. The tests use it as their one host, since the kit can't agree to a new one (FINDINGS K1).

## Notices

**15. The paused notice goes to the person the job runs as.** A plugin can't list everyone who manages plugins (FINDINGS W2), so the notice kind is for people who manage plugins and is sent to the administrator who enabled Junction. Junction's Overview shows paused endpoints to everyone with access.

## Storage and tests

**16. Own tables only, through the query builder, in one place.** Four tables, all prefixed `junction_` and listed under `owns`; every query lives in the three classes under `src/Storage`, so a Laravel upgrade touches three files. Payloads are `json` columns, not `jsonb`, so key order is kept.

**17. Tests on PostgreSQL only.** Tests use Teme's testing kit against a Docker PostgreSQL 18 named `junction-postgres` on port 5462 and a Valkey named `junction-valkey` on 6392, database `junction_test`. I started both for this work, so they are Junction's alone; stop them with `docker stop junction-postgres junction-valkey`. Teme's own test containers weren't running.

**18. Calls to the area class are direct.** Teme's kit can't open or call a plugin's admin area (FINDINGS K2), so the area tests call `JunctionArea` as core would, with the same `AreaContext`. The hooks the kit can't fire (comments, media, scheduling, restore, delete) are tested through a fake `Hooks` that hands each listener a real hook object.

**19. The development site was not changed.** The brief said to pull Teme's latest `main` in `teme-dev` and add this folder to `TEME_PLUGIN_PATHS` only when no other session was using it. Other sessions were running against it throughout, so I did neither. Junction's tests run inside teme-dev's code without changing it. Trying Junction there is a step for REPORT.md.

**20. Line endings.** The files are written with LF and `.gitattributes` says so, so Windows checkouts don't churn.
