# Official TTW creature profiles

This catalog adds factory biographies for official Fallout 3, Fallout: New Vegas,
official DLC, Tale of Two Wastelands, and YUPTTW non-verbal creatures. Game
records are authoritative for identity and reachability. No third-party creature
records are accepted.

## Regeneration

1. Copy tools/xedit/Export Official TTW Creatures.pas into xEdit's Edit Scripts
   folder.
2. Run FNVEdit through the TTW Mod Organizer 2 profile with only the official
   masters enabled. Apply the exporter and retain its raw CSV under tmp/.
3. Run:
   python tools/build_fallout_creature_catalog.py --xedit tmp/fallout_official_crea_xedit.csv
4. Set OPENROUTER_API_KEY in the process environment and run:
   python tools/generate_fallout_creature_profiles.py
5. Build the deterministic SQL and voice palette:
   python tools/build_fallout_creature_seed.py --voices <path-to-fallout_builtin_voices.csv>

Generation uses z-ai/glm-5.1 through OpenRouter, one request at a time. Each
success is checkpointed in data/fallout_creature_profile_manifest.json.
Validated profiles are reused when their generation input, prompt, and model are
unchanged.

## Runtime behavior

Creature templates resolve in this order:

1. Explicit unique placed-reference mapping.
2. Defining plugin and local base FormID.
3. Legacy exact RefID.
4. Unambiguous exact display name.

The legacy prefix-name fallback remains available for existing non-creature
profiles, but it cannot select a mapped creature template. Custom templates,
locked profiles, existing biographies, and manually selected voices retain
precedence. A mapped non-verbal creature's curated voice replaces its incoming
creature sound voice on first assignment.

Voice assignments are validated against Dialectic's vanilla catalog and mapped
sample files. They have not been auditioned in game.
