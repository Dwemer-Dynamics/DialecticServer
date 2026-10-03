# Plugin parity example

Two tiny server plugins plus a CLI probe for DialecticServer's CHIM-style
extension hooks. Nothing here is loaded automatically: the plugins live outside
`ext/` and the probe uses a scratch copy. See
[plugin runtime](../../docs/plugin-runtime.md) for the hook contract.

| File | Demonstrates |
| --- | --- |
| `ext/parity_probe/globals.php` | `chimRegisterPromptInjection()` (`character_bottom`, `prompt_bottom`), `chimRegisterActorProfileEnricher()`, `dialecticRegisterExtensionAction('ExtCmdParityProbe_Ping', …)` |
| `ext/parity_probe/context.php` | Context-stage marker in `$GLOBALS['PLUGIN_PARITY_TRACE']` |
| `ext/parity_probe/prerequest.php` | Observer for the Dialectic client's `funcret` (`dialectic.action_result.v1`) and `pluginevent` (`dialectic.plugin_event.v1`, bridge `ParityProbe`) events |
| `ext/parity_probe_order/globals.php` | Second plugin: load order versus injection priority |
| `probe.php` | Real loader, include-once, exclusions, prompt API, `functions.php` catalog, `json_response.php` schema and action dispatch |

No provider, database, network or game client is used.

## Run the probe

From the server source root:

```sh
php examples/plugin-parity/probe.php
```

It creates a temporary `ext/` with the example plugins and fixtures (disabled,
hidden, `private/`, `staging/`, root-level, failing, `relationship_system` and,
where the OS permits, symlinked plugins), prints `PASS`/`FAIL` lines, removes
the temporary directory and exits non-zero on any failure. Logger output and
`error_log()` go to the scratch directory. Pass
`--scratch=<new-or-empty-dir>` to keep the fixture tree and logs. On Windows
without symlink privilege the symlink fixture is reported as skipped; run under
Linux/WSL to cover it.

The probe loads the real `functions/functions.php` and `functions/json_response.php`
with no database configured. It narrows enabled actions to `Attack` plus the
probe's actions because `GiveCapsTo` reads caps from the database while the
action list is built.

## Build the package

`build_package.php` turns `ext/parity_probe/` into a schema-4 server package
(`manifest.json` named `parity_probe`, `checksums.sha256`, files under
`server/`). Entries are sorted and timestamped at 1980-01-01, so the same source
and version produce identical bytes. It needs PHP's `zip` extension:

```sh
php examples/plugin-parity/build_package.php /tmp/parity_probe-1.0.0.dwpkg 1.0.0
```

Do not commit built archives. For Dialectic's game-side sync, place the archive
in the client addon mod as
`Data/Dialectic/server-plugins/parity_probe/1.0.0.dwpkg`. The folder name must
match the manifest `name`, and the file stem must match `version`.
`parity_probe_order` is a probe fixture and is not packaged.

Action codes must match `ExtCmd<Bridge>_<Action>`, where both the bridge and
the action start with a letter and contain only ASCII letters and digits (64
characters at most). The Dialectic client also accepts bridges that begin with
a digit; the server does not register them, so use a leading letter.

## Install on a disposable test server

Use an isolated server and database, never the live playthrough.

1. Install the package from the Server Plugins page, through Dialectic's
   package sync, or by copying `ext/parity_probe/` into the test server's
   `ext/` directory. Preserve other extensions.
2. Send a dialogue turn with actions enabled. The system prompt gains
   `<parity_probe>…</parity_probe>` inside `<character>` and a
   `<parity_probe_footer>` line; with the nearby-actors custom-state context
   option enabled, NPC entries gain `Parity probe sees <name>`.
   `ExtCmdParityProbe_Ping` appears in the available actions and the
   structured `action` enum.
3. If the model selects it, the response contains a `rolecommand` line with
   `command_name` `ExtCmdParityProbe_Ping` and the speaker's `speaker_refid`.
   With the Dialectic client's ParityProbe script, its `funcret` result makes
   the observer log `[parity_probe] completion: completed Pong…`, and its
   `state`/`ping` plugin events reach `prerequest.php`.
4. Disable without deleting by creating `ext/parity_probe/.disabled`, or remove
   the directory. Restore the server's `ext/` afterwards.

## Not verified by the probe

The probe does not run `main.php` or `main_dialectic_pipeline.php`, call a
model, write the database, or start Fallout. A separate paired check on a
disposable database has installed the built package through the package API,
run a dialogue turn against a stub model that selects the action, validated the
exact response-line `speaker_refid`, and posted client-shaped `funcret` and
`pluginevent` JSON to `main.php`; its current artifacts pass 24/24. A real
model's choice, native client `ExtCmd` dispatch and in-game results remain
live-test items. The game itself has not been run.
Registered codes have no catalog follow-up configuration, so `funcret` is
logged without a follow-up model call.
