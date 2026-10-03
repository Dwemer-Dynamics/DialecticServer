# Addon message API (server side)

The Dialectic client (addon API level 3) adds three addon commands. This page
covers what the server receives; the client contract is in the client's
`docs/XNVSE_EVENT_API.md`.

| Client command | Event type | Server handling |
| --- | --- | --- |
| `DialecticSendAddonMessage` | `inputtext` / `inputtext_s` (`dialectic.input.v1`) | Normal player-input turn. `addon_message.mode` sets the mode for this request only |
| `DialecticRequestAddonReaction` | `external_reaction` (`dialectic.external_request.v1`) | Unchanged: the existing reaction path. Explicit or eligible is decided by the client |
| `DialecticSendAddonContext` | `pluginevent` (`dialectic.addon_context.v1`) | Logged as an `infoaction` context line after `prerequest.php`, then ends without a model call |

## Request-scoped mode

An addon message carries `"addon_message":{"mode":"STANDARD"|"WHISPER"|"SHOUT"}`
and omits `dialectic_mode`/`mode`. `processor/dialectic_modes.php` sets
`$EXECUTION_MODE` from it and does not read or write `conf_opts.dialectic_mode`,
so the stored global mode, the next request and concurrent requests are
unaffected. Validation is strict and case-sensitive; any other value, or a
malformed `addon_message`, falls back to `STANDARD`. Requests without
`addon_message` follow the existing path unchanged.

Only per-request server behavior follows the mode: the whisper/shout tag
conversion and prompt instruction and the private-conversation helpers.
Rechat and narration requests carry no addon mode, so the server still gates
them on the configured global mode (`DIALECTIC_CONFIGURED_EXECUTION_MODE`).
The client keeps the addon mode for the turn's runtime generation: it applies
it to the initial listener radius, audience, privacy and player-turn audience,
and suppresses automatic rechat after an addon whisper turn, so a whisper turn
never reaches the server's rechat gate. A configured Whisper mode still
suppresses rechat after a standard or shout addon turn. Client playback volume
is spatial in every mode, so an addon "whisper" is a private, target-only turn
with a whisper prompt, not a whisper voice effect.

The client refuses an addon message while any dialogue line, TTS or HTTP
request is in flight, so an addon message never interrupts or cancels the
current conversation request.

Servers without this change ignore `addon_message` and apply their stored mode.

## Addon context

```json
{"schema":"dialectic.addon_context.v1","bridge":"ParityProbe","type":"state","name":"quest.stage","text":"Stage 20","actor":"Veronica","actor_refid":"0x000E32A9"}
```

`bridge` is 1 to 32 letters or digits starting with a letter; `type` and `name`
are 1 to 64 letters, digits, `_`, `.` or `-`; `text` is 1 to 1000 bytes. The
server logs `(Addon <bridge> <type> <name> about <actor>: <text>)` as an
`infoaction` event, with `|` and `@` replaced and whitespace collapsed. Invalid
payloads and plain `dialectic.plugin_event.v1` events are not logged. Both still
reach `prerequest.php` observers. No tables, settings or provider calls are
added.
