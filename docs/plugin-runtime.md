# Dialectic plugin runtime reference

This guide describes DialecticServer's current `unstable` implementation for Fallout: New Vegas/TTW. Read the [agent guide](agent-guide.md), [test setup](building.md) and [NPC data contract](plugin-npc-data.md). Check callers against the client/server versions you support; matching PHP filenames do not establish CHIM compatibility.

## Integration points and timing

Installing a package and executing its code are separate operations. The current dialogue pipeline explicitly includes the built-in relationship files; it does not provide CHIM's general recursive named-hook registration. Do not ship an arbitrary `ext/MyPlugin/prerequest.php` and assume it runs.

| Stage | Current source | Contract |
|---|---|---|
| JSON ingress | [main.php](../main.php), [lib/request.php](../lib/request.php) | Decode and normalize the typed event before the pipeline. |
| Non-model events | [processor/comm.php](../processor/comm.php) | `_speech` and `_speech_abort` update delivery bookkeeping. Early completion skips later dialogue stages. |
| Relationship context | [main_dialectic_pipeline.php](../main_dialectic_pipeline.php) | Explicitly includes `ext/relationship_system/context_pre.php` during dialogue context construction. |
| Relationship evaluation | Same pipeline | Explicitly includes `ext/relationship_system/postrequest.php` after streaming/retry completion and before the JSON response closes. This is not proof of client playback. |
| Other post-processing | [processor/postrequest.php](../processor/postrequest.php) | Runs when the later dialogue path reaches it; not a universal callback for all endpoints. |

For an independent extension, identify an explicit integration contract or propose a focused source contribution. Do not replace core files as an installation technique. For other game mods, use the client's [public xNVSE event API](https://github.com/Dwemer-Dynamics/Dialectic/blob/unstable/docs/XNVSE_EVENT_API.md) within its documented scope.

## Request state and optional speech IDs

The normalizer produces `type`, `ts`, `gamets`, `payload`, original `json`, `source`, `request_id` and `response_format`. Array payloads become JSON strings. When input payload is absent, player text can become a `dialectic.input.v1` payload; `_speech` can use the original event object. Prefer explicit payload fixtures when testing acknowledgement handling.

This CLI example uses the real normalizer only. Run from the source root; it does not send an HTTP request, call a provider or open a database:

```php
<?php
require_once getcwd() . '/lib/request.php';
$exampleEvent = dialectic_normalize_json_event([
    'type' => '_speech', 'ts' => 1, 'gamets' => 2,
    'payload' => [
        'speaker' => 'Veronica', 'listener' => 'Courier',
        'speech' => 'Good morning.', 'location' => 'Goodsprings',
    ],
]);
$exampleSpeech = json_decode($exampleEvent['payload'], true, 512, JSON_THROW_ON_ERROR);
$exampleId = is_string($exampleSpeech['utterance_id'] ?? null)
    ? trim($exampleSpeech['utterance_id']) : '';
if ($exampleEvent['type'] !== '_speech' || $exampleId !== '') {
    throw new RuntimeException('Unexpected normalization contract');
}
```

The `_speech` handler reads `speaker`, `listener`, `speech` and `location` directly. Validate those fields before your own effects. It defaults missing `utterance_id` to an empty string; that absence alone is not a warning. Optional metadata includes `audios`, `companions`, distance/spatial values and `debug`. Do not invent a listener from someone merely mentioned in dialogue.

An utterance ID supports delivery matching but is not necessarily a unique extension operation. Scope duplicate prevention to the effect/listener/subject. For missing IDs, either skip operations needing exact correlation or define and test a fallback; text alone can merge separate repeated lines. `_speech_abort` is a separate cancellation path. Respect delivery state instead of treating every generated response as spoken.

Player input, Director lines and injected context have different origins and timing. Inspect [main_dialectic_pipeline.php](../main_dialectic_pipeline.php) before choosing a pre/post-processing point; do not query the latest dialogue row and assume it belongs to the current request. Request acceptance and model completion do not prove in-game speech.

### Missing playthrough state

