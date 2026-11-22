# Report: Junction 1.0.0 (interim)

`krafty-sprouts/junction` 1.0.0, a TemeCMS plugin that sends the site's events, signed, to Make, Zapier, n8n, Pipedream or any webhook address. Author: Krafty Sprouts Media, LLC. Built against `C:\Users\kings\Downloads\Projects\teme-plugins\teme-dev` (Teme `main`, contracts 1.0.0) using only the handbook, the generated references, `teme/contracts` and `teme/testing`.

**This is an interim report. 1.0.0 is not ready to ship: four things are still to do, listed first.** Nothing dangerous or irreversible came up; no file outside this folder was changed, and `teme-dev` was not touched.

## Where work stopped

Still to do before 1.0.0, in this order:

1. **Show a secret once** (asked for by the maintainers' architect, DECISIONS 12). Today the endpoint form shows an endpoint's secret every time it is opened. It should show it once, when the endpoint is created or its secret is made again, and afterwards only that a secret is set, with a "make a new secret" box. Not started.
2. **README.md**: setup, verifying signatures with examples for Make, Zapier and n8n, the events and their payloads, privacy (what is never sent), what the administrator sees when enabling (including the placeholder host `junction.invalid`), and how a Make or Zapier scenario writes to Teme through the management API. FEATURES.md already points at it, so those links are broken until it exists. Not started.
3. **CHANGELOG.md** (1.0.0) and **CONTRIBUTING.md** (notes for maintainers). Not started. The changelog must list the auto-pause, media-uploaded and settings-changed events honestly as part of 1.0.0.
4. **The final REPORT.md**: totals after the last run, and the steps for trying Junction on `teme-dev` with a free test endpoint.

Not yet checked, and needing the dev site (ROADMAP.md tasks): that Junction enables and sends on the real site, that its admin area looks right in a browser, and that the paused-endpoint notice arrives (FINDINGS K4).

## Tests

Last full run, before the notes were last edited: **43 passed, 0 failed, 147 assertions**, on PostgreSQL 18. PHPStan level 6: no errors. Pint: clean. All run from this folder:

```powershell
cd C:\Users\kings\Downloads\Projects\teme-plugins\junction
php ..\teme-dev\vendor\bin\pest --configuration=phpunit.xml
```

The tests need a PostgreSQL on port 5462 with a `junction_test` database and a Valkey on 6392 (`phpunit.xml`). I started them as Docker containers `junction-postgres` and `junction-valkey`; they were not running at the last check, so start them first. No test touches a real address or a real secret; the one host is the placeholder `junction.invalid`, which the kit's fake answers.

## What works (built and tested)

- Endpoints in Junction's own admin area: name, address, events, content-type filter, on or off, copy, delete.
- The administrator agrees to every real host: addresses are listed under Settings › Junction, where Teme asks for the agreement. No real host is agreed in advance.
- Events: an item published, updated, unpublished, scheduled, trashed, restored, deleted; a comment received or approved; media uploaded; settings changed (group and key names only).
- JSON payloads, never including drafts' content, passwords, readers' emails, comment text or secrets.
- Signing: a secret for each endpoint, a timestamp, an HMAC-SHA256 signature and a delivery id.
- Delivery as queued jobs, never at page load: a time limit per call, retries with growing waits, failing for good after the tries, and pausing an endpoint after repeated failures.
- A delivery log, resend, a test event, and pruning.

## What doesn't, and why

- **A person invited or joined**: Teme has no hook (FINDINGS W1).
- **Featured image address**: no contract gives a media address; the field is always `null` (W4).
- **Author when nobody is credited**: Teme doesn't give plugins the item's owner (W5).
- **A notice reaching every plugin manager**: a plugin can't list them (W2). The notice goes to the administrator the job runs as, and it isn't verified to arrive (K4).
- **Within-seconds delivery**: a hook can't queue a job for a visitor, so deliveries leave within about a minute (W6).
- **Everything in ROADMAP.md**: chat formats, a field picker, category and tag filters, other plugins' events, the overview chart and the WordPress import were left for later versions on purpose.

## Decisions to look at

- DECISIONS 1: file headers and `@since` everywhere, against the handbook's recommendation, on your instruction.
- DECISIONS 14 and FINDINGS H1: Teme forces one fixed host at enable, so Junction declares an unreachable placeholder, `junction.invalid`; every real host is agreed by the administrator.
- DECISIONS 12 and FINDINGS W9: the secret is shown on the form until item 1 above is done.

## Files

FEATURES.md (each brief feature and its state), ROADMAP.md (what is left for later, and open tasks), DECISIONS.md, FINDINGS.md (handbook gaps H1–H7, kit gaps K1–K4, walls W1–W9), this report.
