#!/usr/bin/env python3
"""Review Fallout articles for average-person basics and deterministic access rules."""

from __future__ import annotations

import argparse
import csv
import hashlib
import importlib.util
import json
import os
import re
import time
from pathlib import Path
from typing import Any

ROOT = Path(__file__).resolve().parents[2]
SCRIPT_DIR = Path(__file__).resolve().parent
ENRICHER_PATH = SCRIPT_DIR / "enrich_fallout_worldknowledge.py"
SPEC = importlib.util.spec_from_file_location("worldknowledge_enricher", ENRICHER_PATH)
ENRICHER = importlib.util.module_from_spec(SPEC)
assert SPEC.loader is not None
SPEC.loader.exec_module(ENRICHER)
CANONICAL_SPEC = importlib.util.spec_from_file_location(
    "worldknowledge_tag_canonicalizer", SCRIPT_DIR / "canonicalize_fallout_knowledge_tags.py"
)
CANONICAL = importlib.util.module_from_spec(CANONICAL_SPEC)
assert CANONICAL_SPEC.loader is not None
CANONICAL_SPEC.loader.exec_module(CANONICAL)

DEFAULT_INPUT = ROOT / "data" / "fallout_worldknowledge_parity_v1.csv"
DEFAULT_OUTPUT = SCRIPT_DIR / "output" / "fallout_worldknowledge_oghma_parity.local.csv"
DEFAULT_REVIEW = SCRIPT_DIR / "output" / "fallout_worldknowledge_oghma_parity.review.local.json"
DEFAULT_LEDGER = SCRIPT_DIR / "output" / "openrouter_budget_ledger.local.json"
DEFAULT_CACHE = SCRIPT_DIR / "cache" / "oghma-parity"
DEFAULT_TOPIC_CACHE = SCRIPT_DIR / "cache" / "oghma-parity-topics"
SOURCE_CACHE = SCRIPT_DIR / "cache" / "parity-v1" / "sources"
MODEL = "z-ai/glm-5.2"
TIERS = {
    "universal_public", "regional_public", "local_public", "faction_public",
    "specialist", "secret", "personal",
}
REGIONS = {"global", "capital_wasteland", "mojave", "both"}
REGION_ACCESS_VALUES = {
    "capital_wasteland", "mojave", "point_lookout", "the_pitt", "anchorage",
    "mothership_zeta", "zion", "big_mt", "sierra_madre", "divide",
}
REGION_TAGS = set(REGION_ACCESS_VALUES)
BROAD_REGION_TAGS = {"capital_wasteland", "mojave"}
TAG_PATTERN = re.compile(r"^[a-z0-9][a-z0-9_]{0,100}$")
ROLES = [
    "traveler", "doctor", "engineer", "gunsmith", "historian", "hunter",
    "leader", "medic", "merchant", "raider", "researcher", "scientist",
    "soldier", "survivalist", "tribal", "vault_dweller",
]
DOMAINS = [
    "agriculture", "archaeology", "biology", "chemistry", "energy_weapons", "engineering",
    "explosives", "faction_intelligence", "firearms", "history", "medicine", "military",
    "politics", "prewar_history", "robotics", "survival", "technology", "trade", "vault_tec",
    "wildlife", "xenotechnology",
]
FACTIONS = [
    "boomers", "brotherhood_of_steel", "caesars_legion", "enclave", "followers_of_the_apocalypse",
    "great_khans", "ncr", "powder_gangers", "raider",
]


def serialize_rule(classes: list[str]) -> str:
    return ",".join(classes)


def source_fingerprint(row: dict[str, str]) -> str:
    fields = [row.get(field, "") for field in ENRICHER.OUTPUT_FIELDS]
    return hashlib.sha256(json.dumps(fields, ensure_ascii=False).encode()).hexdigest()


def normalize_access_tag(value: Any) -> str:
    return CANONICAL.canonical_tag(value)


