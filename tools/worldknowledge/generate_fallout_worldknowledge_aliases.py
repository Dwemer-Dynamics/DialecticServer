#!/usr/bin/env python3
"""Add reviewed CHIM-style aliases to Dialectic world knowledge topics."""

from __future__ import annotations

import argparse
import csv
import hashlib
import html
import json
import os
import re
import time
from pathlib import Path
from typing import Any, Iterable

from generate_fallout_worldknowledge_basic import (
    DEFAULT_MODEL,
    DEFAULT_OPENROUTER_URL,
    OUTPUT_FIELDS,
    ascii_punctuation,
    canonical_topic,
    comparable_topic,
    normalize_space,
    request_json,
    topic_key,
    topic_parts,
    validate_dataset,
    write_csv,
)


SCRIPT_DIR = Path(__file__).resolve().parent
ROOT_DIR = SCRIPT_DIR.parent.parent
DEFAULT_INPUT = ROOT_DIR / "data" / "fallout_worldknowledge_basic.csv"
DEFAULT_SOURCES = ROOT_DIR / "data" / "fallout_worldknowledge_sources.jsonl"
DEFAULT_OUTPUT = SCRIPT_DIR / "output" / "fallout_worldknowledge_with_aliases.local.csv"
DEFAULT_REPORT = SCRIPT_DIR / "output" / "fallout_worldknowledge_aliases_report.local.json"
DEFAULT_CACHE_DIR = SCRIPT_DIR / "cache" / "aliases"

GENERIC_ALIASES = {
    "america",
    "army",
    "base",
    "boss",
    "brotherhood",
    "camp",
    "captain",
    "casino",
    "city",
    "creature",
    "doctor",
    "elder",
    "faction",
    "ghoul",
    "leader",
    "legion",
    "location",
    "man",
    "merchant",
    "mr",
    "mrs",
    "ms",
    "mutant",
    "outpost",
    "person",
    "place",
    "ranger",
    "robot",
    "settlement",
    "soldier",
    "town",
    "vault",
    "woman",
}

BAD_ALIAS_PREFIXES = (
    "also ",
    "called ",
    "currently ",
    "formerly ",
    "known ",
    "most recently ",
    "referred ",
    "reformed ",
    "simply ",
)