Observing a valid event need not create or require an active Playthrough Saves profile. If an extension needs a durable scope and none is available, skip its scoped effect with a bounded diagnostic or use an explicitly documented independent scope. Do not fabricate profile `0`, switch profiles or create a save as a side effect.

NPC plugin data follows the existing history/save rules. Arbitrary extension tables remain unmanaged unless explicitly covered by [lib/playthrough_policy.php](../lib/playthrough_policy.php); a table name or schema alone does not enroll them. Respect [lib/playthrough_runtime.php](../lib/playthrough_runtime.php) and reject stale queued writes after a switch. A separate Fallout save does not isolate the current server database.

## Atomic writes

The state change, duplicate-prevention record and history for one accepted effect must all commit, or none do. Make network/model calls first; acquire the relevant lock and re-read current state before committing.

- Use one connection, checked query results, parameterized values and a unique operation key. A read-before-write duplicate check alone is racy.
- Dialectic's [database wrapper](../lib/postgresql.class.php) returns a PostgreSQL result or `false` from `execQuery()`. `execQueryVerbose()` instead returns an empty string on success or an error string. Do not copy CHIM's `execQuery()` convention here.
- Preserve relationship validation, manual locks and timeline/history behavior. The automatic paths in [relationship_llm.php](../ext/relationship_system/relationship_llm.php) and the direct/admin setter in [lib/relationship_manager.php](../lib/relationship_manager.php) serve different purposes; the latter is not a generic atomic gossip API.
- `NpcMaster::setPluginData()` writes one namespace. It does not automatically include separate relationship/dedup/history writes. A transaction on another connection cannot cover its operation.
- PostgreSQL `BEGIN` does not nest. Define transaction ownership and use savepoints only with that contract. Roll back after any failure, preserve unrelated JSON fields, and treat a valid no-change result separately from a storage failure.

The exercise below demonstrates the invariant using temporary tables, not real NPCs or a bypass of the relationship writer.

### Runnable transaction exercise

Run these SQL blocks on **one connection to a disposable PostgreSQL database**. They use temporary tables only; do not substitute core table names. This demonstrates atomic state/history/deduplication without changing a real NPC. Production plugin-owned tables belong in the `plugins` schema with ordered migrations and a defined retention policy.

```sql
CREATE TEMP TABLE example_effects (
    scope text NOT NULL, event_id text NOT NULL,
    PRIMARY KEY (scope, event_id)
);
CREATE TEMP TABLE example_affinity (
    scope text NOT NULL, listener_id bigint NOT NULL, subject_id bigint NOT NULL,
    affinity integer NOT NULL CHECK (affinity BETWEEN -100 AND 100),
    PRIMARY KEY (scope, listener_id, subject_id)
);
CREATE TEMP TABLE example_history (
    scope text NOT NULL, event_id text NOT NULL,
    listener_id bigint NOT NULL, subject_id bigint NOT NULL, affinity integer NOT NULL,
    PRIMARY KEY (scope, event_id)
);
```

The example operation key represents one effect on one listener/subject pair. A real plugin must include those identities in its deduplication key when one utterance can produce multiple effects. Parameterize the fixture values when adapting this statement.

```sql
BEGIN;
WITH claimed AS (
    INSERT INTO example_effects (scope, event_id)
    VALUES ('test-session', 'effect-001')
    ON CONFLICT DO NOTHING
    RETURNING scope, event_id
), changed AS (
    INSERT INTO example_affinity AS current (scope, listener_id, subject_id, affinity)
    SELECT scope, 1, 2, 5 FROM claimed
    ON CONFLICT (scope, listener_id, subject_id) DO UPDATE
    SET affinity = LEAST(100, GREATEST(-100, current.affinity + EXCLUDED.affinity))
    RETURNING scope, listener_id, subject_id, affinity
)
INSERT INTO example_history (scope, event_id, listener_id, subject_id, affinity)
SELECT changed.scope, claimed.event_id, listener_id, subject_id, affinity
FROM changed JOIN claimed USING (scope);
COMMIT;
```

After one run, affinity is `5` with one effect and one history row. Repeating the operation leaves those values unchanged. To test rollback, repeat with a new event ID and replace `COMMIT` with `ROLLBACK`; none of that operation's three writes should remain. On any statement error, roll back and report failure rather than continuing to commit or marking the job complete.

