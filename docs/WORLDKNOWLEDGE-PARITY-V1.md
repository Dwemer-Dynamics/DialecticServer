# World Knowledge Parity v1

DIALECTIC owns a native Fallout World Knowledge implementation. It shares a behavioral contract with CHIM and ALMSIVI Oghma, but it does not share runtime code, packages, database tables, or game catalogs with either server.

## Shipped Fallout catalog

The reviewed parity catalog contains 1,169 source-backed articles covering Fallout 3, Fallout: New Vegas, their official DLC, and Tale of Two Wastelands. Every article includes reviewed advanced text. Publicly knowable subjects also include a separate basic summary, while protected subjects may leave it blank. Access to each tier is controlled independently. Coverage includes people, locations, factions, organizations, history, events, cultures, creatures, robots, flora, food and drink, medicine, weapons, armor, items, artifacts, technology, vaults, and broader concepts.

The GLM-assisted scope expansion was generated from pinned Fallout Wiki source revisions, then passed deterministic source, duplicate, alias, article-policy, tag, access, and Fallout-specific scope validation. Generic ammunition, ordinary crafting junk, and plain food or drink records are excluded when they add no meaningful setting knowledge. Closely overlapping topics are merged under one canonical article. Invalid, weak, or inventory-only generations are excluded instead of being published. The immutable manifest records the exact catalog and source checksums, generation budget, model, category counts, curation checksum, and editorial approval. Source evidence remains in a separate build-time ledger rather than article state.

## Retrieval order

For eligible dialogue requests, the server performs one bounded pass in this order:

1. canonical topic and alias grounding;
2. compact grounding that ignores punctuation and spacing;
3. guarded phonetic recovery for explicit knowledge requests or transcript-error cues;
4. at most one configured connector fallback for an explicit unmatched knowledge request;
5. canonical resolution of every connector suggestion back into the installed catalog.

Canonical and alias matches always take precedence. Ordinary article tags can strengthen an already identified topic but never acquire one.

Retrieval returns one to three canonical topics in conversational mention order. Forced species, faction, location, and worldspace context is deduplicated and fills only the remaining one-to-three article budget after conversational matches.

## Article contract

Every factory and custom article uses the exact Herika Oghma V1 fields:

- canonical topic and reviewed spoken aliases;
- advanced article text;
- basic article text;
- independent basic and advanced knowledge classes;
- semantic ranking tags and category;

Semantic tags describe an article and only support ranking after a topic is identified. Knowledge classes authorize an NPC to receive its basic or advanced content. Classes are one flat comma-separated list: any matching class grants that tier. A matching `!class` denies access before positive classes are considered. A blank list is unrestricted.

Each signed knowledge class belongs to exactly one tier per article. Factory generation, catalog loading, CSV upload, creation, and editing reject repeated or contradictory cross-tier classes. The article-only `common` marker makes a basic article public without assigning `common` to NPCs. `capital_wasteland` and `mojave` remain localized basic permissions. The article-only `blocked` marker represents an intentionally unavailable basic tier when no basic article exists and is never assigned to NPCs.

The explicit `knowall` compatibility class grants advanced knowledge for factory and custom articles, matching CHIM and ALMSIVI Oghma.

Knowledge permissions use plain lowercase snake-case IDs such as `ncr`, `doctor`, `medicine`, and `mojave`, matching the canonical storage style used by CHIM and ALMSIVI. Article `tags` remain natural descriptive retrieval phrases and are not access permissions. The frozen contract lives in `data/fallout_worldknowledge_vocabulary.json`.

Legacy namespaced permissions such as `faction:ncr` and `region:mojave` normalize at the access boundary, but new factory and generated data is always written in plain form. Namespace-aware aliases prevent collisions: the old `role:courier` becomes `traveler`, while `person:courier` becomes the exact subject `courier`. Factory biographies carry only identity, location, occupation, faction, and race permissions; `common` is never assigned to NPCs. Template reprovisioning rewrites blank values, the current generated seed, the legacy generated seed containing `common`, or the exact prior generated seed recorded by checksum while preserving divergent user-authored tags. Capital Wasteland and Mojave public knowledge remains separated unless a reviewed rule explicitly grants both regions.

## Catalog lifecycle

Factory catalogs are immutable, versioned, checksum-verified sets. Activation and rollback are transactional. Factory rows and user-created rows have separate ownership. Installing or activating a factory catalog never overwrites or deletes a custom article. A custom article with the same canonical topic is the effective override while it remains active.

## Trace contract

Every eligible pass records the Oghma contract version, effective setting values and sources, request type, normalized input, grounded matches, rejected candidates, fallback eligibility and outcome, forced signals, controlled context tags, access decisions, selected prompt articles, timing, catalog version/checksum, and prompt hash.

Stable top-level statuses are:

- `disabled`
- `ineligible`
- `grounded`
- `no_match`
- `fallback_succeeded`
- `fallback_unresolved`
- `fallback_failed`
- `fallback_disabled`
- `fallback_unconfigured`
- `unavailable`
- `not_run`
- `legacy`

## Prompt contract

Selected articles are emitted as escaped XML under `<oghma contract="oghma-parity-v1" status="...">`. Each `<article>` identifies the canonical topic, conversational or forced source, and access level. Allowed entries contain `<content>`; recognized but unauthorized conversational topics contain only `<denial>`, so protected article text is never included.

Static factory articles describe stable setting knowledge, not the player's mutable quest state. Capital Wasteland, Mojave, DLC, faction, specialist, and personal boundaries are expressed through the two knowledge-class fields.
