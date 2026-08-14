#!/usr/bin/env python3
"""Publish flat Oghma-style Fallout knowledge classes and compact NPC tags."""

from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
from io import StringIO
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "data" / "fallout_worldknowledge_parity_v1.csv"
NPC_TAGS = ROOT / "data" / "fallout_worldknowledge_npc_tags.csv"
MANIFEST = ROOT / "data" / "fallout_worldknowledge_manifest.json"
VOCABULARY = ROOT / "data" / "fallout_worldknowledge_vocabulary.json"
SOURCES = ROOT / "data" / "fallout_worldknowledge_sources.jsonl"
EXPANSION_TOPICS = ROOT / "tools" / "worldknowledge" / "fallout_worldknowledge_expansion_topics.csv"
EDITORIAL_OVERRIDES = ROOT / "tools" / "worldknowledge" / "fallout_worldknowledge_editorial_overrides.json"
EDITORIAL_CURATION = ROOT / "data" / "fallout_worldknowledge_editorial_curation.json"
KNOWN_NAMESPACES = {"person", "region", "community", "place", "faction", "role", "domain", "race"}
TAG_PATTERN = re.compile(r"^[a-z0-9][a-z0-9_]{0,100}$")
CATALOG_FIELDS = [
    "topic", "aliases", "topic_desc", "knowledge_class", "topic_desc_basic",
    "knowledge_class_basic", "tags", "category",
]
BASIC_ONLY_MARKERS = {"capital_wasteland", "common", "mojave"}
BLOCKED_CLASS = "blocked"

# Namespace-aware aliases prevent old role labels from colliding with exact
# person subjects once the namespace itself is removed.
LEGACY_ALIASES = {
    "person:lonewanderer": "lone_wanderer",
    "person:vault_dweller": "the_vault_dweller",
    "role:caravaner": "traveler",
    "role:courier": "traveler",
    "role:military": "soldier",
    "faction:military": "soldier",
    "faction:legion": "caesars_legion",
    "faction:brotherhood": "brotherhood_of_steel",
    "faction:brotherhood_of_steelf": "brotherhood_of_steel",
    "faction:followers": "followers_of_the_apocalypse",
    "faction:great_khan": "great_khans",
    "faction:powder_ganger": "powder_gangers",
    "faction:raiders": "raider",
    "race:supermutant": "super_mutant",
    "region:the_divide": "divide",
    "place:the_divide": "divide",
    "region:zion_canyon": "zion",
    "place:zion_canyon": "zion",
    "community:zion_canyon": "zion",
}
PLAIN_ALIASES = {
    "lonewanderer": "lone_wanderer",
    "caravaner": "traveler",
    "legion": "caesars_legion",
    "brotherhood": "brotherhood_of_steel",
    "brotherhood_of_steelf": "brotherhood_of_steel",
    "followers": "followers_of_the_apocalypse",
    "great_khan": "great_khans",
    "powder_ganger": "powder_gangers",
    "raiders": "raider",
    "supermutant": "super_mutant",
    "big_empty": "big_mt",
    "the_divide": "divide",
    "zion_canyon": "zion",
}


def snake_case(value: str) -> str:
    return re.sub(r"[^a-z0-9]+", "_", value.lower()).strip("_")


def canonical_tag(value: object) -> str:
    raw = str(value).strip().lower()
    if not raw:
        return ""
    negative = raw.startswith("!")
    if negative:
        raw = raw[1:]
    if ":" in raw:
        namespace, label = raw.split(":", 1)
        namespace = snake_case(namespace)
        label = snake_case(label)
        if not namespace or not label:
            return ""
        raw = f"{namespace}:{label}"
        canonical = LEGACY_ALIASES.get(raw, label if namespace in KNOWN_NAMESPACES else snake_case(raw))
    else:
        canonical = snake_case(raw)
        canonical = PLAIN_ALIASES.get(canonical, canonical)
    if not TAG_PATTERN.fullmatch(canonical):
        return ""
    return f"!{canonical}" if negative else canonical


