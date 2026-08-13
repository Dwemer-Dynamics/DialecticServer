# World Knowledge Parity v1

DIALECTIC owns a native Fallout World Knowledge implementation. It shares a behavioral contract with CHIM and ALMSIVI Oghma, but it does not share runtime code, packages, database tables, or game catalogs with either server.

## Shipped Fallout catalog

The reviewed parity catalog contains 1,221 source-backed articles covering Fallout 3, Fallout: New Vegas, their official DLC, and Tale of Two Wastelands. Every article includes both basic and advanced text, and the catalog provides 7,401 semantic tag assignments across 19 categories. Coverage includes people, locations, factions, organizations, history, events, cultures, creatures, robots, flora, food and drink, medicine, weapons, armor, items, artifacts, technology, vaults, and broader concepts.

The 779-article scope expansion was generated with GLM from pinned Fallout Wiki source revisions, then passed deterministic source, chronology, duplicate, alias, article-policy, and tag validation. Invalid or weak generations were excluded instead of being published. The immutable manifest records the exact catalog and source checksums, generation budget, model, category counts, and editorial approval.

## Retrieval order

For eligible dialogue requests, the server performs one bounded pass in this order:

1. canonical topic and alias grounding;
2. compact grounding that ignores punctuation and spacing;
3. guarded phonetic recovery for explicit knowledge requests or transcript-error cues;
4. guarded tag fallback only when the entity passes abstain;
5. at most one configured connector fallback for an explicit unmatched knowledge request;
6. canonical resolution of every connector suggestion back into the installed catalog.

Canonical and alias matches always take precedence over tags. A tag must contain at least two words. A unique low-frequency tag may identify one article; a shared tag requires at least two corroborating tag phrases and an explicit knowledge request. Tags owned by more than three articles never acquire a topic.

Retrieval returns one to three canonical topics in conversational mention order. Forced species, faction, location, and worldspace context is deduplicated, chronology-gated, and fills only the remaining one-to-three article budget after conversational matches.

## Article contract

Every factory article has:

- canonical topic and reviewed spoken aliases;
- basic article text;
- advanced article text, or an explicit editorial reason for remaining basic-only;
- independent basic and advanced knowledge classes;
- semantic tags and category;
- Fallout setting, region, and chronology bounds;
- source URL and source revision;
- catalog version and deterministic content hash.

Semantic tags describe an article. Knowledge classes authorize an NPC to receive its basic or advanced content. `knowall` overrides positive restrictions; a matching `!class` remains a denial.

## Catalog lifecycle

Factory catalogs are immutable, versioned, checksum-verified sets. Activation and rollback are transactional. Factory rows and user-created rows have separate ownership. Installing or activating a factory catalog never overwrites or deletes a custom article. A custom article with the same canonical topic is the effective override while it remains active.

## Trace contract

Every eligible pass records the algorithm version, request type, normalized input, grounded matches, rejected candidates, tag decisions, fallback eligibility and outcome, forced signals, access decisions, selected prompt articles, elapsed time, and catalog version.

Stable top-level statuses are:

- `disabled`
- `ineligible`
- `grounded`
- `no_match`
- `fallback_succeeded`
- `fallback_failed`
- `denied`

## Prompt contract

Selected articles are emitted as escaped XML under `<fallout_context><knowledge>`. Each entry identifies the canonical topic, access level, category, conversational or forced source, factory/custom ownership, and catalog version. A recognized but unauthorized conversational topic is emitted as a self-closing `denied` article and recorded in the structured trace, so the model knows the NPC lacks that knowledge while protected article text is never included.

Static factory articles describe setting knowledge, not the player's mutable quest state. TTW chronology gates prevent 2281 Mojave developments from being injected into 2277 Capital Wasteland conversations unless the article is explicitly timeless or historical at the current date.
