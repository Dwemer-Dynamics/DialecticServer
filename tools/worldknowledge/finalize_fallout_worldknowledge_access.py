#!/usr/bin/env python3
"""Combine access-review shards and create one validated publication input."""

from __future__ import annotations

import argparse
import csv
import importlib.util
import json
import re
from collections import Counter
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
TOOLS = Path(__file__).resolve().parent


def load_module(name: str, path: Path):
    spec = importlib.util.spec_from_file_location(name, path)
    module = importlib.util.module_from_spec(spec)
    assert spec.loader is not None
    spec.loader.exec_module(module)
    return module


ENRICHER = load_module("worldknowledge_enricher", TOOLS / "enrich_fallout_worldknowledge.py")
ACCESS = load_module("worldknowledge_access_reviewer", TOOLS / "review_fallout_worldknowledge_access.py")
ACCESS_NOTE = re.compile(r"Access v2 \(([^;]+); ([^)]+)\):")


def parse_rule(value: str) -> list[list[str]]:
    clauses = []
    for raw_clause in value.split("|"):
        clause = [tag.strip().lower() for tag in raw_clause.split("&") if tag.strip()]
        if clause:
            clauses.append(clause)
    return clauses


def validate_rows(rows: list[dict[str, str]], originals: list[dict[str, str]]) -> dict[str, Any]:
    if len(rows) != len(originals):
        raise RuntimeError("Combined access review does not contain every source row")
    tiers: Counter[str] = Counter()
    regions: Counter[str] = Counter()
    blank_basics = 0
    for row, original in zip(rows, originals):
        topic = ENRICHER.canonical_topic(row["topic"])
        if topic != ENRICHER.canonical_topic(original["topic"]):
            raise RuntimeError(f"Access review order mismatch at {topic}")
        for field in (
            "topic", "tags", "category", "setting", "valid_from_year",
            "valid_to_year", "source_url", "source_revision",
        ):
            if row[field] != original[field]:
                raise RuntimeError(f"Access review changed protected field {field} for {topic}")
        if row["topic_desc"] != original["topic_desc"]:
            raise RuntimeError(f"Access review changed advanced text for {topic}")
        match = ACCESS_NOTE.search(row["editorial_note"])
        if match is None:
            raise RuntimeError(f"Access review metadata is missing for {topic}")
        tier, region = match.groups()
        if tier not in ACCESS.TIERS or region not in ACCESS.REGIONS:
            raise RuntimeError(f"Access review metadata is unsupported for {topic}")
        tiers[tier] += 1
        regions[region] += 1
        advanced = parse_rule(row["knowledge_class"])
        basic = parse_rule(row["knowledge_class_basic"])
        if not advanced or not basic:
            raise RuntimeError(f"Access rules are empty for {topic}")
        for level, clauses in (("advanced", advanced), ("basic", basic)):
            for clause in clauses:
                if any(ACCESS.TAG_PATTERN.fullmatch(tag) is None for tag in clause):
                    raise RuntimeError(f"{topic} {level} rule contains an unsupported tag")
        if any("common" in clause or set(clause).issubset(ACCESS.REGION_TAGS) for clause in advanced):
            raise RuntimeError(f"Advanced access is overbroad for {topic}")
        if tier == "universal_public" and ["common"] not in basic:
            raise RuntimeError(f"Universal basic access is missing for {topic}")
        if tier in {"local_public", "secret", "personal"} and ["common"] in basic:
            raise RuntimeError(f"Basic access is globally overbroad for {topic}")
        if region == "capital_wasteland" and tier in {"regional_public", "local_public"}:
            if not any("region:capital_wasteland" in clause or any(tag.startswith(("community:", "place:", "person:")) for tag in clause) for clause in basic):
                raise RuntimeError(f"Capital basic access lacks a Capital or local boundary for {topic}")
        if region == "mojave" and tier in {"regional_public", "local_public"}:
            if not any("region:mojave" in clause or any(tag.startswith(("community:", "place:", "person:")) for tag in clause) for clause in basic):
                raise RuntimeError(f"Mojave basic access lacks a Mojave or local boundary for {topic}")
        if region == "both" and tier == "regional_public" and any("common" in clause for clause in basic):
            for required_region in ("region:capital_wasteland", "region:mojave"):
                if not any("common" in clause and required_region in clause for clause in basic):
                    raise RuntimeError(f"Cross-region public access lacks {required_region} for {topic}")
        if row["category"] == "person" and [f"person:{topic}"] not in advanced:
            raise RuntimeError(f"Person self-access is missing for {topic}")
        if tier in {"secret", "personal"}:
            if row["topic_desc_basic"]:
                raise RuntimeError(f"Protected basic text was retained for {topic}")
            blank_basics += 1
    issues = ENRICHER.validate_output(rows)
    if issues:
        raise RuntimeError(f"Combined access review has {len(issues)} catalog validation issues: {issues[:3]}")
    return {
        "tier_counts": dict(sorted(tiers.items())),
        "region_counts": dict(sorted(regions.items())),
        "omitted_protected_basics": blank_basics,
    }