def flat_oghma_rule(value: object, domains: set[str], tag_frequency: Counter[str]) -> str:
    """Reduce each legacy AND-clause to its narrowest actual audience class."""
    raw_value = str(value)
    if "&" not in raw_value and "|" not in raw_value:
        flat = []
        for raw_class in re.split(r"[,;]", raw_value):
            tag = canonical_tag(raw_class)
            if tag and tag not in flat:
                flat.append(tag)
        return ",".join(flat)
    classes: list[str] = []
    for raw_clause in raw_value.split("|"):
        terms: list[str] = []
        for raw_term in raw_clause.split("&"):
            tag = canonical_tag(raw_term)
            if tag and tag not in terms:
                terms.append(tag)
        denied = [tag for tag in terms if tag.startswith("!")]
        positive = [tag for tag in terms if not tag.startswith("!")]
        audience = [tag for tag in positive if tag not in domains and tag != "common"]
        if audience:
            selected = min(
                audience,
                key=lambda tag: (tag_frequency.get(tag, 0) or 10**9, tag),
            )
            if selected not in classes:
                classes.append(selected)
        elif "common" in positive and "common" not in classes:
            classes.append("common")
        elif positive:
            selected = min(
                positive,
                key=lambda tag: (tag_frequency.get(tag, 0) or 10**9, tag),
            )
            if selected not in classes:
                classes.append(selected)
        for tag in denied:
            if tag not in classes:
                classes.append(tag)
    return ",".join(classes)


def tier_exclusive_rules(
    advanced_value: object,
    basic_value: object,
    topic: str,
    basic_text: str,
) -> tuple[str, str]:
    """Keep every signed knowledge class in exactly one article tier."""
    advanced = [value for value in str(advanced_value).split(",") if value]
    basic = [value for value in str(basic_value).split(",") if value]
    advanced_by_base = {value.lstrip("!"): value for value in advanced}
    basic_by_base = {value.lstrip("!"): value for value in basic}
    contradictions = sorted(
        base for base in advanced_by_base.keys() & basic_by_base.keys()
        if advanced_by_base[base] != basic_by_base[base]
    )
    if contradictions:
        raise RuntimeError(
            f"{topic}: knowledge classes have contradictory signs across tiers: {', '.join(contradictions)}"
        )

    negative_overlap = sorted(
        set(value for value in advanced if value.startswith("!"))
        & set(value for value in basic if value.startswith("!"))
    )
    if negative_overlap:
        raise RuntimeError(
            f"{topic}: negative knowledge classes exist in both tiers: {', '.join(negative_overlap)}"
        )

    # Public markers belong to basic. When advanced has another specialist or
    # subject class, a shared positive class belongs to basic; otherwise it stays
    # advanced so tier normalization never broadens advanced text.
    moved_basic_markers = [value for value in advanced if value in BASIC_ONLY_MARKERS]
    advanced = [value for value in advanced if value not in BASIC_ONLY_MARKERS]
    if basic_text.strip():
        basic.extend(value for value in moved_basic_markers if value not in basic)
    positive_overlap = {
        value for value in advanced
        if not value.startswith("!") and value in basic
    }
    advanced_without_overlap = [value for value in advanced if value not in positive_overlap]
    if any(not value.startswith("!") for value in advanced_without_overlap):
        advanced = advanced_without_overlap
    else:
        basic = [value for value in basic if value not in positive_overlap]
    if not advanced:
        raise RuntimeError(f"{topic}: advanced knowledge classes became empty during tier normalization")
    if not basic:
        basic = [BLOCKED_CLASS]

    overlap = sorted(set(advanced) & set(basic))
    if overlap:
        raise RuntimeError(f"{topic}: knowledge classes remain in both tiers: {', '.join(overlap)}")
    return ",".join(advanced), ",".join(basic)


def canonical_npc_tags(value: object, allowed: set[str]) -> str:
    tags = ["common"]
    for raw_tag in str(value).split(","):
        tag = canonical_tag(raw_tag)
        if tag and tag in allowed and tag not in tags:
            tags.append(tag)
    return ",".join(sorted(tags))


def npc_tag_frequency(path: Path) -> Counter[str]:
    counts: Counter[str] = Counter()
    with path.open(encoding="utf-8", newline="") as handle:
        for row in csv.DictReader(handle):
            for raw_tag in row["worldknowledge_tags"].split(","):
                tag = canonical_tag(raw_tag)
                if tag:
                    counts[tag] += 1
    return counts


