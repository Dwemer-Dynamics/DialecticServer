#!/usr/bin/env python3
"""Generate and checkpoint the complete Dialectic creature profile catalog."""

from __future__ import annotations

import argparse
import hashlib
import json
import os
import re
import sys
import time
from datetime import datetime, timezone
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen


ROOT = Path(__file__).resolve().parents[1]
API_URL = "https://openrouter.ai/api/v1/chat/completions"
MODEL = "z-ai/glm-5.1"
PROMPT_VERSION = "fallout-creature-catalog-v1-creature-focused"
REQUIRED = ("core", "npc_static_bio", "appearance", "personality", "relationships", "occupation", "skills", "speechstyle", "goals")
FORBIDDEN = re.compile(r"\b(?:Big MT|Mojave|Zion|Capital Wasteland|wiki|quest|gameplay|player character|prompt|language model|LLM)\b", re.I)
LOCATION = re.compile(r"\b(?:lives?|dwells?|found|encountered|located|region|migrat(?:e|ed|ion)|spread|nest(?:s|ing)? (?:in|near|around))\b", re.I)

SYSTEM_PROMPT = """Create one compact roleplay profile for a non-verbal creature in the Fallout setting. Return only JSON with exactly these fields: core (string), npc_static_bio (string), appearance (string), personality (string), relationships (object), occupation (string), skills (array of 2-4 strings), speechstyle (string), goals (array of 2-4 strings).

The biography must be three to five concise sentences about biology, instincts, diet, senses, behavior, social structure, capabilities, and threats. At most one short origin clause is allowed when it materially explains the creature; never name an origin facility or location. Do not describe habitat, geographic range, migration, spread, encounter locations, or where it nests. Do not mention gameplay, quests, wikis, prompts, AI, or language models.

Dialectic translates instincts and perceptions into understandable speech. The speechstyle must require direct answers to direct questions and normally one or two short sentences. Preserve natural hostility, fear, hunger, and territorial behavior. Add only mild dry species-appropriate humor, with no meme spam. Use plain text, correct punctuation, and no Markdown."""


def sha(value: str) -> str:
    return hashlib.sha256(value.encode("utf-8")).hexdigest()


