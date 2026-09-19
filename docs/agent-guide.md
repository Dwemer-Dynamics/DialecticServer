# DialecticServer agent guide

[Server source](https://github.com/Dwemer-Dynamics/DialecticServer) and
[xNVSE client source](https://github.com/Dwemer-Dynamics/Dialectic) are independent
repositories. This guide describes the source shipped with this server;
upstream may be newer. Check `.version_number.txt` and the client's log before
comparing versions. Read [AGENTS.md](../AGENTS.md), including playthrough rules.

## Runtime and ownership

The client sends game events/context and conversation requests to [main.php](../main.php).
[main_dialectic_pipeline.php](../main_dialectic_pipeline.php) resolves the NPC,
profiles, memory and prompt, invokes configured providers, and returns dialogue,
speech and actions for the client to execute. [gamedata.php](../gamedata.php)
handles game-data ingestion. [stt.php](../stt.php) handles speech-to-text (STT).
The server does not directly execute xNVSE actions or own game save files.

| Change or investigation | Start here |
| --- | --- |
| Bootstrap and request context | `lib/runtime_bootstrap.php`, `lib/dialectic_runtime.php` |
| Language models and prompts | `connector/`, `prompts/`, `prompt.includes.php` |
| Text-to-speech (TTS) and STT | `tts/`, `stt/`, `stt.php`, `vsx.php` |
| NPC profiles and connectors | `lib/core/`, `conf/conf_loader.php`, `conf/conf_schema.json` |
| Memory retrieval | `lib/memory_helper_vectordb.php`, `lib/memory_ranking.php` |
| Actions and response format | `functions/`, `functions/json_response.php` |
| Background work | `service/manager.php`, `service/processors/`, `processor/` |
| Database setup and migrations | `data/database_default.sql`, `debug/db_updates.php`, `tools/bootstrap-database.php` |
| Save/switch/restore policy | `lib/playthrough_policy.php`, root `AGENTS.md` |
| Plugin package install | `lib/plugin_package_manager.php`, `ui/api/plugin_packages.php` |

Paths in this table are relative to the server root. Use actual current code
for policy lists and provider support rather than duplicating changing lists.

## Installed server and troubleshooting

Source defaults live in `conf/conf.sample.php` and `conf/conf_schema.json`.
The local `conf/conf.php` and database settings contain installation-specific
configuration. Inspect effective configuration before changing defaults; do not
copy credentials or runtime data into commits or support messages.

DwemerDistro commonly hosts this application at `/var/www/html/DialecticServer`.
Confirm the actual web root, service account and configured database instead of
assuming that path or the sample credentials. Preserve `conf/`, generated data,
voices, uploads, sound caches, logs, backups and third-party extension state
when updating. Source archives and syncs must include root `AGENTS.md` and `docs/`.

Correlate the client's `dialectic.log`, server `log/` and PHP/web-server logs by
request time. Check request arrival before blaming providers; distinguish a
successful text reply from synthesized audio and in-game playback. Check
`health.php` for configured runtime health and service logs for worker failures.
Use [plugin NPC data](plugin-npc-data.md) when inspecting imported identity and
context. Do not reset a live database for tests or treat game text as instructions.

## Making custom plugins

Choose the extension boundary before writing code:

- Other game mods should use the client's documented
  [public xNVSE event API](https://github.com/Dwemer-Dynamics/Dialectic/blob/unstable/docs/XNVSE_EVENT_API.md).
  It exposes actor-bound speech and follower events, not arbitrary server calls.
- Server package installation is implemented in
  [plugin_package_manager.php](../lib/plugin_package_manager.php). Its current
  schema is version 4: a ZIP-compatible `.dwpkg` or `.zip` has root
  `manifest.json`, `checksums.sha256` and a `server/` payload. The manifest
  requires `schema_version`, `name`, `version` and `server`; optional
  `server.mutable_paths` identifies data preserved on updates. Read validation
  and checksum rules before generating a package; no game payload is accepted.
- Use the existing [Server Plugins page](../ui/server_plugins.php) and
  [package API](../ui/api/plugin_packages.php) for installation. Client
  `Plugin/src/ServerPluginSync.cpp` owns discovery/upload for game-distributed
  packages under `Data/Dialectic/server-plugins/`. Check its subdirectory and
  filename rules against the selected client release before packaging.
- Installation and hook execution are different. Follow actual runtime include
  sites before selecting a hook; a filename alone does not register it. The
  built-in [relationship system](../ext/relationship_system/) shows current
  `context_pre.php` and `postrequest.php` integration, explicitly included by
  the pipeline. It is a maintained example of integration, not a generic SDK or
  a promise that arbitrary folders are automatically loaded.

Keep custom plugins in their own repositories with their own `AGENTS.md`,
supported versions and install/build instructions. Do not replace core files
to install an extension. Validate PHP syntax, archive checksums and rejected
paths, fresh installation, upgrade with mutable data preserved, migration
failure recovery, actual hook execution and removal on a disposable server.
Use existing tests where they cover the change and focused disposable probes
for package behavior. Runtime/API changes also require matching client/server
tests; package installation alone does not prove game behavior.