def combine_ledgers(paths: list[Path], output: Path, budget: float) -> dict[str, Any]:
    requests = []
    prompt_tokens = 0
    completion_tokens = 0
    spent = 0.0
    for path in paths:
        ledger = json.loads(path.read_text(encoding="utf-8"))
        requests.extend(ledger.get("requests", []))
        prompt_tokens += int(ledger.get("prompt_tokens", 0) or 0)
        completion_tokens += int(ledger.get("completion_tokens", 0) or 0)
        spent += float(ledger.get("spent_usd", 0.0) or 0.0)
    if spent > budget:
        raise RuntimeError(f"Cumulative OpenRouter cost ${spent:.6f} exceeds ${budget:.2f}")
    combined = {
        "schema": "dialectic.openrouter-budget-ledger.v1",
        "model": ACCESS.MODEL,
        "authorized_budget_usd": budget,
        "spent_usd": round(spent, 9),
        "prompt_tokens": prompt_tokens,
        "completion_tokens": completion_tokens,
        "requests": requests,
        "updated_at": ENRICHER.timestamp(),
    }
    ENRICHER.write_json_atomic(output, combined)
    return combined


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, default=ROOT / "data" / "fallout_worldknowledge_parity_v1.csv")
    parser.add_argument("--shard-output", action="append", type=Path, required=True)
    parser.add_argument("--ledger", action="append", type=Path, required=True)
    parser.add_argument("--output", type=Path, required=True)
    parser.add_argument("--review", type=Path, required=True)
    parser.add_argument("--cumulative-ledger", type=Path, required=True)
    parser.add_argument("--budget-usd", type=float, default=30.0)
    args = parser.parse_args()

    originals = ENRICHER.read_output_rows(args.input)
    by_topic: dict[str, dict[str, str]] = {}
    for path in args.shard_output:
        for row in ENRICHER.read_output_rows(path):
            topic = ENRICHER.canonical_topic(row["topic"])
            if topic in by_topic:
                raise RuntimeError(f"Duplicate reviewed topic {topic}")
            by_topic[topic] = row
    missing = [ENRICHER.canonical_topic(row["topic"]) for row in originals if ENRICHER.canonical_topic(row["topic"]) not in by_topic]
    if missing:
        raise RuntimeError(f"Access review is missing {len(missing)} topics: {missing[:5]}")
    rows = [by_topic[ENRICHER.canonical_topic(row["topic"])] for row in originals]
    metrics = validate_rows(rows, originals)
    ledger = combine_ledgers(args.ledger, args.cumulative_ledger, args.budget_usd)

    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=ENRICHER.OUTPUT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    ENRICHER.write_json_atomic(args.review, {
        "schema": "dialectic.worldknowledge-review.v2",
        "model": ACCESS.MODEL,
        "row_count": len(rows),
        "issues": [],
        "category_counts": dict(sorted(Counter(row["category"] for row in rows).items())),
        "advanced_rows": sum(bool(row["topic_desc"]) for row in rows),
        "basic_rows": sum(bool(row["topic_desc_basic"]) for row in rows),
        "tag_assignments": sum(len(row["tags"].split(",")) for row in rows),
        "spent_usd": float(ledger["spent_usd"]),
        "requires_editorial_approval": True,
        "access_metrics": metrics,
        "generated_at": ENRICHER.timestamp(),
    })
    print(json.dumps({"rows": len(rows), "spent_usd": ledger["spent_usd"], **metrics}, sort_keys=True))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
