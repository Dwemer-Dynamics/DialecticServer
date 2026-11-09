# DialecticServer changelog

## 1.2.0 — 2026-11-08 release candidate

Changes from 1.1.3, paired with Dialectic 1.2.0:

- Added server plugin catalog channels, package uploads, stable installation folders, safe settings links, and removal that can retain plugin data.
- Added extension hooks, registered addon actions, plugin-local helpers, and bounded opt-in action follow-ups that preserve action focus and results.
- Added request-scoped addon messages and reactions without changing global settings.
- Unified NPC and narrator quest commentary routing and added one-shot NPC hypnosis mode.
- Added the DwemerDistro LLM Studio connector and Quickstart integration.
- Improved PocketTTS connector selection and provider detection in TTS Studio.
- Expanded addon runtime and distribution documentation, with a reproducible example package and parity checks.

### Upgrade notes

- Update alongside the 1.2.0 client. Preserve installation configuration, credentials, profiles, playthroughs, voices, and plugin data.
- Retains production Ploofy model discovery, licensing, and database-provisioning fixes.
- No new database schema delta is introduced by this promotion relative to production.
- Shared API keys and relay security changes on `unstable`, and the open relationship fixes, are outside this candidate.
- Gameplay, live providers, and fresh-install/upgrade acceptance remain release gates.