def write_json(path: Path, value: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    path.write_text(json.dumps(value, indent=2, ensure_ascii=False) + "\n", encoding="utf-8", newline="\n")


def parse_object(raw: str) -> dict[str, Any]:
    if not isinstance(raw, str) or not raw.strip():
        raise RuntimeError("response contained no text content")
    raw = re.sub(r"^```(?:json)?\s*|\s*```$", "", raw.strip(), flags=re.I)
    try:
        value = json.loads(raw)
    except json.JSONDecodeError:
        value = json.loads(raw[raw.index("{") : raw.rindex("}") + 1])
    if not isinstance(value, dict):
        raise RuntimeError("response was not a JSON object")
    return value


def sentence_count(value: str) -> int:
    return len([part for part in re.split(r"(?<=[.!?])\s+", value.strip()) if part])


def validate(profile: dict[str, Any], name: str) -> dict[str, Any]:
    if set(profile) != set(REQUIRED):
        raise RuntimeError(f"{name}: fields differ from required schema")
    for field in ("core", "npc_static_bio", "appearance", "personality", "occupation", "speechstyle"):
        if not isinstance(profile[field], str) or not profile[field].strip():
            raise RuntimeError(f"{name}: {field} must be a non-empty string")
        profile[field] = profile[field].strip()
    if not isinstance(profile["relationships"], dict):
        raise RuntimeError(f"{name}: relationships must be an object")
    for field in ("skills", "goals"):
        if not isinstance(profile[field], list) or not 2 <= len(profile[field]) <= 4 or not all(isinstance(x, str) and x.strip() for x in profile[field]):
            raise RuntimeError(f"{name}: {field} must contain 2-4 strings")
        profile[field] = [x.strip() for x in profile[field]]
    biography = profile["npc_static_bio"]
    if not 3 <= sentence_count(biography) <= 5:
        raise RuntimeError(f"{name}: biography must have 3-5 sentences")
    serialized = json.dumps(profile, ensure_ascii=False)
    if FORBIDDEN.search(serialized) or LOCATION.search(biography):
        raise RuntimeError(f"{name}: forbidden or location-heavy wording")
    if "\\" in serialized or "&quot;" in serialized or "&#" in serialized:
        raise RuntimeError(f"{name}: malformed escaping or HTML entity")
    if "direct" not in profile["speechstyle"].lower():
        raise RuntimeError(f"{name}: speechstyle must require direct answers")
    return profile


def request_profile(record: dict[str, Any], api_key: str, timeout: float) -> tuple[dict[str, Any], int]:
    user_prompt = json.dumps({
        "creature": record["display_name"],
        "species_or_variant": record["species_or_variant"],
        "voice_direction": record["voice_reason"],
    }, ensure_ascii=False)
    body = json.dumps({
        "model": MODEL,
        "messages": [{"role": "system", "content": SYSTEM_PROMPT}, {"role": "user", "content": user_prompt}],
        "temperature": 0.2,
        "max_tokens": 5000,
        "response_format": {"type": "json_object"},
    }).encode("utf-8")
    for attempt in range(1, 4):
        req = Request(API_URL, data=body, method="POST", headers={
            "Authorization": f"Bearer {api_key}", "Content-Type": "application/json",
            "HTTP-Referer": "https://dwemerdynamics.com", "X-Title": "Dialectic Creature Catalog",
        })
        try:
            with urlopen(req, timeout=timeout) as response:
                payload = json.loads(response.read().decode("utf-8"))
            raw = payload["choices"][0]["message"].get("content")
            if isinstance(raw, list):
                raw = "".join(str(part.get("text") or "") for part in raw if isinstance(part, dict))
            return validate(parse_object(raw), record["display_name"]), attempt
        except HTTPError as exc:
            if exc.code not in {429, 500, 502, 503, 504} or attempt == 3:
                raise
            time.sleep(min(60, 2 ** attempt))
        except (URLError, TimeoutError, KeyError, TypeError, ValueError, RuntimeError) as exc:
            if attempt == 3:
                raise RuntimeError(f"{record['display_name']}: {exc}") from exc
            time.sleep(min(60, 2 ** attempt))
    raise RuntimeError(f"{record['display_name']}: attempts exhausted")


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--input", type=Path, default=ROOT / "tmp" / "fallout_creature_profiles_input.json")
    parser.add_argument("--manifest", type=Path, default=ROOT / "data" / "fallout_creature_profile_manifest.json")
    parser.add_argument("--timeout", type=float, default=180.0)
    args = parser.parse_args()
    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    source = json.loads(args.input.read_text(encoding="utf-8"))
    prompt_hash = sha(SYSTEM_PROMPT)
    existing: dict[str, Any] = {}
    if args.manifest.exists():
        prior = json.loads(args.manifest.read_text(encoding="utf-8"))
        existing = {row["template_name"]: row for row in prior.get("profiles", []) if isinstance(row, dict)}

    records: list[dict[str, Any]] = source["profiles"]
    results: dict[str, dict[str, Any]] = {}
    pending: list[dict[str, Any]] = []
    reused = 0
    for record in records:
        generation_source = {
            key: record[key]
            for key in ("template_name", "display_name", "species_or_variant", "voiceid", "voice_reason", "sample_question")
        }
        source_hash = sha(json.dumps(generation_source, sort_keys=True, ensure_ascii=False))
        old = existing.get(record["template_name"])
        same_generation_input = old and all(old.get(key) == record.get(key) for key in generation_source)
        if old and (old.get("source_hash") == source_hash or same_generation_input) and old.get("prompt_hash") == prompt_hash and old.get("model") == MODEL and old.get("validation") == "passed":
            results[record["template_name"]] = {
                **old,
                **record,
                "source": {
                    "kind": "official_xedit_crea_inventory",
                    "catalog": source["catalog"],
                    "source_hash": source["xedit_source_sha256"],
                },
                "source_hash": source_hash,
            }
            reused += 1
        else:
            record["source_hash"] = source_hash
            pending.append(record)

    request_total = 0
    retry_total = 0
    failures: list[dict[str, Any]] = []
    if pending and not api_key:
        raise RuntimeError("OPENROUTER_API_KEY is required when generation is pending")
    for pass_index in range(3):
        batch, failures = (pending if pass_index == 0 else failures), []
        if not batch:
            break
        for index, record in enumerate(batch, 1):
            print(f"pass={pass_index + 1} profile={record['template_name']} item={index}/{len(batch)}", flush=True)
            try:
                profile, attempts = request_profile(record, api_key, args.timeout)
                request_total += attempts
                retry_total += attempts - 1
                results[record["template_name"]] = {
                    **record,
                    "source": {"kind": "official_xedit_crea_inventory", "catalog": source["catalog"], "source_hash": source["xedit_source_sha256"]},
                    "source_hash": record["source_hash"], "prompt_version": PROMPT_VERSION, "prompt_hash": prompt_hash,
                    "model": MODEL, "status": "generated", "attempt_count": attempts, "validation": "passed", "profile": profile,
                }
                completed_profiles = [results[key] for key in sorted(results)]
                manifest = {
                    "catalog": source["catalog"], "generated_at": datetime.now(timezone.utc).isoformat(),
                    "prompt_version": PROMPT_VERSION, "prompt_hash": prompt_hash, "model": MODEL,
                    "inventory_counts": source["inventory_counts"], "candidate_count": source["candidate_count"],
                    "profile_count": len(records),
                    "request_total": sum(int(row.get("attempt_count", 1)) for row in completed_profiles),
                    "retry_total": sum(max(0, int(row.get("attempt_count", 1)) - 1) for row in completed_profiles),
                    "last_run_request_total": request_total, "last_run_retry_total": retry_total, "reused_total": reused,
                    "profiles": completed_profiles,
                }
                write_json(args.manifest, manifest)
            except Exception as exc:
                print(f"failed={record['template_name']} error={exc}", file=sys.stderr, flush=True)
                failures.append(record)
    if failures:
        raise RuntimeError(f"generation failed after recovery passes: {', '.join(row['template_name'] for row in failures)}")
    completed_profiles = [results[key] for key in sorted(results)]
    final = {
        "catalog": source["catalog"], "generated_at": datetime.now(timezone.utc).isoformat(),
        "prompt_version": PROMPT_VERSION, "prompt_hash": prompt_hash, "model": MODEL,
        "inventory_counts": source["inventory_counts"], "candidate_count": source["candidate_count"],
        "profile_count": len(records),
        "request_total": sum(int(row.get("attempt_count", 1)) for row in completed_profiles),
        "retry_total": sum(max(0, int(row.get("attempt_count", 1)) - 1) for row in completed_profiles),
        "last_run_request_total": request_total, "last_run_retry_total": retry_total, "reused_total": reused,
        "profiles": completed_profiles,
    }
    write_json(args.manifest, final)
    print(json.dumps({"profiles": len(records), "requests": request_total, "retries": retry_total, "reused": reused}))
    return 0


if __name__ == "__main__":
    try:
        raise SystemExit(main())
    except Exception as exc:
        print(f"error: {exc}", file=sys.stderr)
        raise SystemExit(1)