def load_editorial_curation() -> tuple[set[str], dict[str, dict[str, object]], dict[str, dict[str, str]]]:
    payload = json.loads(EDITORIAL_CURATION.read_text(encoding="utf-8"))
    if payload.get("schema") != "dialectic.worldknowledge-editorial-curation.v1":
        raise RuntimeError("Fallout World Knowledge editorial curation schema is invalid")
    excluded = {
        snake_case(topic)
        for topics in payload.get("exclusions", {}).values()
        for topic in topics
    }
    source_merges: dict[str, dict[str, object]] = {}
    for target, raw_merge in payload.get("merges", {}).items():
        merge = dict(raw_merge)
        target = snake_case(target)
        survivor = snake_case(str(merge.get("survivor", "")))
        sources = [snake_case(source) for source in merge.get("sources", [])]
        if not target or not survivor or survivor not in sources or len(sources) < 2:
            raise RuntimeError(f"Fallout World Knowledge merge {target!r} is invalid")
        merge["target"] = target
        merge["survivor"] = survivor
        merge["sources"] = sources
        for source in sources:
            if source in excluded or source in source_merges:
                raise RuntimeError(f"Fallout World Knowledge curation repeats source {source}")
            source_merges[source] = merge
    overrides_payload = json.loads(EDITORIAL_OVERRIDES.read_text(encoding="utf-8"))
    if overrides_payload.get("schema") != "dialectic.worldknowledge-editorial-overrides.v1":
        raise RuntimeError("Fallout World Knowledge editorial override schema is invalid")
    overrides = overrides_payload.get("overrides", {})
    for merge in source_merges.values():
        if merge["target"] not in overrides:
            raise RuntimeError(f"Fallout World Knowledge merge {merge['target']} has no reviewed override")
    return excluded, source_merges, overrides


def rewrite_catalog(
    path: Path,
    *,
    check: bool,
    domains: set[str],
    tag_frequency: Counter[str],
    excluded: set[str],
    source_merges: dict[str, dict[str, object]],
    overrides: dict[str, dict[str, str]],
) -> tuple[int, bytes, set[str], list[dict[str, str]]]:
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        rows = list(reader)
        fields = list(reader.fieldnames or [])
    if fields != CATALOG_FIELDS:
        raise RuntimeError("Fallout World Knowledge catalog must use the eight-field Herika format")
    changed = 0
    used_classes = {"common", "knowall"}
    curated_rows = []
    seen_topics = set()
    for row in rows:
        original_topic = snake_case(str(row.get("topic", "")).split(",", 1)[0])
        if original_topic in excluded:
            changed += 1
            continue
        merge = source_merges.get(original_topic)
        if merge is not None:
            if original_topic != merge["survivor"]:
                changed += 1
                continue
            target = str(merge["target"])
            replacement = overrides[target]
            for field, value in replacement.items():
                if field not in fields:
                    continue
                normalized = str(value).strip()
                if row.get(field, "") != normalized:
                    row[field] = normalized
                    changed += 1
        topic_parts = [part.strip() for part in str(row.get("topic", "")).split(",") if part.strip()]
        canonical_topic = snake_case(topic_parts[0] if topic_parts else "")
        aliases = [part.strip() for part in str(row.get("aliases", "")).split(",") if part.strip()]
        aliases = list(dict.fromkeys([*topic_parts[1:], *aliases]))
        if row.get("topic", "") != canonical_topic:
            row["topic"] = canonical_topic
            changed += 1
        normalized_aliases = ",".join(aliases)
        if row.get("aliases", "") != normalized_aliases:
            row["aliases"] = normalized_aliases
            changed += 1
        for field in ("knowledge_class", "knowledge_class_basic"):
            canonical = flat_oghma_rule(row[field], domains, tag_frequency)
            if canonical != row[field]:
                row[field] = canonical
                changed += 1
        advanced_rule, basic_rule = tier_exclusive_rules(
            row["knowledge_class"], row["knowledge_class_basic"], canonical_topic, row["topic_desc_basic"]
        )
        if advanced_rule != row["knowledge_class"]:
            row["knowledge_class"] = advanced_rule
            changed += 1
        if basic_rule != row["knowledge_class_basic"]:
            row["knowledge_class_basic"] = basic_rule
            changed += 1
        for field in ("knowledge_class", "knowledge_class_basic"):
            used_classes.update(tag.lstrip("!") for tag in row[field].split(",") if tag)
        if canonical_topic in seen_topics:
            raise RuntimeError(f"Fallout World Knowledge curation produced duplicate topic {canonical_topic}")
        seen_topics.add(canonical_topic)
        curated_rows.append(row)
    output = StringIO(newline="")
    writer = csv.DictWriter(output, fieldnames=CATALOG_FIELDS, lineterminator="\n")
    writer.writeheader()
    writer.writerows(curated_rows)
    content = output.getvalue().encode("utf-8")
    if content == path.read_bytes():
        changed = 0
    if not check:
        path.write_bytes(content)
    return changed, content, used_classes, curated_rows