def read_dataset(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != OUTPUT_FIELDS:
            raise RuntimeError("Input CSV does not match the Dialectic seven-column contract")
        return [{key: str(value or "").strip() for key, value in row.items()} for row in reader]


def read_sources(path: Path) -> dict[str, dict[str, Any]]:
    sources: dict[str, dict[str, Any]] = {}
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            row = json.loads(line)
            topic = topic_key(str(row.get("topic", "")))
            if topic:
                sources[topic] = row
    return sources


def clean_source_title(value: str) -> str:
    value = html.unescape(ascii_punctuation(value)).strip()
    value = re.sub(r"^Overview:\s*", "", value, flags=re.IGNORECASE)
    value = re.sub(r"\s*\([^)]*\)\s*$", "", value)
    return normalize_space(value)


def comparable_without_article(value: str) -> str:
    value = comparable_topic(value)
    return re.sub(r"^the\s+", "", value)


def comparable_singular(value: str) -> str:
    words = comparable_without_article(value).split()
    if not words:
        return ""
    last = words[-1]
    if last.endswith("ies") and len(last) > 4:
        words[-1] = last[:-3] + "y"
    elif last.endswith("es") and len(last) > 4 and not last.endswith(("sses", "uses")):
        words[-1] = last[:-2]
    elif last.endswith("s") and len(last) > 3 and not last.endswith(("ss", "us")):
        words[-1] = last[:-1]
    return " ".join(words)


def is_simple_plural(alias: str, canonical: str) -> bool:
    alias_value = comparable_without_article(alias)
    canonical_value = comparable_without_article(canonical)
    if alias_value in {canonical_value + "s", canonical_value + "es"}:
        return True
    return canonical_value.endswith("y") and alias_value == canonical_value[:-1] + "ies"


def title_acronyms(value: str) -> set[str]:
    words = re.findall(r"[A-Za-z0-9]+", value)
    if len(words) < 2:
        return set()
    ignored = {"a", "an", "and", "for", "of", "the", "to"}
    significant = [word for word in words if word.lower() not in ignored]
    results = {"".join(word[0] for word in words).lower()}
    if significant:
        results.add("".join(word[0] for word in significant).lower())
    return {value for value in results if 2 <= len(value) <= 8}


def alias_has_source_evidence(alias: str, row: dict[str, str], source: dict[str, Any]) -> bool:
    comparable = comparable_topic(alias)
    source_title = clean_source_title(str(source.get("title", "")))
    description = row.get("topic_desc_basic", "")
    evidence = comparable_topic(source_title + " " + description)
    if f" {comparable} " in f" {evidence} ":
        description_comparable = comparable_topic(description)
        cue_pattern = re.compile(
            r"(?:also known as|born|called|designated|known as|known by|named|nicknamed|"
            r"pronounced|referred to as|shortened to).{0,70}\b" + re.escape(comparable) + r"\b"
        )
        if cue_pattern.search(description_comparable):
            return True

        first_sentence = re.split(r"(?<=[.!?])\s+", description, maxsplit=1)[0]
        subject_match = re.match(r"^(?:A|An|The)\s+(.{2,90}?)\s+(?:are|is|was|were)\b|^(.{2,90}?)\s+(?:are|is|was|were)\b", first_sentence, flags=re.IGNORECASE)
        subject = ""
        if subject_match:
            subject = comparable_topic(subject_match.group(1) or subject_match.group(2) or "")
        if comparable_without_article(alias) == comparable_without_article(subject):
            return True
        if row.get("category") == "person" and f" {comparable} " in f" {subject} ":
            return True
    compact_alias = re.sub(r"[^a-z0-9]", "", alias.lower())
    return compact_alias in title_acronyms(source_title or canonical_topic(row.get("topic", "")).replace("_", " "))


def split_alias_list(value: str) -> list[str]:
    value = re.sub(r"\s+(?:and|or)\s+", ",", value, flags=re.IGNORECASE)
    return [part.strip(" \t\r\n.\"'") for part in value.split(",") if part.strip(" \t\r\n.\"'")]


def deterministic_aliases(row: dict[str, str], source: dict[str, Any]) -> list[str]:
    topic = canonical_topic(row.get("topic", ""))
    description = html.unescape(row.get("topic_desc_basic", ""))
    aliases: list[str] = []

    source_title = clean_source_title(str(source.get("title", "")))
    if source_title:
        aliases.append(source_title)

    patterns = [
        r"\b(?:often|commonly)\s+abbreviated\s+as\s+(?:the\s+)?([^,.;()]+)",
        r"\b(?:often|commonly)\s+(?:called|known as)\s+(?:the\s+)?([^,.;()]+)",
        r"\bknown by many names, including\s+([^.;]+)",
    ]
    for pattern in patterns:
        for match in re.finditer(pattern, description, flags=re.IGNORECASE):
            aliases.extend(split_alias_list(match.group(1)))

    first_sentence = re.split(r"(?<=[.!?])\s+", description, maxsplit=1)[0]
    subject = re.match(r"^(?:A|An|The)\s+(.{2,90}?)\s+(?:are|is|was|were)\b|^(.{2,90}?)\s+(?:are|is|was|were)\b", first_sentence, flags=re.IGNORECASE)
    if subject:
        aliases.append((subject.group(1) or subject.group(2) or "").strip())

    if topic == "ed_e":
        aliases.extend(["Eddie", "E-D-E", "Eyebot Duraframe Subject E"])

    return aliases


def alias_cache_path(cache_dir: Path, topic: str) -> Path:
    return cache_dir / f"{topic}.json"


def alias_input_hash(topic: str, title: str, category: str, description: str, model: str) -> str:
    payload = json.dumps([topic, title, category, description, model], ensure_ascii=False)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def read_cached_aliases(
    cache_dir: Path,
    topic: str,
    input_hash: str,
) -> list[str] | None:
    path = alias_cache_path(cache_dir, topic)
    if not path.exists():
        return None
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return None
    if payload.get("input_hash") != input_hash:
        return None
    aliases = payload.get("aliases")
    return [str(alias) for alias in aliases] if isinstance(aliases, list) else None


def write_cached_aliases(
    cache_dir: Path,
    topic: str,
    input_hash: str,
    model: str,
    aliases: list[str],
) -> None:
    cache_dir.mkdir(parents=True, exist_ok=True)
    alias_cache_path(cache_dir, topic).write_text(
        json.dumps(
            {
                "topic": topic,
                "model": model,
                "input_hash": input_hash,
                "aliases": aliases,
                "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )


def chunked(values: list[dict[str, str]], size: int) -> Iterable[list[dict[str, str]]]:
    for index in range(0, len(values), size):
        yield values[index : index + size]


def generate_alias_batch(
    api_url: str,
    api_key: str,
    model: str,
    batch: list[dict[str, str]],
) -> dict[str, list[str]]:
    system_prompt = (
        "You create alternate search names for a curated Fallout 3 and Fallout: New Vegas lore database. "
        "Return only aliases for the exact subject of each supplied article. Allowed aliases include established "
        "acronyms, nicknames, titles used as names, former names, common short names, and meaningful spelling or "
        "pronunciation variants. Do not return the canonical name with only spaces or capitalization changed. "
        "Do not return generic roles, creature classes, locations, related people, related factions, descriptions, "
        "or aliases belonging to another subject. Use only information supported by the supplied title and summary. "
        "Return zero to eight concise aliases per topic."
    )
    payload = {
        "model": model,
        "temperature": 0.1,
        "reasoning": {"effort": "low", "exclude": True},
        "max_tokens": 3000,
        "messages": [
            {"role": "system", "content": system_prompt},
            {"role": "user", "content": json.dumps(batch, ensure_ascii=False)},
        ],
        "response_format": {
            "type": "json_schema",
            "json_schema": {
                "name": "fallout_world_knowledge_aliases",
                "strict": True,
                "schema": {
                    "type": "object",
                    "properties": {
                        "topics": {
                            "type": "array",
                            "items": {
                                "type": "object",
                                "properties": {
                                    "topic": {"type": "string"},
                                    "aliases": {
                                        "type": "array",
                                        "items": {"type": "string"},
                                        "maxItems": 8,
                                    },
                                },
                                "required": ["topic", "aliases"],
                                "additionalProperties": False,
                            },
                        }
                    },
                    "required": ["topics"],
                    "additionalProperties": False,
                },
            },
        },
    }
    response = request_json(
        api_url,
        method="POST",
        payload=payload,
        headers={
            "Authorization": f"Bearer {api_key}",
            "HTTP-Referer": "https://dwemerdynamics.com",
            "X-Title": "Dialectic Fallout World Knowledge Aliases",
        },
        timeout=180.0,
    )
    content = str(response.get("choices", [{}])[0].get("message", {}).get("content", "")).strip()
    if not content:
        raise RuntimeError("OpenRouter returned no alias content")
    cleaned = re.sub(r"^```(?:json)?\s*", "", content, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s*```$", "", cleaned)
    try:
        parsed = json.loads(cleaned)
    except json.JSONDecodeError:
        object_match = re.search(r"\{.*\}", cleaned, flags=re.DOTALL)
        if not object_match:
            raise RuntimeError("OpenRouter returned invalid alias JSON")
        parsed = json.loads(object_match.group(0))
    if isinstance(parsed, list):
        parsed_topics = parsed
    elif isinstance(parsed, dict):
        parsed_topics = parsed.get("topics", [])
    else:
        raise RuntimeError("OpenRouter returned an unsupported alias JSON shape")
    result: dict[str, list[str]] = {}
    for item in parsed_topics:
        if not isinstance(item, dict):
            continue
        topic = topic_key(str(item.get("topic", "")))
        aliases = item.get("aliases", [])
        if topic and isinstance(aliases, list):
            result[topic] = [str(alias).strip() for alias in aliases if str(alias).strip()]
    return result


def sanitize_alias(alias: str) -> str:
    alias = normalize_space(html.unescape(ascii_punctuation(str(alias))))
    return alias.strip(" \t\r\n.\"'")


def build_alias_topics(
    rows: list[dict[str, str]],
    sources: dict[str, dict[str, Any]],
    generated: dict[str, list[str]],
) -> tuple[list[dict[str, str]], dict[str, Any]]:
    canonical_owners = {
        comparable_topic(canonical_topic(row.get("topic", ""))): canonical_topic(row.get("topic", ""))
        for row in rows
    }
    candidates: dict[str, list[str]] = {}
    rejected: list[dict[str, str]] = []

    for row in rows:
        topic = canonical_topic(row.get("topic", ""))
        source = sources.get(topic, {})
        trusted_values = [
            *topic_parts(row.get("topic", ""))[1:],
            *deterministic_aliases(row, source),
        ]
        generated_values = []
        for alias in generated.get(topic, []):
            if alias_has_source_evidence(alias, row, source):
                generated_values.append(alias)
            else:
                rejected.append({"topic": topic, "alias": str(alias), "reason": "not supported by source text"})
        values = [*trusted_values, *generated_values]
        accepted: list[str] = []
        seen = {comparable_without_article(topic)}
        for raw_alias in values:
            alias = sanitize_alias(raw_alias)
            comparable = comparable_topic(alias)
            comparable_key = comparable_without_article(alias)
            reason = ""
            if not alias or not comparable:
                reason = "blank"
            elif "," in alias:
                reason = "contains comma"
            elif len(alias) > 80 or len(alias.split()) > 8:
                reason = "too long"
            elif comparable_key in seen:
                reason = "duplicate"
            elif comparable in GENERIC_ALIASES:
                reason = "generic"
            elif is_simple_plural(alias, topic) or comparable_singular(alias) == comparable_singular(topic):
                reason = "canonical variant"
            elif comparable.startswith(BAD_ALIAS_PREFIXES) or " by " in f" {comparable} ":
                reason = "descriptive fragment"
            elif comparable in canonical_owners and canonical_owners[comparable] != topic:
                reason = f"matches canonical topic {canonical_owners[comparable]}"
            if reason:
                rejected.append({"topic": topic, "alias": alias or str(raw_alias), "reason": reason})
                continue
            seen.add(comparable_key)
            accepted.append(alias)
        candidates[topic] = accepted

    alias_owners: dict[str, set[str]] = {}
    for topic, aliases in candidates.items():
        for alias in aliases:
            alias_owners.setdefault(comparable_without_article(alias), set()).add(topic)
    collisions = {
        alias: sorted(owners)
        for alias, owners in alias_owners.items()
        if len(owners) > 1
    }

    output_rows: list[dict[str, str]] = []
    for row in rows:
        topic = canonical_topic(row.get("topic", ""))
        aliases = [alias for alias in candidates[topic] if comparable_without_article(alias) not in collisions]
        output_row = dict(row)
        output_row["topic"] = ",".join([topic, *aliases])
        output_rows.append(output_row)

    report = {
        "topics": len(output_rows),
        "topics_with_aliases": sum(1 for row in output_rows if len(topic_parts(row["topic"])) > 1),
        "aliases": sum(max(0, len(topic_parts(row["topic"])) - 1) for row in output_rows),
        "collisions": collisions,
        "rejected": rejected,
    }
    return output_rows, report


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    parser.add_argument("--sources", type=Path, default=DEFAULT_SOURCES)
    parser.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    parser.add_argument("--report", type=Path, default=DEFAULT_REPORT)
    parser.add_argument("--cache-dir", type=Path, default=DEFAULT_CACHE_DIR)
    parser.add_argument("--openrouter-url", default=DEFAULT_OPENROUTER_URL)
    parser.add_argument("--model", default=DEFAULT_MODEL)
    parser.add_argument("--batch-size", type=int, default=10)
    parser.add_argument("--delay", type=float, default=0.2)
    parser.add_argument("--no-llm", action="store_true")
    parser.add_argument("--refresh", action="store_true")
    args = parser.parse_args()

    rows = read_dataset(args.input)
    sources = read_sources(args.sources)
    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not args.no_llm and not api_key:
        raise RuntimeError("OPENROUTER_API_KEY is required unless --no-llm is used")

    generated: dict[str, list[str]] = {}
    pending: list[dict[str, str]] = []
    hashes: dict[str, str] = {}
    for row in rows:
        topic = canonical_topic(row.get("topic", ""))
        source = sources.get(topic, {})
        title = clean_source_title(str(source.get("title", "")))
        description = row.get("topic_desc_basic", "")
        current_hash = alias_input_hash(topic, title, row.get("category", ""), description, args.model)
        hashes[topic] = current_hash
        cached = None if args.refresh else read_cached_aliases(args.cache_dir, topic, current_hash)
        if cached is not None:
            generated[topic] = cached
        elif not args.no_llm:
            pending.append(
                {
                    "topic": topic,
                    "title": title,
                    "category": row.get("category", ""),
                    "summary": description,
                }
            )

    if pending:
        batches = list(chunked(pending, max(1, args.batch_size)))
        request_number = 0

        def process_batch(batch: list[dict[str, str]]) -> None:
            nonlocal request_number
            request_number += 1
            print(f"[aliases request {request_number}] {batch[0]['topic']} ({len(batch)} topics) ...")
            batch_results: dict[str, list[str]] | None = None
            last_error: RuntimeError | json.JSONDecodeError | None = None
            for attempt in range(1, 4):
                try:
                    batch_results = generate_alias_batch(args.openrouter_url, api_key, args.model, batch)
                    break
                except (RuntimeError, json.JSONDecodeError) as exc:
                    last_error = exc
                    print(f"[aliases] retry {attempt}/3 after: {exc}")
                    time.sleep(float(attempt))
            if batch_results is None:
                if len(batch) > 1:
                    midpoint = max(1, len(batch) // 2)
                    process_batch(batch[:midpoint])
                    process_batch(batch[midpoint:])
                    return
                print(f"[aliases] no GLM aliases for {batch[0]['topic']}: {last_error}")
                batch_results = {}

            for item in batch:
                topic = item["topic"]
                aliases = batch_results.get(topic, [])
                generated[topic] = aliases
                write_cached_aliases(args.cache_dir, topic, hashes[topic], args.model, aliases)
            if args.delay:
                time.sleep(args.delay)

        for batch in batches:
            process_batch(batch)

    output_rows, report = build_alias_topics(rows, sources, generated)
    write_csv(args.output, OUTPUT_FIELDS, output_rows)
    validation = validate_dataset(args.output)
    report["dataset_validation"] = validation
    args.report.parent.mkdir(parents=True, exist_ok=True)
    args.report.write_text(json.dumps(report, ensure_ascii=False, indent=2), encoding="utf-8")
    print(json.dumps({key: report[key] for key in ("topics", "topics_with_aliases", "aliases")}, indent=2))
    print(f"[aliases] output: {args.output}")
    print(f"[aliases] report: {args.report}")
    return 0 if validation.get("valid") else 2


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except (RuntimeError, ValueError, json.JSONDecodeError) as exc:
        print(f"error: {exc}", file=os.sys.stderr)
        raise SystemExit(1)
