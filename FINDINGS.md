# Findings

Where Teme's handbook was missing, wrong or unclear (**H**), where its testing kit fell short (**K**), and every missing socket, a "wall" (**W**). Each says what I expected and what I did instead. Teme's code was not changed to work around any of them.

Found by building against `teme-dev` at Teme `main` (contracts 1.0.0, the site reporting 0.8.0-alpha.1).

## Handbook

**H1. `hostsFrom` alone isn't enough to enable a plugin.** *Expected:* the handbook's Plugin settings and secrets page says a plugin that lets people type in the service's address lists the setting under `hostsFrom` and needs the `http` capability. *Happened:* enabling failed with "It asks for http but names no hosts." *Did:* named one real host, `hooks.zapier.com`, under `hosts` as well. *Suggest:* either accept `hostsFrom` alone, or say that `hosts` must be non-empty.

**H2. The area's block rules aren't in the guide.** *Expected:* the handbook's A plugin's own area page lists the blocks and what each declares. *Happened:* Teme refused the manifest for two things the guide doesn't say: every block needs an `id` of 2 to 40 lower-case letters, digits and hyphens starting with a letter, and a figures block holds 1 to 4 figures. *Did:* added ids, and split five figures into two blocks. *Suggest:* put both rules in the guide.

**H3. The site's version blocks a `^1.0` plugin, and the fix depends on a `.env` line.** *Expected:* the handbook's Set up a development site page says an untagged `main` reports 0.1.0 and to set `TEME_VERSION`. *Happened:* teme-dev's `.env` already says `TEME_VERSION=0.8.0-alpha.1`, which the kit respects, so a plugin requiring `^1.0` was refused in tests too ("this is Teme 0.8.0-alpha.1"). *Did:* set `TEME_VERSION=1.0.0` in Junction's own `phpunit.xml`. *Suggest:* say in the testing guide that the kit's version fallback applies only to an untagged checkout, so a `.env` value wins.

**H4. The test command in the guide isn't the one that worked.** *Expected:* the handbook's Testing a plugin or theme page says `php artisan test ../my-plugin/tests`. *Did:* ran Pest from teme-dev with Junction's own `phpunit.xml` (`php ../teme-dev/vendor/bin/pest --configuration=phpunit.xml`), because I needed my own database and bootstrap. I did not try `artisan test`, so this is a gap in what I checked, not a proven fault. Two things I did find: a plugin's `tests/Pest.php` wasn't loaded this way, and `uses(...)->in('Feature')` didn't match on Windows paths, so each test file names the kit's test case itself, as the guide's first-line rule says. *Suggest:* say how a plugin runs against a test database of its own.

**H5. The notice button's route parameter isn't named.** *Expected:* the handbook's Notices from plugins page says a button may lead to `plugins.area.show` with the plugin's own code. *Happened:* it doesn't say what the parameter is called. *Did:* guessed `code`. *Unverified:* the notice is sent inside a try/catch, so a wrong name would be swallowed. It is on ROADMAP.md's task list to check on the dev site.

**H6. An area form's `terms` field holds term IDs; the contracts speak slugs.** *Expected:* a way to pick categories and tags in a form and compare them with an item's terms. *Happened:* `ItemSnapshot::$terms` is slugs by taxonomy; the area's `terms` field stores IDs, and no contract maps one to the other. *Did:* in 1.0.0 there is no category or tag filter (planned, 1.1, ROADMAP.md).

**H7. The code standards page and the brief disagree with the person who started this work.** The handbook's the handbook's Code standards page recommends no file headers and `@since` only for what a package publishes. The instruction was to use headers and `@since` everywhere. Nothing is wrong in the handbook; recorded so the choice is traceable (DECISIONS 1).

## Testing kit

**K1. `changeSettings()` can't agree to a new host.** *Expected:* saving a plugin's `urls` setting, as an administrator does, with a way to say yes. *Happened:* the save was refused with "Agree to let Junction connect to hooks.example.com, or remove them." and the kit has no argument to tick the box. *Did:* all tests use the one host the manifest names, which is agreed at enable. So there is no test of the real agree step for a new host.

**K2. A plugin's area can't be reached from a test.** The kit has no way to open an area page or call the area as core would. *Did:* called `JunctionArea` directly (DECISIONS 18). Core's checks on top of it (permissions, the manifest's field types and limits) aren't covered by Junction's tests.

**K3. Only content hooks can be fired.** The kit has `publish`, `unpublish` and `trash`. Comments, media, scheduling, restore and delete have no helper. *Did:* hand real hook objects to Junction's listeners through a fake `Hooks`. That tests Junction's mapping, not that Teme fires those hooks when it should.

## Walls

**W1. No hook for a person invited or joined.** The brief wanted both events. Teme has no such hook, so Junction sends neither.

**W2. A plugin can't list the people who manage plugins.** The notice kind reaches "people who manage plugins", but `Notices::send()` takes account IDs and `PeopleReader` lists writers only. Junction sends to the one person its job runs as.

**W3. A plugin can't listen to every other plugin's hooks.** Teme's own-hooks socket needs the listener to require the plugin it listens to and name each hook, so Junction can't carry "any hook another plugin declares" without each plugin being named in its manifest. *Did, then deferred:* a pull hook, `junction:CollectEvents`, that other plugins answer once a minute. It works and was tested, and returns in 1.2 (ROADMAP.md).

**W4. No media file address, so no featured image.** `MediaRef` has an id, kind, type, file name, title, size and dimensions, but no address, and `ItemSnapshot` names no featured image. `featured_image` is always `null`, and a media event carries no address.

**W5. The item's owner isn't given to plugins.** `ItemSnapshot::$credits` is empty when an item credits only its owner, so the author's name is `null` for most items.

**W6. A hook listener can't queue a job for a visitor.** `Jobs::queue()` refuses with no signed-in person, and most hooks fire in a visitor's request. *Did:* the outbox and an every-minute job (DECISIONS 4). Deliveries wait up to a minute.

**W7. An area form can't take the host agreement.** `hostsFrom` works for settings, not for a record's `url` field in an area, so adding an endpoint is two steps (DECISIONS 13).

**W8. An area's rows have only five built-in actions.** Edit, duplicate, enable, disable and delete. Junction wanted "Resend" and "Send test". *Did:* Duplicate on a log row resends it, and a box on the endpoint form sends a test.

**W9. An area form has no secret field.** Junction's per-endpoint secret is a text field, readable by anyone who holds the manage permission (DECISIONS 12). A write-only secret field would let the form show "set" and never the value.
