# Fallout World Knowledge Attribution

The legacy basic dataset in `fallout_worldknowledge_basic.csv` and the reviewed
factory catalog in `fallout_worldknowledge_parity_v1.csv` contain summaries
derived from articles on [The Fallout
Wiki](https://fallout.wiki/). The wiki publishes page content under the
[Creative Commons Attribution-ShareAlike 4.0 International
License](https://creativecommons.org/licenses/by-sa/4.0/) unless otherwise
noted.

The catalog summaries derived from that material are distributed under the
same Creative Commons Attribution-ShareAlike 4.0 International License.

`fallout_worldknowledge_sources.jsonl` records the source article URL, page ID,
revision ID, and revision timestamp for every shipped factory topic. The
catalog manifest records its source-snapshot checksum, generation model,
bounded generation cost, and editorial approval. Advanced fields were proposed
with `z-ai/glm-5.2`, then checked against the source snapshot with deterministic
format, provenance, access-class, chronology, duplicate, and representative
content review on August 13, 2026.

Fallout and related names are trademarks or registered trademarks of their
respective owners. This fan-created dataset is not endorsed by Bethesda
Softworks or The Fallout Wiki.