def normalize_classes(value: Any, *, topic: str, level: str, tier: str, category: str) -> list[str]:
    if not isinstance(value, list) or not value:
        raise RuntimeError(f"{topic} {level} access requires at least one class")
    classes = sorted({normalize_access_tag(tag) for tag in value if str(tag).strip()})
    invalid_tags = [tag for tag in classes if TAG_PATTERN.fullmatch(tag) is None]
    if not classes or invalid_tags:
        detail = ", ".join(invalid_tags) if invalid_tags else "empty class list"
        raise RuntimeError(f"{topic} {level} contains unsupported access tag(s): {detail}")
    if level == "advanced":
        classes = [tag for tag in classes if tag != "common" and tag not in BROAD_REGION_TAGS]
    if len(classes) > (5 if level == "advanced" else 4):
        raise RuntimeError(f"{topic} {level} returned too many classes")
    if level == "basic" and tier == "local_public" and "common" in classes:
        raise RuntimeError(f"{topic} cannot expose {tier} basics globally")
    if level == "advanced" and category == "person" and topic not in classes:
        classes.insert(0, topic)
    return classes


def advanced_fallback(topic: str, category: str, region: str) -> list[str]:
    if category == "person":
        return [topic]
    if category == "location":
        return [topic]
    if category in {"faction", "organization"}:
        return [topic]
    if category in {"medicine"}:
        return ["doctor"]
    if category in {"technology", "robot", "artifact"}:
        return ["engineer"]
    if category in {"weapon", "armor"}:
        return ["gunsmith"]
    if category in {"creature", "flora", "food_drink"}:
        return ["hunter"]
    return ["historian"]


def validate_result(source: dict[str, str], result: dict[str, Any]) -> dict[str, str]:
    topic = source["topic"].split(",", 1)[0]
    if str(result.get("topic", "")).strip() != topic:
        raise RuntimeError(f"topic mismatch for {topic}")
    tier = str(result.get("awareness_tier", "")).strip().lower()
    region = str(result.get("normalized_region", "")).strip().lower()
    if tier not in TIERS or region not in REGIONS:
        raise RuntimeError(f"{topic} returned an unsupported tier or region")
    basic = ENRICHER.normalize_space(str(result.get("basic_article", "")))
    if basic:
        words = len(basic.split())
        if not 20 <= words <= 110 or ENRICHER.article_policy_issue(basic):
            raise RuntimeError(f"{topic} basic article failed prose policy")
    elif tier not in {"secret", "personal"}:
        raise RuntimeError(f"{topic} may omit basic text only when secret or personal")
    note = ENRICHER.normalize_space(str(result.get("access_note", "")))
    if not 4 <= len(note.split()) <= 80:
        raise RuntimeError(f"{topic} access note failed length validation")
    advanced_rules = normalize_classes(
        result.get("advanced_allow_any"), topic=topic, level="advanced", tier=tier, category=source["category"]
    )
    if not advanced_rules:
        advanced_rules = advanced_fallback(topic, source["category"], region)
    basic_rules = normalize_classes(
        result.get("basic_allow_any"), topic=topic, level="basic", tier=tier, category=source["category"]
    )
    # Secret and personal subjects have no average-person summary. Reuse their
    # stricter advanced audience instead of allowing a model-supplied public rule.
    if tier in {"secret", "personal"}:
        basic = ""
        basic_rules = advanced_rules
    elif tier == "local_public" and "common" in basic_rules:
        boundary = region if region in {"capital_wasteland", "mojave"} else topic
        basic_rules = [boundary if value == "common" else value for value in basic_rules]
    elif tier == "regional_public" and region == "both" and "common" in basic_rules:
        basic_rules = ["capital_wasteland", "mojave", *[value for value in basic_rules if value != "common"]]
    if tier == "universal_public" and "common" not in basic_rules:
        raise RuntimeError(f"{topic} universal public rule is not available to ordinary people")
    if region in {"capital_wasteland", "mojave"} and tier in {"regional_public", "local_public"}:
        regional_tag = region
        has_boundary = regional_tag in basic_rules or any(
            tag not in {"common", *ROLES, *DOMAINS, *FACTIONS, *BROAD_REGION_TAGS}
            for tag in basic_rules
        )
        if not has_boundary:
            basic_rules = [regional_tag if tag == "common" else tag for tag in basic_rules]
            if regional_tag not in basic_rules:
                basic_rules.append(regional_tag)
    if region == "both" and tier == "regional_public":
        for required_region in ("capital_wasteland", "mojave"):
            if required_region not in basic_rules:
                raise RuntimeError(f"{topic} cross-region public rule lacks {required_region}")
    editorial = ENRICHER.normalize_space(source.get("editorial_note", ""))
    access_note = f"Oghma parity ({tier}; {region}): {note}"
    return {
        **source,
        "topic_desc_basic": basic,
        "knowledge_class_basic": serialize_rule(basic_rules),
        "knowledge_class": serialize_rule(advanced_rules),
        "region": region,
        "editorial_note": " ".join(filter(None, [editorial, access_note])),
    }