## Installation and updates

Dialectic uses the schema-4 ZIP-compatible `.dwpkg`/`.zip` [package manager](../lib/plugin_package_manager.php). Do not substitute CHIM catalog tarballs. Its layout is:

```text
manifest.json
checksums.sha256
server/
  manifest.json
  ...extension files...
```

The outer manifest supplies `schema_version: 4`, `name`, `version` and `server`. All archive files except `checksums.sha256` need checksum entries. Declare actual extension-owned mutable paths in `server.mutable_paths`; paths are relative to the server payload. Game DLLs do not belong in it. Review path validation, migrations and activation rollback before building a package.

1. Verify supported client/server versions and the extension's explicit execution entry point. Back up configuration and use disposable data for the first install.
2. For a game-carried package, install the author's MO2 archive so its virtual Data tree contains `Dialectic/server-plugins/<name>/<version>.dwpkg` (or `.zip`). Use one intended version per plugin.
3. Launch Fallout through MO2 against the intended server. The [client synchronizer](https://github.com/Dwemer-Dynamics/Dialectic/blob/unstable/Plugin/src/ServerPluginSync.cpp) probes and uploads through [ui/api/plugin_packages.php](../ui/api/plugin_packages.php). Inspect `[SERVER_PLUGIN_SYNC]` client logs and the [Server Plugins page](../ui/server_plugins.php).
4. Check package activation and then one known extension event. A completed install does not prove an arbitrary hook ran.
5. Replace the old package for an update and verify again. Follow the author's separate server uninstall procedure; removing the MO2 archive only stops future discovery.

A server-only archive may not match MO2's usual game-data layout. Verify the path above and the author's instructions before accepting a warning. Do not put PHP payload files directly into the game's Data root.

The game client chooses the newest **file modification time** when multiple package files exist, not the highest version. The server's probe compares exact version equality against its installed-package ledger, not semantic upgrade order: an older differing version can request upload. Equal-version changed bytes are not a probe-based update. Explicit server-side installs and game sync should share one intended source/version; manual file replacement does not update the ledger and can be overwritten or skipped unexpectedly.

## Background model calls

The built-in relationship system is a working integration example, not a public plugin queue API. Read [postrequest.php](../ext/relationship_system/postrequest.php), [async_queue.php](../ext/relationship_system/async_queue.php), [context_pre.php](../ext/relationship_system/context_pre.php), [worker.php](../ext/relationship_system/worker.php) and [relationship_llm.php](../ext/relationship_system/relationship_llm.php) together. The queue, startup policy, connector checks and worker belong to that feature. Queue coalescing is not an every-utterance audit trail.

On an isolated server with the relationship feature/connector configured, produce a qualifying dialogue event and inspect its queue and worker log. To exercise one batch manually, stop the test installation's existing relationship daemon first, then run from the server root:

```sh
php ext/relationship_system/worker.php
```

This can call a paid provider and alter relationships; it is not a documentation smoke test. Without `--daemon` it processes one batch, not necessarily the full backlog. The current one-shot worker exits `1` when it processes zero items, so inspect its output/logs before treating that exit status as a crash. Verify the actual `context_pre.php` startup code; the worker header's reference to `context.php` is an older comment.

A custom worker needs explicit registration/startup, bounded payloads, scoped actor/operation identities, claims/leases, timeouts, limited retries and shutdown/switch handling. Keep model calls outside write transactions. Revalidate current scope/locks before committing, and make redelivery after a crash safe. Do not reuse relationship PID/lock files or assume its refresh helper manages arbitrary workers.

## Validation and reports

Lint and run the normalization example without the server bootstrap. Test speech fixtures with and without IDs, malformed data and cancellation separately from model generation. Use disposable PostgreSQL for the transaction exercise and temporary server/state roots with a stubbed migration runner for package probes. Check clean install, upgrade, mutable files, checksum rejection and migration-failure recovery.

Follow [building.md](building.md) for existing tests. Record extension/client/server revisions, event route, speaker/listener/subject, expected/actual results and filtered logs. Package, source and fixture checks do not establish MO2, live worker/provider or Fallout gameplay success.
