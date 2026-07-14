# Dialectic JSON Transfer Contract

Dialectic no longer supports old delimiter-based game envelopes or line-based
response records. The xNVSE plugin and server communicate with JSON only.

Formal contract files live in `docs/schemas/`. Keep the schema file and the
runtime `schema` value in sync whenever a payload shape changes.

## Event Request

Game events are sent as `POST` requests with `Content-Type: application/json`.

```json
{
  "schema": "dialectic.event.v1",
  "type": "inputtext",
  "ts": 1781315857,
  "gamets": 1781315857,
  "game": "fnv",
  "player": {
    "name": "Courier",
    "refid": "0x00000014"
  },
  "speaker": {
    "name": "Courier",
    "refid": "0x00000014"
  },
  "target": {
    "name": "NCR Ranger",
    "refid": "0x00174039",
    "baseid": "0x000f43de"
  },
  "text": "hello my man",
  "people": [
    {
      "name": "NCR Ranger",
      "refid": "0x00174039",
      "baseid": "0x000f43de",
      "audible": true,
      "distance": 312.4,
      "source": "spatial_audio"
    }
  ],
  "flags": {
    "manual_activation": true,
    "auto_managed": false
  }
}
```

## Event Response

Server responses use `dialectic.response.v1`.

```json
{
  "schema": "dialectic.response.v1",
  "ok": true,
  "lines": [
    {
      "speaker": "NCR Ranger",
      "action": "say",
      "text": "Keep your eyes open out here.",
      "subtitle": "Keep your eyes open out here.",
      "tts_text": "Keep your eyes open out here.",
      "metadata": {
        "expression": "default",
        "listener": "Courier",
        "animation": "IdleDialogueExpressiveStart",
        "phonetic": "",
        "volume": 1.0,
        "rechat_target": "Courier",
        "utterance_id": "..."
      }
    }
  ],
  "close": false
}
```

`action: "rolecommand"` is also structured:

```json
{
  "schema": "dialectic.response.line.v1",
  "speaker": "The Narrator",
  "action": "rolecommand",
  "text": "DebugNotification",
  "command": "DebugNotification",
  "command_name": "DebugNotification",
  "command_args": ["Diary Entry Written for Courier"]
}
```

## Actor And Gameplay Metadata

Runtime metadata belongs in JSON payloads, not delimiter strings:

- `gamedata.php` accepts JSON for inventory, equipment, stats, voice metadata,
  skills, activity, transformation, market stock, and plugin manifests.
- `stt.php` accepts microphone audio as `multipart/form-data` with the WAV in
  `file` and all game metadata in a JSON `metadata` part using
  `schema: "dialectic.stt.v1"`. Responses use `dialectic.stt.response.v1`.
- `vsx.php` accepts voice uploads as `multipart/form-data` with the binary
  sample in `file` and all game metadata in a JSON `metadata` part using
  `schema: "dialectic.voice_sample.v1"`.
- Processor events such as `funcret`, `setconf`, `captured_dialogue`, and
  `updateprofiles_batch_async` carry JSON payloads in the event `text` field.
  Non-JSON payloads are rejected or ignored instead of parsed as fallback text.
- `addnpc` and actor snapshot requests should carry structured actor fields for
  identity, SPECIAL, skills, equipment, health/AP/XP/karma, factions,
  reputation, voice data, and profile hints.
- Nearby people and audience data should be JSON actor arrays. Internal fallback
  text fields may exist for prompt/search compatibility, but they must be
  derived from the JSON source instead of transferred as the source of truth.