def cache_reviewed_topic(
    topic_cache_dir: Path,
    source: dict[str, str],
    result: dict[str, Any],
) -> dict[str, str]:
    validated = validate_result(source, result)
    topic = source["topic"].split(",", 1)[0]
    ENRICHER.write_json_atomic(topic_cache_dir / f"{topic}.json", {
        "source_fingerprint": source_fingerprint(source),
        "result": result,
        "model": MODEL,
    })
    return validated


def generate_batch(api_key: str, base_url: str, batch: list[dict[str, Any]], max_tokens: int, feedback: str) -> tuple[dict[str, Any], dict[str, Any]]:
    system = (
        "You are the access-control editor for a static in-world Fallout 3, Fallout: New Vegas, official DLC, and TTW lore catalog. "
        "For every supplied article, rewrite BASIC knowledge as only what an average ordinary person in the appropriate region or community could plausibly know. "
        "Basic prose must be 25-90 words, public or observable, and must remove private biography, hidden identities, secret interiors, exact technical operation, inventory/location lists, game mechanics, and mutable quest outcomes. "
        "Rumors must be explicitly described as rumors. For a truly secret or personal topic, basic_article may be empty. Do not rewrite the advanced article. "
        "Classify awareness as universal_public, regional_public, local_public, faction_public, specialist, secret, or personal. Normalize geography to global, capital_wasteland, mojave, or both. "
        "Return flat any-of knowledge class lists. Any single matching class grants that tier. Basic classes describe average public awareness in the correct geography. "
        "Advanced rules are only for relevant experts, faction insiders, local participants, direct associates, or the person themself; never grant advanced access through common or geography alone. "
        "Use only plain lowercase snake-case knowledge IDs with no namespace prefixes. Tags use underscores, never spaces, hyphens, or colons. global and both are not access tags; use common for global basic knowledge and role/domain combinations for global advanced knowledge. "
        "Choose only classes whose members would independently know the subject; do not encode combinations or nested rules. "
        "People and obscure local sites must not be global common knowledge. Capital and Mojave regional knowledge must remain separated unless trade, national importance, or a shared subject genuinely justifies both. "
        "Return exactly one result for each supplied topic and preserve source uncertainty."
    )
    if feedback:
        system += " Previous output was rejected: " + feedback
    schema = {
        "type": "object",
        "properties": {"articles": {"type": "array", "minItems": len(batch), "maxItems": len(batch), "items": {
            "type": "object",
            "properties": {
                "topic": {"type": "string"},
                "basic_article": {"type": "string"},
                "awareness_tier": {"type": "string", "enum": sorted(TIERS)},
                "normalized_region": {"type": "string", "enum": sorted(REGIONS)},
                "basic_allow_any": {"type": "array", "minItems": 1, "maxItems": 4, "items": {"type": "string"}},
                "advanced_allow_any": {"type": "array", "minItems": 1, "maxItems": 5, "items": {"type": "string"}},
                "access_note": {"type": "string"},
            },
            "required": ["topic", "basic_article", "awareness_tier", "normalized_region", "basic_allow_any", "advanced_allow_any", "access_note"],
            "additionalProperties": False,
        }}},
        "required": ["articles"],
        "additionalProperties": False,
    }
    payload = {
        "model": MODEL,
        "temperature": 0.1,
        "max_tokens": max_tokens,
        "reasoning": {"effort": "low", "exclude": True},
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": json.dumps({
                "controlled_roles": ROLES,
                "controlled_domains": DOMAINS,
                "controlled_factions": FACTIONS,
                "controlled_regions": sorted(REGION_ACCESS_VALUES),
                "tag_examples": ["common", "mojave", "big_mt", "mothership_zeta", "vault_101", "goodsprings", "amata", "doctor", "medicine", "ncr", "ghoul"],
                "articles": batch,
            }, ensure_ascii=False)},
        ],
        "response_format": {"type": "json_schema", "json_schema": {"name": "dialectic_access_review", "strict": True, "schema": schema}},
    }
    response = ENRICHER.request_json(
        f"{base_url.rstrip('/')}/chat/completions", method="POST", payload=payload,
        headers={"Authorization": f"Bearer {api_key}"}, timeout=180.0, retries=2,
    )
    return response, payload


