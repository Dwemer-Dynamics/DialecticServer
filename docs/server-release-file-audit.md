# DialecticServer Release File Audit

Date: 2026-07-13

This audit classifies the tracked DialecticServer tree by runtime reachability
and checks the deployed WSL tree for stale source files.

## Runtime Entry Points

- `main.php`: JSON/NDJSON dialogue and event endpoint.
- `gamedata.php`: structured world, actor, inventory, quest, and telemetry data.
- `csv_import.php`: automatic NPC and description CSV import endpoint.
- `stt.php`: microphone audio transcription endpoint.
- `vsx.php`: NPC voice-sample upload and clone preparation endpoint.
- `player_rewrite.php`: internal player respeech worker.
- `ui/`: configuration, profiles, memories, diagnostics, and maintenance pages.

## Dynamically Loaded Files

- `connector/*.php` and `connector/templates/*.php`: selectable LLM drivers and
  KoboldCPP prompt templates.
- `tts/tts-*.php`: selectable NPC/player TTS drivers and the PHPUnit fake.
- `stt/stt-*.php`: selectable STT drivers and the disabled fallback.
- `ext/relationship_system/`: relationship context, queue, and worker hooks.
- `service/processors/*/entrypoint.php`: middle-term memory and rolemaster
  processors discovered by `service/manager.php`.
- `processor/`, `prompts/`, and `functions/`: request routing and prompt hooks.

## Deliberately Retained Development Files

- `unittests/tests/`, `unittests/composer.json`, and `unittests/composer.lock`:
  maintained regression tests. `unittests/vendor/` is generated and ignored.
- `ui/tests/` and `debug/data/test.wav`: manually invoked connector diagnostics.
- `docs/`: protocol schemas and implementation audits.
- `tools/`: database bootstrap and release verification.

## Removed During This Audit

- Committed `unittests/vendor/` dependency output.
- Unused `tts/composer.json` Google Cloud TTS dependency manifest.
- Unused `tts/data/put_your_json_voices_here.txt` placeholder.

## Deployed Tree Check

The local runtime at `/var/www/html/DialecticServer` contained no live-only PHP,
JavaScript, CSS, SQL, schema, or UI asset files. Its live-only files were runtime
state under voice cache, TTS job queues, logs, and local configuration.

Run `tools/audit-release-tree.ps1` before each release. It rejects tracked
runtime output and vendored dependencies, verifies required endpoints and every
selectable TTS/STT driver, lints tracked PHP, and parses tracked JSON.
