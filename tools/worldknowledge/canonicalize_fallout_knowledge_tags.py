#!/usr/bin/env python3
"""Publish flat Oghma-style Fallout knowledge classes and compact NPC tags."""

from __future__ import annotations

import argparse
from collections import Counter
import csv
import hashlib
import json
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
CATALOG = ROOT / "data" / "fallout_worldknowledge_parity_v1.csv"
NPC_TAGS = ROOT / "data" / "fallout_worldknowledge_npc_tags.csv"
MANIFEST = ROOT / "data" / "fallout_worldknowledge_manifest.json"
VOCABULARY = ROOT / "data" / "fallout_worldknowledge_vocabulary.json"
KNOWN_NAMESPACES = {"person", "region", "community", "place", "faction", "role", "domain", "race"}
TAG_PATTERN = re.compile(r"^[a-z0-9][a-z0-9_]{0,100}$")
RETRIEVAL_PHRASES = {
    "brotherhood_of_steel": "technology hoarding",
    "caesars_legion": "slave army,roman war culture",
    "new_california_republic": "california republic army",
}

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


def rewrite_catalog(
    path: Path,
    *,
    check: bool,
    domains: set[str],
    tag_frequency: Counter[str],
) -> tuple[int, bytes, set[str]]:
    with path.open(encoding="utf-8", newline="") as handle:
        reader = csv.DictReader(handle)
        rows = list(reader)
        fields = list(reader.fieldnames or [])
    if "retrieval_phrases" not in fields:
        fields.insert(fields.index("tags"), "retrieval_phrases")
    changed = 0
    used_classes = {"common", "knowall"}
    for row in rows:
        for field in ("knowledge_class", "knowledge_class_basic"):
            canonical = flat_oghma_rule(row[field], domains, tag_frequency)
            if canonical != row[field]:
                row[field] = canonical
                changed += 1
            used_classes.update(tag.lstrip("!") for tag in canonical.split(",") if tag)
        canonical_topic = snake_case(str(row.get("topic", "")).split(",", 1)[0])
        retrieval_phrases = RETRIEVAL_PHRASES.get(canonical_topic, "")
        if row.get("retrieval_phrases", "") != retrieval_phrases:
            row["retrieval_phrases"] = retrieval_phrases
            changed += 1
        editorial = str(row.get("editorial_note", "")).replace("Access v2 (", "Oghma parity (")
        editorial = re.sub(
            r"\b(?:faction|region|role|domain|person|place|community|race):([a-z0-9_]+)",
            r"\1",
            editorial,
        )
        if re.search(r"Oghma parity \([^)]+\):.*\b(?:AND|OR)\b", editorial):
            editorial = re.sub(
                r"Oghma parity \(([^)]+)\):.*$",
                r"Oghma parity (\1): Access was converted to reviewed flat knowledge classes.",
                editorial,
            )
        if editorial != row.get("editorial_note", ""):
            row["editorial_note"] = editorial
            changed += 1
    from io import StringIO
    output = StringIO(newline="")
    writer = csv.DictWriter(output, fieldnames=fields, lineterminator="\n")
    writer.writeheader()
    writer.writerows(rows)
    content = output.getvalue().encode("utf-8")
    if not check:
        path.write_bytes(content)
    return changed, content, used_classes


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
    parser.add_argument("--catalog-version", default="parity-v1.5-2026-08-13")
    args = parser.parse_args()

    vocabulary = json.loads(VOCABULARY.read_text(encoding="utf-8"))
    domains = set(vocabulary["domains"])
    stable_classes = {
        *vocabulary["roles"],
        *vocabulary["factions"],
        *vocabulary["races"],
        *vocabulary["regions"],
        *vocabulary["reserved"],
    }
    tag_frequency = npc_tag_frequency(NPC_TAGS)
    catalog_changes, catalog_content, used_classes = rewrite_catalog(
        CATALOG,
        check=args.check,
        domains=domains,
        tag_frequency=tag_frequency,
    )
    npc_changes, _ = rewrite_npc_tags(
        NPC_TAGS,
        check=args.check,
        allowed=stable_classes | used_classes,
    )
    manifest = json.loads(MANIFEST.read_text(encoding="utf-8"))
    expected_checksum = hashlib.sha256(catalog_content).hexdigest()
    vocabulary_checksum = hashlib.sha256(VOCABULARY.read_bytes()).hexdigest()
    manifest_changed = (
        manifest.get("catalog_version") != args.catalog_version
        or manifest.get("checksum_sha256") != expected_checksum
        or manifest.get("knowledge_vocabulary", {}).get("checksum_sha256") != vocabulary_checksum
    )
    if not args.check:
        manifest["catalog_version"] = args.catalog_version
        manifest["checksum_sha256"] = expected_checksum
        manifest["knowledge_vocabulary"] = {
            "file": VOCABULARY.name,
            "checksum_sha256": vocabulary_checksum,
        }
        MANIFEST.write_text(json.dumps(manifest, indent=2) + "\n", encoding="utf-8")
    elif catalog_changes or npc_changes or manifest_changed:
        raise RuntimeError(
            f"Canonicalization required: catalog_fields={catalog_changes} "
            f"npc_rows={npc_changes} manifest={manifest_changed}"
        )
    print(json.dumps({
        "catalog_fields_changed": catalog_changes,
        "npc_rows_changed": npc_changes,
        "catalog_version": args.catalog_version,
        "checksum_sha256": expected_checksum,
    }, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