def parse_articles(response: dict[str, Any]) -> list[dict[str, Any]]:
    return ENRICHER.parse_content(response)


class AccessValidationError(RuntimeError):
    """Distinguish rejected model content from transport and budget failures."""


def generate_validated_batch(
    *,
    api_key: str,
    base_url: str,
    batch: list[dict[str, Any]],
    max_tokens: int,
    by_topic: dict[str, dict[str, str]],
    prompt_price: float,
    completion_price: float,
    ledger: dict[str, Any],
    ledger_path: Path,
    budget_usd: float,
    batch_hash: str,
    progress_label: str,
    initial_feedback: str = "",
) -> tuple[list[dict[str, Any]], dict[str, Any]]:
    """Generate one batch and persist every paid attempt before accepting it."""
    feedback = initial_feedback
    last_payload: dict[str, Any] = {}
    for attempt in range(1, 6):
        response, last_payload = generate_batch(api_key, base_url, batch, max_tokens, feedback)
        usage = response.get("usage", {})
        cost = float(usage.get("cost", 0.0) or 0.0)
        if cost <= 0:
            cost = int(usage.get("prompt_tokens", 0) or 0) * prompt_price + int(usage.get("completion_tokens", 0) or 0) * completion_price
        if float(ledger["spent_usd"]) + cost > budget_usd:
            raise RuntimeError("Provider response would exceed the authorized budget")
        parse_error = ""
        results: list[dict[str, Any]] = []
        try:
            results = parse_articles(response)
            if len(results) != len(batch):
                raise RuntimeError("response batch length mismatch")
            for item, result in zip(batch, results):
                validate_result(by_topic[item["topic"]], result)
        except (RuntimeError, json.JSONDecodeError) as exc:
            parse_error = str(exc)
            feedback = parse_error
            results = []
        ledger["spent_usd"] = round(float(ledger["spent_usd"]) + cost, 9)
        ledger["prompt_tokens"] = int(ledger["prompt_tokens"]) + int(usage.get("prompt_tokens", 0) or 0)
        ledger["completion_tokens"] = int(ledger["completion_tokens"]) + int(usage.get("completion_tokens", 0) or 0)
        ledger["requests"].append({
            "purpose": "oghma-parity-review", "batch_hash": batch_hash, "topics": [item["topic"] for item in batch],
            "attempt": attempt, "valid_shape": bool(results), "cost_usd": cost,
            "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0), "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
            "parse_error": parse_error, "completed_at": ENRICHER.timestamp(),
        })
        ledger["updated_at"] = ENRICHER.timestamp()
        ENRICHER.write_json_atomic(ledger_path, ledger)
        print(f"[review] {progress_label} attempt={attempt} cost=${cost:.6f} total=${float(ledger['spent_usd']):.6f}")
        if results:
            return results, last_payload
    raise AccessValidationError(feedback or "provider output failed validation")


