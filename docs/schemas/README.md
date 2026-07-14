# Dialectic JSON Schemas

These files define the versioned JSON contracts used between the Dialectic
xNVSE plugin and DialecticServer. Runtime code currently uses the `schema`
field mostly as a version marker; these files are the source-controlled
contracts for tests, docs, and future endpoint validation.

## Core Contracts

- `dialectic.event.v1.schema.json` - generic plugin event envelope sent to `main.php`.
- `dialectic.input.v1.schema.json` - player text/chat input payload.
- `dialectic.response.v1.schema.json` - server response envelope.
- `dialectic.response.line.v1.schema.json` - streamed `say` and `rolecommand` lines.
- `dialectic.command.v1.schema.json` - structured plugin command payload.
- `dialectic.action.v1.schema.json` - server-side action representation before emission.
- `dialectic.gamedata.v1.schema.json` - shared gamedata endpoint payload family.
- `dialectic.gamedata.response.v1.schema.json` - gamedata ACK/error response.
- `dialectic.media.v1.schema.json` - STT, voice sample, and player TTS media payloads.
- `dialectic.common.v1.schema.json` - shared actor/audience definitions.

## Versioning Rule

Only increment the `.vN` suffix when a contract change is not backward
compatible for the current plugin/server pair. Additive optional fields can stay
on the same version.
