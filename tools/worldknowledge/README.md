# Fallout World Knowledge Builder

This local tool builds basic-only Dialectic world knowledge from Fallout Wiki
pages. It deliberately separates page discovery from generation so discovered
pages must be reviewed before they can become shipped lore.

## Data contract

Generated CSV files use Dialectic's seven columns:

```text
topic,topic_desc,knowledge_class,topic_desc_basic,knowledge_class_basic,tags,category
```

Only `topic`, `topic_desc_basic`, and `category` are populated. Advanced lore,
knowledge classes, and tags remain blank. A topic may contain CHIM-style
comma-separated aliases. The first value is always the canonical lowercase
snake_case key:

```csv
"new_california_republic,NCR",,,"The NCR is...",,,faction
```

## Workflow

1. Discover candidates from the configured Fallout 3 and New Vegas category
   roots:

   ```powershell
   python tools/worldknowledge/generate_fallout_worldknowledge_basic.py discover
   ```

   This writes `tools/worldknowledge/output/discovered_topics.csv`. Review the
   file and set `include` to `1` only for pages that belong in the dataset.

2. Curate a balanced release manifest from the discovery output. Curated seed
   files are always included before the remaining category quotas are filled:

   ```powershell
   python tools/worldknowledge/generate_fallout_worldknowledge_basic.py curate `
     --candidates tools/worldknowledge/output/discovered_topics.csv `
     --seed tools/worldknowledge/fallout_worldknowledge_topics.csv `
     --output tools/worldknowledge/output/curated_topics.csv
   ```

   The default quotas produce 350 topics: 110 locations, 45 factions, 40
   creatures, 125 people, and 30 historical events.

3. Build approved topics with OpenRouter GLM 5.2:

   ```powershell
   $env:OPENROUTER_API_KEY = "..."
   python tools/worldknowledge/generate_fallout_worldknowledge_basic.py build `
     --manifest tools/worldknowledge/output/discovered_topics.csv
   ```

   The committed 25-topic pilot can be built by omitting `--manifest`.

4. Validate an output CSV:

   ```powershell
   python tools/worldknowledge/generate_fallout_worldknowledge_basic.py validate `
     tools/worldknowledge/output/fallout_worldknowledge_basic.csv
   ```

5. Generate aliases from the approved descriptions and source titles:

   ```powershell
   $env:OPENROUTER_API_KEY = "..."
   python tools/worldknowledge/generate_fallout_worldknowledge_aliases.py
   ```

   The alias pass caches GLM results, rejects aliases that are not supported by
   the source text, removes cross-topic collisions, and writes a review report.
   Use `--no-llm` to rebuild from cached and deterministic aliases only.

Use `build --no-llm --allow-invalid` for local API/cache smoke tests. Extracted
wiki introductions are then used as drafts rather than release-quality text.

## Local artifacts

- `cache/`: cached MediaWiki page payloads and resumable GLM summaries keyed to
  the source revision and model.
- `output/fallout_worldknowledge_basic.csv`: generated Dialectic import file.
- `output/fallout_worldknowledge_sources.jsonl`: source URLs and revision IDs.
- `output/fallout_worldknowledge_validation.json`: validation report.

`cache/` and `output/` are git-ignored. Once a dataset has been reviewed, copy
the approved CSV into `data/fallout_worldknowledge_basic.csv` in a separate
release change and add the idempotent database seed migration.

The script reads credentials only from `OPENROUTER_API_KEY`. Do not place API
keys in source files or tracked text files.

Use `build --refresh-generation` only when approved summaries should be
regenerated. Normal reruns reuse valid summaries while the wiki revision and
model remain unchanged.
