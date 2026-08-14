# World Knowledge Parity v1

DIALECTIC owns a native Fallout World Knowledge implementation. It shares a behavioral contract with CHIM and ALMSIVI Oghma, but it does not share runtime code, packages, database tables, or game catalogs with either server.

## Shipped Fallout catalog

The reviewed parity catalog contains 1,221 source-backed articles covering Fallout 3, Fallout: New Vegas, their official DLC, and Tale of Two Wastelands. Every article includes reviewed advanced text. Publicly knowable articles also include a separate basic summary, while genuinely secret or personal subjects intentionally omit it. Coverage includes people, locations, factions, organizations, history, events, cultures, creatures, robots, flora, food and drink, medicine, weapons, armor, items, artifacts, technology, vaults, and broader concepts.

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
- basic article text, unless the subject is intentionally protected;
- advanced article text, or an explicit editorial reason for remaining basic-only;
- independent basic and advanced knowledge classes;
- semantic tags and category;
- Fallout setting, region, and chronology bounds;
- source URL and source revision;
- catalog version and deterministic content hash.

Semantic tags describe an article. Knowledge classes authorize an NPC to receive its basic or advanced content. Access-v2 rules are OR-of-AND expressions: `&` joins tags that must all match, and `|` joins alternative audiences. Legacy comma-separated custom rules retain their any-of behavior.

Factory access-v2 articles always use their reviewed rules. The legacy `knowall` compatibility tag applies only to custom articles and cannot expose protected factory knowledge. A matching `!class` remains a denial.

Knowledge permissions use plain lowercase snake-case IDs such as `ncr`, `doctor`, `medicine`, and `mojave`, matching the canonical storage style used by CHIM and ALMSIVI. Article `tags` remain natural descriptive retrieval phrases and are not access permissions. The frozen contract lives in `data/fallout_worldknowledge_vocabulary.json`.

Legacy namespaced permissions such as `faction:ncr` and `region:mojave` normalize at the access boundary, but new factory and generated data is always written in plain form. Namespace-aware aliases prevent collisions: the old `role:courier` becomes `traveler`, while `person:courier` becomes the exact subject `courier`. Every factory biography carries `common`; template reprovisioning rewrites only blank values or values that are semantically identical to the generated seed, preserving divergent user-authored tags. Capital Wasteland and Mojave public knowledge remains separated unless a reviewed rule explicitly grants both regions.

## Catalog lifecycle

Factory catalogs are immutable, versioned, checksum-verified sets. Activation and rollback are transactional. Factory rows and user-created rows have separate ownership. Installing or activating a factory catalog never overwrites or deletes a custom article. A custom article with the same canonical topic is the effective override while it remains active.

## Trace contract

Every eligible pass records the algorithm version, request type, normalized input, grounded matches, rejected candidates, tag decisions, fallback eligibility and outcome, forced signals, controlled context tags, access decisions, selected prompt articles, elapsed time, and catalog version.

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
