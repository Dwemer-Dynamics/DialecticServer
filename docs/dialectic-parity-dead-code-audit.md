# Dialectic Parity and Dead-Code Audit

Date: 2026-07-13

Scope: current `unstable` branches of `DialecticServer` and `Dialectic`, compared
with current `unstable` HerikaServer and CHIM source. A feature is classified as
working only when its production entry point, runtime consumer, and relevant
tests or static verification are present.

## Fixed During This Audit

### Combat dialogue policy

`Behavior:EnableCombatDialogue` was exposed in MCM and loaded from the INI but
had no runtime consumers. It now gates chatbox opening, conversation start,
typed player input, microphone input, and rechat whenever the player or target
actor is in combat. Combat barks remain a separate system. The existing
`CancelDialogueOnCombat` option still controls whether an already-playing turn
is cancelled when combat begins.

### In-game AI Agent management

Dialectic now has an `AI Agents` MCM page modelled on CHIM's management page.
It can manually add the targeted NPC, add all eligible nearby NPCs, remove the
targeted agent, remove all agents, list active agents, and refresh/list nearby
available NPCs. Operations use `ActivationManager`, `AgentManager`, and
`TargetManager`; there is no parallel agent registry.

The obsolete no-op `DialecticHaltAIActions` command was replaced in the same
xNVSE opcode slot by `DialecticManageAIAgents`, preserving the opcode positions
of commands registered after it.

### Dead plugin configuration

Removed configuration that was parsed or saved but had no behavioral consumer:

- `Server:DefaultProfile`
- `NearbyItems:SendOnHeldChange`
- `dynamicProfileTimerEnabled` and the hidden `DynamicProfile:Enabled` setting ID
- the duplicate unused `GameLoop.cpp` player-TTS deduplication helper

Dynamic profile scheduling remains always active and is controlled by
`DynamicProfile:TimerMinutes`, as intended.

## Previously Reported Gaps Now Verified Present

- Normal relationship context and post-response updates are wired through
  `ext/relationship_system/context_pre.php` and `postrequest.php`.
- Auto Greeting has server policy/runtime handling, plugin emission, and focused
  runtime tests.
- Native exterior collection includes attached loaded cells rather than only the
  player's current cell.
- UI maintenance executes before the empty-request redirect.
- The complete DialecticServer PHPUnit suite is reproducible in the configured
  WSL test runtime.
- Native task execution uses bounded workers, cancellation, deadlines, and the
  game-thread dispatcher; detached background tasks are no longer the primary
  execution model.

## Remaining CHIM-Parity Work

### Runtime validation matrix

Action execution, rechat interruption, trade deltas, 3D audio, subtitles,
lipsync, traditional dialogue capture, and menu pause have production wiring,
but final parity still depends on repeated in-game tests across interiors,
exterior cell transitions, combat, save loads, and TTW worldspace travel. Static
verification cannot prove engine behavior.

### Script bridge retirement

Native state is authoritative for many systems, but deployed xNVSE scripts still
provide callbacks, comparison telemetry, or fallback data. Remove bridge families
only after their in-game matrix passes, and remove each family as a unit: writer,
DLL reader, startup cleanup, deployed script, and documentation. MCM callback
scripts are active integration code, not dead bridges.

### Deliberately smaller Fallout feature set

Dialectic does not attempt parity with Skyrim-specific spell, shout, soul,
vampire/werewolf, magic-event, Soulgaze, ITT, Background Life, or AI Quest
Manager behavior. Its action and TTS connector catalogs are intentionally
smaller by product decision.

## Confirmed Working Surfaces

- Strict JSON game-data requests and structured response/action envelopes.
- NDJSON streaming, response queue tracking, cancellation, and playback queueing.
- Relationship, memory, middle-term memory, dynamic profile, diary, rechat, and
  active-quest prompt paths.
- World, nearby actors, actors nearby, nearby items, points of interest,
  inventory/equipment, party, activity, and condition context paths.
- Player TTS/STT, NPC TTS voice samples, subtitles, lipsync, facing, spatial
  awareness, and 3D playback production paths.
- Current Fallout action catalog and structured action result delivery.
- Native task manager, game-thread dispatcher, and pipeline integration tests.

## Verification Results

- Dialectic plugin Release build: passing.
- Native CTest: 3 of 3 passing.
- Native runtime static verifier: passing.
- MCM JSON parse: passing.
- DialecticServer PHPUnit: 131 tests passing in the configured WSL runtime.
- Runtime-only behavior still requires in-game verification after deployment.

## Next Review Order

1. Run the in-game parity matrix and record failures with request/turn IDs.
2. Remove native comparison bridges whose matrices pass.
3. Compare CHIM action confirmation and traditional Player TTS behavior against
   Dialectic only where those features remain product requirements.
4. Repeat dead-code scans after each bridge family is retired.