def rewrite_source_snapshot(
    path: Path,
    *,
    check: bool,
    excluded: set[str],
    source_merges: dict[str, dict[str, object]],
) -> tuple[int, bytes, int]:
    rows = [json.loads(line) for line in path.read_text(encoding="utf-8").splitlines() if line.strip()]
    changed = 0
    curated_rows = []
    for row in rows:
        original_topic = snake_case(str(row.get("topic", "")))
        if original_topic in excluded:
            changed += 1
            continue
        merge = source_merges.get(original_topic)
        if merge is not None:
            if original_topic != merge["survivor"]:
                changed += 1
                continue
            replacement = {
                "topic": merge["target"],
                "title": merge["title"],
                "game": merge["game"],
            }
            if any(row.get(field) != value for field, value in replacement.items()):
                row.update(replacement)
                changed += 1
        curated_rows.append(row)
    content = "".join(json.dumps(row, ensure_ascii=False) + "\n" for row in curated_rows).encode("utf-8")
    if not check:
        path.write_bytes(content)
    return changed, content, len(curated_rows)


def rewrite_expansion_topics(
    path: Path,
    *,
    check: bool,
    excluded: set[str],
    source_merges: dict[str, dict[str, object]],
) -> tuple[int, bytes]:
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        rows = list(reader)
        fields = list(reader.fieldnames or [])
    changed = 0
    curated_rows = []
    for row in rows:
        original_topic = snake_case(str(row.get("topic", "")))
        if original_topic in excluded:
            changed += 1
            continue
        merge = source_merges.get(original_topic)
        if merge is not None:
            if original_topic != merge["survivor"]:
                changed += 1
                continue
            replacement = {
                "topic": str(merge["target"]),
                "title": str(merge["title"]),
                "aliases": ",".join(str(alias) for alias in merge["aliases"]),
                "category": str(merge["category"]),
                "game": str(merge["game"]),
            }
            if any(row.get(field) != value for field, value in replacement.items()):
                row.update(replacement)
                changed += 1
        curated_rows.append(row)
    output = StringIO(newline="")
    writer = csv.DictWriter(output, fieldnames=fields, lineterminator="\n")
    writer.writeheader()
    writer.writerows(curated_rows)
    content = output.getvalue().encode("utf-8")
    if not check:
        path.write_bytes(content)
    return changed, content