def main() -> int:
    parser = argparse.ArgumentParser()
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--review", type=Path, default=DEFAULT_REVIEW)
    parser.add_argument("--ledger", type=Path, default=DEFAULT_LEDGER)
    parser.add_argument("--cache-dir", type=Path, default=DEFAULT_CACHE)
    parser.add_argument("--topic-cache-dir", type=Path, default=DEFAULT_TOPIC_CACHE)
    parser.add_argument("--openrouter-url", default="https://openrouter.ai/api/v1")
    parser.add_argument("--budget-usd", type=float, default=30.0)
    parser.add_argument("--batch-size", type=int, default=6)
    parser.add_argument("--max-tokens", type=int, default=5200)
    parser.add_argument("--delay", type=float, default=0.15)
    parser.add_argument("--dry-run", action="store_true")
    parser.add_argument("--preflight-only", action="store_true")
    parser.add_argument("--shard-index", type=int, default=0)
    parser.add_argument("--shard-count", type=int, default=1)
    args = parser.parse_args()

    rows = ENRICHER.read_output_rows(args.input)
    if args.shard_count < 1 or not 0 <= args.shard_index < args.shard_count:
        raise RuntimeError("Shard index must be within the configured shard count")
    if args.shard_count > 1:
        rows = [row for index, row in enumerate(rows) if index % args.shard_count == args.shard_index]
    prepared = []
    for row in rows:
        topic = row["topic"].split(",", 1)[0]
        source_path = SOURCE_CACHE / f"{topic}.json"
        source = json.loads(source_path.read_text(encoding="utf-8")) if source_path.exists() else {}
        prepared.append({
            "topic": topic,
            "title_and_aliases": row["topic"],
            "category": row["category"],
            "setting": row["setting"],
            "current_region": row["region"],
            "current_basic_article": row["topic_desc_basic"],
            "advanced_article": row["topic_desc"],
            "source_extract": ENRICHER.normalize_space(str(source.get("source_extract", "")))[:2000],
        })

    by_topic = {row["topic"].split(",", 1)[0]: row for row in rows}
    reviewed: dict[str, dict[str, str]] = {}
    pending = []
    args.topic_cache_dir.mkdir(parents=True, exist_ok=True)
    for item in prepared:
        topic_cache_path = args.topic_cache_dir / f"{item['topic']}.json"
        if topic_cache_path.exists():
            cached = json.loads(topic_cache_path.read_text(encoding="utf-8"))
            result = cached.get("result", {})
            if cached.get("source_fingerprint") == source_fingerprint(by_topic[item["topic"]]):
                try:
                    reviewed[item["topic"]] = validate_result(by_topic[item["topic"]], result)
                    continue
                except RuntimeError:
                    pass
        pending.append(item)

    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if pending and not api_key and not args.dry_run:
        raise RuntimeError("OPENROUTER_API_KEY is required")
    ledger = ENRICHER.read_ledger(args.ledger, MODEL, args.budget_usd)
    if float(ledger["authorized_budget_usd"]) != args.budget_usd or float(ledger["spent_usd"]) > args.budget_usd:
        raise RuntimeError("Budget ledger does not match the authorized cap")
    prompt_price, completion_price = (0.0, 0.0) if args.dry_run or not pending else ENRICHER.model_prices(args.openrouter_url, api_key, MODEL)
    batches = [pending[index:index + args.batch_size] for index in range(0, len(pending), args.batch_size)]
    estimated_prompt = sum(ENRICHER.estimated_tokens(json.dumps(batch, ensure_ascii=False)) + 1800 for batch in batches)
    worst_case = estimated_prompt * prompt_price + len(batches) * args.max_tokens * completion_price
    remaining = args.budget_usd - float(ledger["spent_usd"])
    print(f"[preflight] rows={len(rows)} cached_topics={len(reviewed)} requests={len(batches)} remaining=${remaining:.6f} worst_case=${worst_case:.6f}")
    if not args.dry_run and worst_case > remaining:
        raise RuntimeError("Worst-case access review exceeds the remaining authorized budget")
    if args.dry_run or args.preflight_only:
        return 0

    args.cache_dir.mkdir(parents=True, exist_ok=True)
    for batch_index, batch in enumerate(batches, 1):
        digest = hashlib.sha256(json.dumps(["oghma-parity-v1", MODEL, batch], ensure_ascii=False, sort_keys=True).encode()).hexdigest()
        cache_path = args.cache_dir / f"{digest}.json"
        results: list[dict[str, Any]] = []
        feedback = ""
        if cache_path.exists():
            cached = json.loads(cache_path.read_text(encoding="utf-8"))
            results = cached.get("articles", [])
            try:
                if len(results) != len(batch):
                    raise RuntimeError("cached batch length mismatch")
                for item, result in zip(batch, results):
                    validate_result(by_topic[item["topic"]], result)
                print(f"[review] cache {batch_index}/{len(batches)}")
            except RuntimeError as exc:
                feedback = str(exc)
                results = []
        if not results:
            try:
                results, request_payload = generate_validated_batch(
                    api_key=api_key, base_url=args.openrouter_url, batch=batch, max_tokens=args.max_tokens,
                    by_topic=by_topic, prompt_price=prompt_price, completion_price=completion_price,
                    ledger=ledger, ledger_path=args.ledger, budget_usd=args.budget_usd,
                    batch_hash=digest, progress_label=f"{batch_index}/{len(batches)}",
                    initial_feedback=feedback,
                )
            except AccessValidationError as batch_error:
                if len(batch) == 1:
                    raise RuntimeError(f"Access review failed for batch {batch_index}: {batch_error}") from batch_error
                print(f"[review] {batch_index}/{len(batches)} isolating topics after: {batch_error}")
                results = []
                for item_index, item in enumerate(batch, 1):
                    single_hash = hashlib.sha256(json.dumps(["oghma-parity-v1-single", MODEL, item], ensure_ascii=False, sort_keys=True).encode()).hexdigest()
                    single_results, _ = generate_validated_batch(
                        api_key=api_key, base_url=args.openrouter_url, batch=[item], max_tokens=args.max_tokens,
                        by_topic=by_topic, prompt_price=prompt_price, completion_price=completion_price,
                        ledger=ledger, ledger_path=args.ledger, budget_usd=args.budget_usd,
                        batch_hash=single_hash,
                        progress_label=f"{batch_index}/{len(batches)} single={item_index}/{len(batch)}",
                    )
                    topic = item["topic"]
                    reviewed[topic] = cache_reviewed_topic(
                        args.topic_cache_dir, by_topic[topic], single_results[0]
                    )
                    results.extend(single_results)
                request_payload = {"isolated_fallback_topics": [item["topic"] for item in batch]}
            ENRICHER.write_json_atomic(cache_path, {"articles": results, "request_hash": hashlib.sha256(json.dumps(request_payload, sort_keys=True).encode()).hexdigest()})
            time.sleep(args.delay)
        for item, result in zip(batch, results):
            reviewed[item["topic"]] = cache_reviewed_topic(
                args.topic_cache_dir, by_topic[item["topic"]], result
            )

    output_rows = [reviewed[row["topic"].split(",", 1)[0]] for row in rows]
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=ENRICHER.OUTPUT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(output_rows)
    ENRICHER.write_json_atomic(args.review, {
        "schema": "dialectic.worldknowledge-access-review.v2", "model": MODEL, "rows": len(output_rows),
        "spent_usd": float(ledger["spent_usd"]), "issues": [], "generated_at": ENRICHER.timestamp(),
    })
    print(f"[done] rows={len(output_rows)} total=${float(ledger['spent_usd']):.6f} output={args.output}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