def rewrite_npc_tags(path: Path, *, check: bool, allowed: set[str]) -> tuple[int, bytes]:
    with path.open(encoding="utf-8", newline="") as handle:
        rows = list(csv.DictReader(handle))
    changed = 0
    for row in rows:
        original = row["worldknowledge_tags"]
        canonical = canonical_npc_tags(original, allowed)
        if canonical != row["worldknowledge_tags"]:
            row["worldknowledge_tags"] = canonical
            changed += 1
        row.setdefault("prior_seed_sha256", hashlib.sha256(original.encode("utf-8")).hexdigest())
    from io import StringIO
    output = StringIO(newline="")
    writer = csv.DictWriter(
        output,
        fieldnames=["npc_name", "worldknowledge_tags", "prior_seed_sha256"],
        lineterminator="\n",
    )
    writer.writeheader()
    writer.writerows(rows)
    content = output.getvalue().encode("utf-8")
    if not check:
        path.write_bytes(content)
    return changed, content


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--check", action="store_true")
    parser.add_argument("--catalog-version", default="parity-v1.7-2026-08-14")
    args = parser.parse_args()

    vocabulary = json.loads(VOCABULARY.read_text(encoding="utf-8"))
    article_access_markers = set(vocabulary.get("article_access_markers", []))
    domains = set(vocabulary["domains"])
    stable_classes = {
        *vocabulary["roles"],
        *vocabulary["factions"],
        *vocabulary["races"],
        *vocabulary["regions"],
        *vocabulary["reserved"],
    }
    tag_frequency = npc_tag_frequency(NPC_TAGS)
    excluded, source_merges, overrides = load_editorial_curation()
    catalog_changes, catalog_content, used_classes, catalog_rows = rewrite_catalog(
        CATALOG,
        check=args.check,
        domains=domains,
        tag_frequency=tag_frequency,
        excluded=excluded,
        source_merges=source_merges,
        overrides=overrides,
    )
    source_changes, source_content, source_count = rewrite_source_snapshot(
        SOURCES,
        check=args.check,
        excluded=excluded,
        source_merges=source_merges,
    )
    expansion_changes, _ = rewrite_expansion_topics(
        EXPANSION_TOPICS,
        check=args.check,
        excluded=excluded,
        source_merges=source_merges,
    )
    npc_changes, _ = rewrite_npc_tags(
        NPC_TAGS,
        check=args.check,
        allowed=(stable_classes | used_classes) - article_access_markers,
    )
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    expected_checksum = hashlib.sha256(catalog_content).hexdigest()
    source_checksum = hashlib.sha256(source_content).hexdigest()
    vocabulary_checksum = hashlib.sha256(VOCABULARY.read_bytes()).hexdigest()
    curation_checksum = hashlib.sha256(EDITORIAL_CURATION.read_bytes()).hexdigest()
    override_checksum = hashlib.sha256(EDITORIAL_OVERRIDES.read_bytes()).hexdigest()
    category_counts = dict(sorted(Counter(row["category"] for row in catalog_rows).items()))
    expected_manifest = json.loads(json.dumps(manifest))
    expected_manifest["catalog_version"] = args.catalog_version
    expected_manifest["checksum_sha256"] = expected_checksum
    expected_manifest["row_count"] = len(catalog_rows)
    expected_manifest["source_snapshot"] = {
        "file": SOURCES.name,
        "checksum_sha256": source_checksum,
        "row_count": source_count,
    }
    expected_manifest["generation"]["editorial_overrides_checksum_sha256"] = override_checksum
    expected_manifest["generation"]["editorial_overrides_applied"] = True
    expected_manifest["generation"]["editorial_curation_checksum_sha256"] = curation_checksum
    expected_manifest["coverage"]["advanced_articles"] = sum(bool(row["topic_desc"]) for row in catalog_rows)
    expected_manifest["coverage"]["tag_assignments"] = sum(
        len([tag for tag in row["tags"].split(",") if tag]) for row in catalog_rows
    )
    expected_manifest["coverage"]["category_counts"] = category_counts
    expected_manifest["editorial_review"]["reviewed_at"] = "2026-08-14T06:00:00Z"
    expected_manifest["editorial_review"]["method"] = (
        "source-bound validation, Fallout-specific scope curation, deterministic checks, "
        "alias ownership and access review, and representative article review"
    )
    expected_manifest["knowledge_vocabulary"] = {
        "file": VOCABULARY.name,
        "checksum_sha256": vocabulary_checksum,
    }
    manifest_changed = (
        manifest != expected_manifest
    )
    if not args.check:
        MANIFEST.write_text(json.dumps(expected_manifest, indent=2) + "\n", encoding="utf-8")
    elif catalog_changes or source_changes or expansion_changes or npc_changes or manifest_changed:
        raise RuntimeError(
            f"Canonicalization required: catalog_fields={catalog_changes} "
            f"sources={source_changes} expansion={expansion_changes} "
            f"npc_rows={npc_changes} manifest={manifest_changed}"
        )
    print(json.dumps({
        "catalog_fields_changed": catalog_changes,
        "catalog_rows": len(catalog_rows),
        "source_rows_changed": source_changes,
        "expansion_rows_changed": expansion_changes,
        "npc_rows_changed": npc_changes,
        "catalog_version": args.catalog_version,
        "checksum_sha256": expected_checksum,
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
