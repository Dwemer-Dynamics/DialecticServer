#!/usr/bin/env python3
"""Enrich reviewed Fallout World Knowledge with GLM under a strict resumable budget."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import os
import re
import sys
import time
from pathlib import Path
from typing import Any
from urllib.error import HTTPError, URLError
from urllib.request import Request, urlopen

SCRIPT_DIR = Path(__file__).resolve().parent
ROOT_DIR = SCRIPT_DIR.parent.parent
DEFAULT_INPUT = ROOT_DIR / "data" / "fallout_worldknowledge_basic.csv"
DEFAULT_SOURCES = ROOT_DIR / "data" / "fallout_worldknowledge_sources.jsonl"
DEFAULT_OUTPUT = SCRIPT_DIR / "output" / "fallout_worldknowledge_parity_v1.local.csv"
DEFAULT_REVIEW = SCRIPT_DIR / "output" / "fallout_worldknowledge_parity_v1_review.local.json"
DEFAULT_LEDGER = SCRIPT_DIR / "output" / "openrouter_budget_ledger.local.json"
DEFAULT_CACHE = SCRIPT_DIR / "cache" / "parity-v1"
DEFAULT_PUBLISHED_OUTPUT = ROOT_DIR / "data" / "fallout_worldknowledge_parity_v1.csv"
DEFAULT_MANIFEST = ROOT_DIR / "data" / "fallout_worldknowledge_manifest.json"
DEFAULT_PUBLISHED_SOURCES = ROOT_DIR / "data" / "fallout_worldknowledge_sources.jsonl"
DEFAULT_EXPANSION_MANIFEST = SCRIPT_DIR / "fallout_worldknowledge_expansion_topics.csv"
DEFAULT_EDITORIAL_OVERRIDES = SCRIPT_DIR / "fallout_worldknowledge_editorial_overrides.json"
DEFAULT_VOCABULARY = ROOT_DIR / "data" / "fallout_worldknowledge_vocabulary.json"
DEFAULT_EXPANSION_INPUT = SCRIPT_DIR / "output" / "fallout_worldknowledge_expansion_input.local.csv"
DEFAULT_EXPANSION_SOURCES = SCRIPT_DIR / "output" / "fallout_worldknowledge_expansion_sources.local.jsonl"
DEFAULT_MODEL = "z-ai/glm-5.2"
DEFAULT_OPENROUTER_URL = "https://openrouter.ai/api/v1"
DEFAULT_WIKI_API = "https://fallout.wiki/api.php"
DEFAULT_BUDGET_USD = 30.0

INPUT_FIELDS = [
    "topic", "topic_desc", "knowledge_class", "topic_desc_basic",
    "knowledge_class_basic", "tags", "category",
]
OUTPUT_FIELDS = [
    "topic", "topic_desc", "knowledge_class", "topic_desc_basic",
    "knowledge_class_basic", "tags", "category", "setting", "region",
    "valid_from_year", "valid_to_year", "source_url", "source_revision",
    "editorial_note",
]
CATEGORIES = {
    "artifact", "armor", "concept", "creature", "culture", "event", "faction",
    "flora", "food_drink", "history", "item", "location", "medicine", "organization",
    "person", "robot", "technology", "vault", "weapon",
}
_VOCABULARY = json.loads(DEFAULT_VOCABULARY.read_text(encoding="utf-8"))
KNOWLEDGE_CLASSES = {
    str(value)
    for group in ("roles", "domains", "factions", "races", "regions", "reserved")
    for value in _VOCABULARY.get(group, [])
}
OUT_OF_WORLD_PATTERNS = [
    r"\bplayer characters?\b", r"\bthe player\b", r"\bperks?\b", r"\bquests?\b",
    r"\bgameplay\b", r"\bkarma\b", r"\bexperience points?\b", r"\brandom encounters?\b",
    r"\b(?:creature )?spawns?\b", r"\bcompass displays?\b", r"\bhostile blips?\b",
    r"\bnon-interactive\b", r"\bfast[- ]travel", r"\bskill checks?\b",
    r"\b\d+ points? of damage\b", r"\bdamage threshold\b", r"\bdamage resistance\b",
    r"\bending slides?\b", r"\bcharacter was designed\b", r"\bconcept art\b",
    r"\bofficial game guide\b", r"\bcollector'?s edition\b", r"\bFallout Bible\b",
    r"\bFallout (?:3|4|76|: New Vegas|New Vegas)\b", r"\bbase[- ]game\b",
    r"\btemporary companion\b", r"\bcompanion arc\b", r"\btrade interface\b",
    r"\bprimary setting of\b", r"\bnarrative device\b", r"\binert world objects?\b",
    r"\baction points?\b", r"\b(?:damage|critical hit) per shot\b",
    r"\b(?:base|raw) damage(?: output)?\b", r"\bweapon spread\b",
    r"\bcritical hit probability\b", r"\bsecond[- ]highest\b",
    r"\bhighest base damage\b", r"\bmost powerful .* weapon\b",
    r"\b(?:jam|swimming|reload) animations?\b", r"\binventory icons?\b",
    r"\bnear[- ]breaking condition\b", r"\bcan(?:not|'t) be deployed\b",
    r"\b(?:replenishes|restocks?) (?:twice|weekly|daily)\b",
    r"\b(?:Broken Steel|Point Lookout|Mothership Zeta) add-on\b",
    r"\bdeveloper (?:Chris Taylor|Joshua Sawyer|J\.E\. Sawyer|John Gonzalez)\b",
    r"\b(?:designed|written) by (?:Joshua Sawyer|J\.E\. Sawyer|John Gonzalez)\b",
    r"\b(?:Chris Taylor|Chris Avellone|Joshua Sawyer|J\.E\. Sawyer|John Gonzalez)\b",
    r"\b(?:gaming effect|dialogue script notes?|console commands?|reverse-pickpocketed)\b",
    r"\bdislodged from (?:their|the) inventory\b",
    r"\bif (?:both|either|[A-Z][A-Za-z'-]+) (?:dies|is killed|is destroyed)\b",
    r"\bwhen (?:traveling|acting|serving) as (?:a |the )?companion\b",
    r"\bunused (?:variant|version|item|weapon|armor|model)\b",
    r"\b(?:damage per second|damage per hit|higher damage|lower damage|more damage|less damage)\b",
    r"\b\d+% damage\b", r"\brequires? (?:a |an )?(?:\w+ )?skill of \d+\b",
    r"\b(?:can be )?looted from\b", r"\bif siding with\b",
    r"\bnot for sale\b", r"\bdiscount of \d+ percent\b",
    r"\bappear .* only if\b",
    r"\bonly appears (?:after|if|when)\b", r"\bdepending on quest progress\b",
    r"\b(?:if|when|unless) the (?:Courier|Lone Wanderer)\b",
    r"\b(?:Courier|Lone Wanderer) (?:can|may|must|could|should|chooses|helps|kills|completes|sides|persuades|convinces)\b",
    r"\bif Megaton is destroyed\b", r"\bwhether by the Courier\b",
    r"\bAppalachia\b", r"\bDiamond City\b", r"\bGoodneighbor\b", r"\bFilly\b",
    r"\bVault 63\b", r"\bFens Way Station\b", r"\bKnights of San Fernando\b",
    r"\bCold Fusion Diode\b", r"\bThaddeus\b",
]


def normalize_space(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def split_sentences(value: str) -> list[str]:
    protected = normalize_space(value)
    sentinel = "\0"
    protected = re.sub(
        r"\b(?:Mr|Mrs|Ms|Dr|St|Lt|Col|Sgt|Capt|Cpl|Pvt|Gen)\.",
        lambda match: match.group(0).replace(".", sentinel),
        protected,
        flags=re.IGNORECASE,
    )
    return [
        part.replace(sentinel, ".").strip()
        for part in re.split(r"(?<=[.!?])\s+", protected)
        if part.strip()
    ]


def normalize_setting(value: str, game_scope: str) -> str:
    # The reviewed source scope owns this field; model output cannot turn a location into a game setting.
    scope = normalize_space(game_scope).lower()
    if scope == "both" or " and " in scope:
        return "both"
    if scope == "fnv" or "new vegas" in scope:
        return "fnv"
    if scope == "fallout3" or "fallout 3" in scope:
        return "fallout3"
    return "fallout"


def article_policy_issue(value: str) -> str:
    for pattern in OUT_OF_WORLD_PATTERNS:
        if re.search(pattern, value, re.IGNORECASE):
            return f"contains out-of-world or mutable-game wording matching {pattern}"
    for match in re.finditer(r"\b(2\d{3})\b", value):
        year = int(match.group(1))
        prefix = value[max(0, match.start() - 5):match.start()]
        if year > 2281 and re.search(r"[A-Z]{1,4}-$", prefix) is None:
            return f"contains post-2281 chronology ({year})"
    return ""


def fetch_page(api_url: str, title: str, topic: str, cache_dir: Path, refresh: bool) -> dict[str, Any]:
    cache_path = cache_dir / f"{topic}.json"
    if cache_path.exists() and not refresh:
        return json.loads(cache_path.read_text(encoding="utf-8"))
    query = {
        "action": "query",
        "format": "json",
        "formatversion": "2",
        "prop": "extracts|info|revisions",
        "titles": title,
        "redirects": "1",
        "explaintext": "1",
        "exsectionformat": "plain",
        "inprop": "url",
        "rvprop": "ids|timestamp",
    }
    from urllib.parse import urlencode
    response = request_json(f"{api_url}?{urlencode(query)}")
    pages = response.get("query", {}).get("pages", [])
    if not pages or pages[0].get("missing") is True:
        raise RuntimeError(f"Fallout Wiki page not found: {title}")
    page = pages[0]
    payload = {
        "title": page.get("title", title),
        "url": page.get("fullurl", ""),
        "extract": page.get("extract", ""),
        "revision": (page.get("revisions") or [{}])[0],
        "fetched_at": timestamp(),
    }
    write_json_atomic(cache_path, payload)
    return payload


def request_json(
    url: str,
    *,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    timeout: float = 180.0,
    retries: int = 5,
) -> dict[str, Any]:
    body = json.dumps(payload).encode("utf-8") if payload is not None else None
    request_headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "User-Agent": "DialecticWorldKnowledgeParity/1.0 (+https://dwemerdynamics.com)",
    }
    if headers:
        request_headers.update(headers)
    for attempt in range(1, retries + 1):
        try:
            request = Request(url, data=body, headers=request_headers, method=method)
            with urlopen(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except HTTPError as exc:
            detail = exc.read().decode("utf-8", errors="replace")[:800]
            if exc.code not in {408, 409, 425, 429, 500, 502, 503, 504} or attempt >= retries:
                raise RuntimeError(f"HTTP {exc.code} for {url}: {detail}") from exc
        except (URLError, TimeoutError, json.JSONDecodeError) as exc:
            if attempt >= retries:
                raise RuntimeError(f"Request failed for {url}: {exc}") from exc
        time.sleep(min(10.0, 1.5 * attempt))
    raise RuntimeError(f"Request failed for {url}")


def read_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != INPUT_FIELDS:
            raise RuntimeError("Input CSV does not match the seven-column Dialectic contract")
        return [{key: str(value or "").strip() for key, value in row.items()} for row in reader]


def read_output_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != OUTPUT_FIELDS:
            raise RuntimeError("Generated CSV does not match the fourteen-column parity-v1 contract")
        return [{key: str(value or "").strip() for key, value in row.items()} for row in reader]


def apply_editorial_overrides(rows: list[dict[str, str]], path: Path) -> list[dict[str, str]]:
    """Apply source-reviewed prose corrections after model generation and before every gate."""
    payload = json.loads(path.read_text(encoding="utf-8"))
    overrides = payload.get("overrides", {})
    if payload.get("schema") != "dialectic.worldknowledge-editorial-overrides.v1" or not isinstance(overrides, dict):
        raise RuntimeError("Editorial overrides do not match the parity-v1 contract")
    result: list[dict[str, str]] = []
    for original in rows:
        row = dict(original)
        topic = canonical_topic(row["topic"])
        replacement = overrides.get(topic, {})
        if not isinstance(replacement, dict) or any(
            field not in {"topic", "topic_desc", "topic_desc_basic", "tags"} for field in replacement
        ):
            raise RuntimeError(f"Invalid editorial override for {topic}")
        for field, value in replacement.items():
            row[field] = normalize_space(str(value))
        result.append(row)
    return result


def read_sources(path: Path) -> dict[str, dict[str, Any]]:
    sources: dict[str, dict[str, Any]] = {}
    with path.open("r", encoding="utf-8") as handle:
        for line in handle:
            if not line.strip():
                continue
            row = json.loads(line)
            topic = str(row.get("topic", "")).strip()
            if topic:
                sources[topic] = row
    return sources


def prepare_expansion_inputs(args: argparse.Namespace) -> int:
    """Create collision-free generation inputs from the reviewed expansion manifest."""
    existing_rows = read_rows(args.input)
    occupied_phrases: set[str] = set()
    for row in existing_rows:
        occupied_phrases.update(
            normalize_space(part.replace("_", " ").lower())
            for part in str(row["topic"]).split(",")
            if part.strip()
        )

    with args.expansion_manifest.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        expected = ["topic", "title", "aliases", "category", "game"]
        if reader.fieldnames != expected:
            raise RuntimeError("Expansion manifest has an unexpected header")
        candidates = list(reader)

    output_rows: list[dict[str, str]] = []
    source_rows: list[dict[str, Any]] = []
    skipped: list[dict[str, Any]] = []
    for candidate in candidates:
        canonical = str(candidate["topic"]).strip().lower()
        aliases = [normalize_space(value) for value in str(candidate["aliases"]).split("|") if value.strip()]
        # Source page titles are provenance, not retrieval aliases. Reusing a broad
        # source for a narrower reviewed concept must not create a false collision.
        phrases = [normalize_space(canonical.replace("_", " "))]
        phrases.extend(normalize_space(value.lower()) for value in aliases)
        collisions = sorted({phrase for phrase in phrases if phrase in occupied_phrases})
        if collisions:
            skipped.append({"topic": canonical, "reason": "existing_phrase_collision", "phrases": collisions})
            continue
        if not re.fullmatch(r"[a-z0-9_]+", canonical):
            raise RuntimeError(f"Expansion topic is invalid: {canonical}")
        category = str(candidate["category"]).strip().lower()
        if category not in CATEGORIES:
            raise RuntimeError(f"Expansion category is invalid for {canonical}: {category}")
        topic_list = ",".join([canonical, *aliases])
        output_rows.append({
            "topic": topic_list,
            "topic_desc": "",
            "knowledge_class": "",
            "topic_desc_basic": "",
            "knowledge_class_basic": "",
            "tags": "",
            "category": category,
        })
        source_rows.append({
            "topic": canonical,
            "title": str(candidate["title"]).strip(),
            "game": str(candidate["game"]).strip(),
            "source_url": "",
            "revision_id": "",
        })
        occupied_phrases.update(phrases)

    args.expansion_input.parent.mkdir(parents=True, exist_ok=True)
    with args.expansion_input.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=INPUT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(output_rows)
    args.expansion_sources.write_text(
        "".join(json.dumps(row, ensure_ascii=False) + "\n" for row in source_rows),
        encoding="utf-8",
    )
    write_json_atomic(args.expansion_input.with_suffix(".review.json"), {
        "selected": len(output_rows),
        "skipped": skipped,
        "generated_at": timestamp(),
    })
    print(f"[expansion] selected={len(output_rows)} skipped={len(skipped)} input={args.expansion_input}")
    return 0


def canonical_topic(value: str) -> str:
    return str(value).split(",", 1)[0].strip()


def comparable_topic(value: str) -> str:
    return normalize_space(re.sub(r"[^a-z0-9\s]", " ", value.replace("_", " ").lower()))


def read_ledger(path: Path, model: str, budget: float) -> dict[str, Any]:
    if path.exists():
        ledger = json.loads(path.read_text(encoding="utf-8"))
        if ledger.get("model") != model:
            raise RuntimeError("Budget ledger belongs to a different model")
        if float(ledger.get("authorized_budget_usd", 0)) != budget:
            raise RuntimeError("Budget ledger authorization differs from --budget-usd")
        return ledger
    return {
        "schema": "dialectic.openrouter-budget.v1",
        "model": model,
        "authorized_budget_usd": budget,
        "spent_usd": 0.0,
        "prompt_tokens": 0,
        "completion_tokens": 0,
        "requests": [],
        "created_at": timestamp(),
        "updated_at": timestamp(),
    }


def write_json_atomic(path: Path, payload: Any) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    temporary = path.with_suffix(path.suffix + ".tmp")
    temporary.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    for attempt in range(6):
        try:
            temporary.replace(path)
            return
        except PermissionError:
            if attempt == 5:
                raise
            time.sleep(0.05 * (attempt + 1))


def timestamp() -> str:
    return time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime())


def model_prices(base_url: str, api_key: str, model: str) -> tuple[float, float]:
    author, slug = model.split("/", 1)
    response = request_json(
        f"{base_url.rstrip('/')}/model/{author}/{slug}",
        headers={"Authorization": f"Bearer {api_key}"},
    )
    data = response.get("data", response)
    pricing = data.get("pricing", {})
    prompt = float(pricing.get("prompt", 0))
    completion = float(pricing.get("completion", 0))
    if prompt <= 0 or completion <= 0:
        raise RuntimeError(f"OpenRouter returned unusable pricing for {model}")
    return prompt, completion


def estimated_tokens(text: str) -> int:
    return max(1, (len(text.encode("utf-8")) + 3) // 4)


def batch_hash(batch: list[dict[str, Any]], model: str) -> str:
    payload = json.dumps([model, batch], ensure_ascii=False, sort_keys=True)
    return hashlib.sha256(payload.encode("utf-8")).hexdigest()


def parse_content(response: dict[str, Any]) -> list[dict[str, Any]]:
    try:
        content = response["choices"][0]["message"]["content"]
    except (KeyError, IndexError, TypeError) as exc:
        raise RuntimeError("OpenRouter response did not contain message content") from exc
    if not isinstance(content, str) or not content.strip():
        raise RuntimeError("OpenRouter response content was empty")
    cleaned = re.sub(r"^```(?:json)?\s*|\s*```$", "", content.strip(), flags=re.IGNORECASE)
    payload = json.loads(cleaned)
    articles = payload.get("articles") if isinstance(payload, dict) else None
    if not isinstance(articles, list):
        raise RuntimeError("OpenRouter response did not contain an articles list")
    return articles


def generate_batch(
    *,
    base_url: str,
    api_key: str,
    model: str,
    batch: list[dict[str, Any]],
    max_tokens: int,
    validation_feedback: str = "",
) -> tuple[dict[str, Any], dict[str, Any]]:
    system = (
        "You are curating a static in-world Fallout 3, Fallout: New Vegas, official DLC, and Tale of Two Wastelands "
        "knowledge catalog for roleplayed NPCs. Use only the supplied source extracts. Preserve uncertainty. Do not "
        "invent facts, quest instructions, gameplay mechanics, player decisions, ending outcomes, or mutable quest state. "
        "When a supplied basic article is non-empty, return it unchanged. When it is empty, write a source-grounded basic "
        "article of 40-90 words containing only common in-world knowledge. Write an advanced article of 90-230 words with deeper historical, political, "
        "scientific, cultural, or geographic context supported by the source. Choose one allowed category. Produce 4-8 "
        "specific lowercase semantic tags, each two to five words; tags are retrieval evidence, not access permissions. "
        "Choose zero or more advanced access classes from the allowed ontology. Basic knowledge is normally unrestricted. "
        "Set chronology conservatively: Fallout 3 present-day developments begin in 2277; New Vegas present-day developments "
        "begin in 2281. Timeless or pre-war history may have blank bounds. Use only facts belonging to the supplied game_scope, "
        "even when a source extract mentions later games, television, Appalachia, the Commonwealth, or events after 2281. "
        "Write entirely in-world: never mention players, perks, quests, karma, experience, stats, UI, spawning, encounter systems, "
        "product titles, guides, developers, endings, conditional player choices, or mutable protagonist outcomes. Return exactly "
        "one result per supplied topic."
    )
    if validation_feedback:
        system += " Previous draft rejected: " + validation_feedback + ". Rewrite the affected articles to comply."
    user_payload = {
        "allowed_categories": sorted(CATEGORIES),
        "allowed_knowledge_classes": sorted(KNOWLEDGE_CLASSES),
        "articles": batch,
    }
    schema = {
        "type": "object",
        "properties": {
            "articles": {
                "type": "array",
                "minItems": len(batch),
                "maxItems": len(batch),
                "items": {
                    "type": "object",
                    "properties": {
                        "topic": {"type": "string"},
                        "basic_article": {"type": "string"},
                        "advanced_article": {"type": "string"},
                        "advanced_knowledge_classes": {"type": "array", "items": {"type": "string"}},
                        "basic_knowledge_classes": {"type": "array", "items": {"type": "string"}},
                        "tags": {"type": "array", "items": {"type": "string"}},
                        "category": {"type": "string"},
                        "setting": {"type": "string"},
                        "region": {"type": "string"},
                        "valid_from_year": {"type": ["integer", "null"]},
                        "valid_to_year": {"type": ["integer", "null"]},
                        "editorial_note": {"type": "string"},
                    },
                    "required": [
                        "topic", "basic_article", "advanced_article", "advanced_knowledge_classes", "basic_knowledge_classes",
                        "tags", "category", "setting", "region", "valid_from_year", "valid_to_year", "editorial_note",
                    ],
                    "additionalProperties": False,
                },
            }
        },
        "required": ["articles"],
        "additionalProperties": False,
    }
    request_payload = {
        "model": model,
        "temperature": 0.15,
        "max_tokens": max_tokens,
        "reasoning": {"effort": "low", "exclude": True},
        "messages": [
            {"role": "system", "content": system},
            {"role": "user", "content": json.dumps(user_payload, ensure_ascii=False)},
        ],
        "response_format": {
            "type": "json_schema",
            "json_schema": {"name": "dialectic_worldknowledge_articles", "strict": True, "schema": schema},
        },
    }
    response = request_json(
        f"{base_url.rstrip('/')}/chat/completions",
        method="POST",
        payload=request_payload,
        headers={"Authorization": f"Bearer {api_key}"},
        timeout=120.0,
        retries=2,
    )
    return response, request_payload


def normalize_generated(source: dict[str, Any], generated: dict[str, Any]) -> dict[str, str]:
    topic = str(source["topic"])
    if str(generated.get("topic", "")).strip() != topic:
        raise RuntimeError(f"GLM returned mismatched topic for {topic}")
    category = str(generated.get("category", "")).strip().lower()
    if category not in CATEGORIES:
        raise RuntimeError(f"GLM returned unsupported category {category!r} for {topic}")
    advanced = normalize_space(str(generated.get("advanced_article", "")))
    word_count = len(advanced.split())
    if not 70 <= word_count <= 280 or len(split_sentences(advanced)) < 2:
        raise RuntimeError(f"Advanced article for {topic} failed length validation ({word_count} words)")
    if any(marker in advanced for marker in ("```", "<", ">", "**")):
        raise RuntimeError(f"Advanced article for {topic} contains markup")
    policy_issue = article_policy_issue(advanced)
    if policy_issue:
        raise RuntimeError(f"Advanced article for {topic} {policy_issue}")
    tags = sorted({normalize_space(str(value)).lower() for value in generated.get("tags", []) if str(value).strip()})
    if (
        not 4 <= len(tags) <= 8
        or any(not 2 <= len(tag.split()) <= 5 for tag in tags)
        or any(not re.fullmatch(r"[^\W_](?:[^\W_]|[ .'-])*", tag, flags=re.UNICODE) for tag in tags)
    ):
        raise RuntimeError(f"Tags for {topic} failed the reviewed tag shape")
    advanced_classes = [str(value).strip().lower() for value in generated.get("advanced_knowledge_classes", [])]
    basic_classes = [str(value).strip().lower() for value in generated.get("basic_knowledge_classes", [])]
    for value in [*advanced_classes, *basic_classes]:
        comparable = value[1:] if value.startswith("!") else value
        if comparable not in KNOWLEDGE_CLASSES:
            raise RuntimeError(f"GLM returned unsupported knowledge class {value!r} for {topic}")
    from_year = generated.get("valid_from_year")
    to_year = generated.get("valid_to_year")
    if from_year is not None and (not isinstance(from_year, int) or not 1 <= from_year <= 2281):
        raise RuntimeError(f"GLM returned invalid valid_from_year for {topic}")
    if to_year is not None and (not isinstance(to_year, int) or not 1 <= to_year <= 2281):
        raise RuntimeError(f"GLM returned invalid valid_to_year for {topic}")
    if from_year is not None and to_year is not None and from_year > to_year:
        raise RuntimeError(f"GLM returned inverted chronology for {topic}")
    basic = normalize_space(str(source.get("basic_article", ""))) or normalize_space(str(generated.get("basic_article", "")))
    basic_word_count = len(basic.split())
    if not 20 <= basic_word_count <= 220 or len(split_sentences(basic)) < 1:
        raise RuntimeError(f"Basic article for {topic} failed length validation ({basic_word_count} words)")
    basic_policy_issue = article_policy_issue(basic)
    if basic_policy_issue:
        raise RuntimeError(f"Basic article for {topic} {basic_policy_issue}")
    return {
        "topic": str(source["topic_list"]),
        "topic_desc": advanced,
        "knowledge_class": ",".join(dict.fromkeys(advanced_classes)),
        "topic_desc_basic": basic,
        "knowledge_class_basic": ",".join(dict.fromkeys(basic_classes)),
        "tags": ",".join(tags),
        "category": category,
        "setting": normalize_setting(str(generated.get("setting", "")), str(source.get("game_scope", ""))),
        "region": normalize_space(str(generated.get("region", ""))).lower(),
        "valid_from_year": "" if from_year is None else str(from_year),
        "valid_to_year": "" if to_year is None else str(to_year),
        "source_url": str(source["source_url"]),
        "source_revision": str(source["source_revision"]),
        "editorial_note": normalize_space(str(generated.get("editorial_note", ""))),
    }


def validate_generated_batch(batch: list[dict[str, Any]], articles: list[dict[str, Any]]) -> tuple[bool, str]:
    """Validate both exact batch identity and every generated catalog field before caching."""
    expected_topics = {item["topic"] for item in batch}
    actual_topics = {str(item.get("topic", "")).strip() for item in articles if isinstance(item, dict)}
    if len(articles) != len(batch) or actual_topics != expected_topics:
        return False, "response did not return exactly the requested topics"
    generated_by_topic = {str(item["topic"]).strip(): item for item in articles}
    try:
        for source in batch:
            normalize_generated(source, generated_by_topic[str(source["topic"])])
    except RuntimeError as exc:
        return False, str(exc)
    return True, ""


def prepare_source(
    row: dict[str, str],
    source: dict[str, Any],
    wiki_api: str,
    wiki_cache: Path,
    refresh_sources: bool,
) -> dict[str, Any]:
    topic = canonical_topic(row["topic"])
    page = fetch_page(
        wiki_api,
        str(source.get("title", topic)),
        topic,
        wiki_cache,
        refresh_sources,
    )
    extract = normalize_space(str(page.get("extract", "")))
    if len(extract) < 100:
        raise RuntimeError(f"Source extract is too short for {topic}")
    revision = page.get("revision", {})
    return {
        "topic": topic,
        "topic_list": row["topic"],
        "source_title": str(page.get("title", source.get("title", topic))),
        "source_url": str(page.get("url", source.get("source_url", ""))),
        "source_revision": str(revision.get("revid", source.get("revision_id", ""))),
        "game_scope": str(source.get("game", "")),
        "current_category": row["category"],
        "basic_article": row["topic_desc_basic"],
        "source_extract": extract[:18000],
    }


def validate_output(rows: list[dict[str, str]]) -> list[dict[str, Any]]:
    issues: list[dict[str, Any]] = []
    seen_topics: set[str] = set()
    for row in rows:
        topic = canonical_topic(row["topic"])
        if topic in seen_topics:
            issues.append({"topic": topic, "issue": "duplicate_topic"})
        seen_topics.add(topic)
        if not re.fullmatch(r"[a-z0-9_]+", topic):
            issues.append({"topic": topic, "issue": "invalid_canonical_topic"})
        aliases = [normalize_space(value) for value in row["topic"].split(",") if value.strip()]
        comparable_aliases = [comparable_topic(value) for value in aliases]
        if len(comparable_aliases) != len(set(comparable_aliases)):
            issues.append({"topic": topic, "issue": "duplicate_alias"})
        omitted_basic = not row["topic_desc_basic"] and re.search(
            r"Access v2 \((?:secret|personal);", row["editorial_note"], flags=re.IGNORECASE
        )
        if not row["topic_desc"] or (not row["topic_desc_basic"] and not omitted_basic):
            issues.append({"topic": topic, "issue": "missing_article_text"})
        advanced_words = len(row["topic_desc"].split())
        basic_words = len(row["topic_desc_basic"].split())
        if not 70 <= advanced_words <= 280:
            issues.append({"topic": topic, "issue": "advanced_article_length", "words": advanced_words})
        if row["topic_desc_basic"] and not 20 <= basic_words <= 220:
            issues.append({"topic": topic, "issue": "basic_article_length", "words": basic_words})
        for field in ("topic_desc", "topic_desc_basic"):
            if not row[field]:
                continue
            policy_issue = article_policy_issue(row[field])
            if policy_issue:
                issues.append({"topic": topic, "issue": "article_policy", "field": field, "detail": policy_issue})
        raw_tags = [value.strip().lower() for value in row["tags"].split(",") if value.strip()]
        tags = [normalize_space(value) for value in raw_tags]
        if (
            not 4 <= len(set(tags)) <= 8
            or any(not 2 <= len(tag.split()) <= 5 for tag in tags)
            or any(tag != raw_tag for tag, raw_tag in zip(tags, raw_tags))
            or any(not re.fullmatch(r"[^\W_](?:[^\W_]|[ .'-])*", tag, flags=re.UNICODE) for tag in tags)
        ):
            issues.append({"topic": topic, "issue": "invalid_tags"})
        classes = [
            value.strip().lower().lstrip("!")
            for field in ("knowledge_class", "knowledge_class_basic")
            for value in re.split(r"[,|&]", row[field])
            if value.strip()
        ]
        if any(re.fullmatch(r"[a-z0-9][a-z0-9_]{0,100}", value) is None for value in classes):
            issues.append({"topic": topic, "issue": "invalid_knowledge_class"})
        if not row["tags"] or not row["setting"] or not row["source_url"] or not row["source_revision"]:
            issues.append({"topic": topic, "issue": "missing_metadata"})
        if not re.match(r"^https?://", row["source_url"]) or not row["source_revision"].isdigit():
            issues.append({"topic": topic, "issue": "invalid_provenance"})
        if row["category"] not in CATEGORIES:
            issues.append({"topic": topic, "issue": "invalid_category"})
        try:
            from_year = int(row["valid_from_year"]) if row["valid_from_year"] else None
            to_year = int(row["valid_to_year"]) if row["valid_to_year"] else None
            if (from_year is not None and not 1 <= from_year <= 2281) or (to_year is not None and not 1 <= to_year <= 2281):
                raise ValueError
            if from_year is not None and to_year is not None and from_year > to_year:
                issues.append({"topic": topic, "issue": "inverted_chronology"})
        except ValueError:
            issues.append({"topic": topic, "issue": "invalid_chronology"})
    return issues


def publish_reviewed_catalog(args: argparse.Namespace) -> int:
    """Publish only a complete, issue-free generated catalog after an explicit editorial gate."""
    if args.editorial_approval != "approved":
        raise RuntimeError("Publishing requires --editorial-approval approved after source review")
    output_paths = [args.output, *args.additional_output]
    review_paths = [args.review, *args.additional_review]
    source_paths = [args.sources, *args.additional_sources]
    if len(output_paths) != len(review_paths) or len(output_paths) != len(source_paths):
        raise RuntimeError("Each published output requires one matching review report and source snapshot")
    if any(not path.is_file() for path in [*output_paths, *review_paths, *source_paths, args.ledger]):
        raise RuntimeError("Generated output, review report, and budget ledger are required before publishing")

    rows: list[dict[str, str]] = []
    reviews: list[dict[str, Any]] = []
    seen_topics: set[str] = set()
    seen_phrases: dict[str, str] = {}
    for output_path, review_path in zip(output_paths, review_paths):
        output_rows = read_output_rows(output_path)
        if not args.skip_editorial_overrides:
            output_rows = apply_editorial_overrides(output_rows, args.editorial_overrides)
        review = json.loads(review_path.read_text(encoding="utf-8"))
        if review.get("issues") or int(review.get("row_count", 0)) != len(output_rows):
            raise RuntimeError(f"Generated catalog has unresolved issues: {output_path}")
        for row in output_rows:
            topic = canonical_topic(row["topic"])
            if topic in seen_topics:
                raise RuntimeError(f"Duplicate canonical topic while publishing: {topic}")
            for phrase in str(row["topic"]).split(","):
                normalized_phrase = comparable_topic(phrase)
                owner = seen_phrases.get(normalized_phrase)
                if owner is not None and owner != topic:
                    raise RuntimeError(f"Ambiguous catalog phrase {normalized_phrase!r}: {owner}, {topic}")
                seen_phrases[normalized_phrase] = topic
            seen_topics.add(topic)
            rows.append(row)
        reviews.append(review)
    ledger = json.loads(args.ledger.read_text(encoding="utf-8"))
    issues = validate_output(rows)
    if issues:
        raise RuntimeError("Generated catalog has unresolved validation issues")

    args.published_output.parent.mkdir(parents=True, exist_ok=True)
    with args.published_output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(rows)
    checksum = hashlib.sha256(args.published_output.read_bytes()).hexdigest()
    source_metadata: dict[str, dict[str, Any]] = {}
    for source_path in source_paths:
        source_metadata.update(read_sources(source_path))
    published_source_rows = []
    for row in rows:
        topic = canonical_topic(row["topic"])
        metadata = source_metadata.get(topic, {})
        published_source_rows.append({
            "topic": topic,
            "title": str(metadata.get("title", topic)),
            "pageid": metadata.get("pageid"),
            "revision_id": str(row["source_revision"]),
            "revision_timestamp": metadata.get("revision_timestamp"),
            "source_url": str(row["source_url"]),
            "game": str(metadata.get("game", "")),
        })
    args.published_sources.write_text(
        "".join(json.dumps(row, ensure_ascii=False) + "\n" for row in published_source_rows),
        encoding="utf-8",
    )
    source_checksum = hashlib.sha256(args.published_sources.read_bytes()).hexdigest()
    provider_cost = sum(
        float(item.get("cost_usd", 0.0) or 0.0)
        for item in ledger.get("requests", [])
        if item.get("type") != "conservative_untracked_cost_reserve"
    )
    manifest = {
        "schema": "dialectic.worldknowledge-catalog.v1",
        "catalog_id": "fallout-3-new-vegas-ttw-official",
        "catalog_version": args.catalog_version,
        "display_name": "DIALECTIC Fallout 3, New Vegas, DLC and TTW",
        "csv_file": args.published_output.name,
        "checksum_sha256": checksum,
        "row_count": len(rows),
        "source_snapshot": {
            "file": args.published_sources.name,
            "checksum_sha256": source_checksum,
            "row_count": len(published_source_rows),
        },
        "generation": {
            "model": str(reviews[0].get("model", args.model)),
            "provider_cost_usd": round(provider_cost, 9),
            "budget_ledger_total_usd": float(ledger.get("spent_usd", 0.0)),
            "authorized_budget_usd": float(ledger.get("authorized_budget_usd", args.budget_usd)),
            "ledger_checksum_sha256": hashlib.sha256(args.ledger.read_bytes()).hexdigest(),
            "editorial_overrides_checksum_sha256": hashlib.sha256(args.editorial_overrides.read_bytes()).hexdigest(),
            "editorial_overrides_applied": not args.skip_editorial_overrides,
        },
        "coverage": {
            "games": ["Fallout 3", "Fallout: New Vegas", "official DLC", "Tale of Two Wastelands"],
            "advanced_articles": sum(int(review.get("advanced_rows", 0)) for review in reviews),
            "tag_assignments": sum(int(review.get("tag_assignments", 0)) for review in reviews),
            "category_counts": {
                category: sum(int(review.get("category_counts", {}).get(category, 0)) for review in reviews)
                for category in sorted(CATEGORIES)
            },
        },
        "editorial_review": {
            "status": "approved",
            "reviewer": args.editorial_reviewer,
            "reviewed_at": timestamp(),
            "method": "source-bound validation, deterministic checks, duplicate and chronology audit, and representative article review",
        },
    }
    write_json_atomic(args.manifest, manifest)
    print(f"[publish] csv={args.published_output} manifest={args.manifest} checksum={checksum}")
    return 0


def run(args: argparse.Namespace) -> int:
    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not args.dry_run and not api_key:
        raise RuntimeError("OPENROUTER_API_KEY is required unless --dry-run is used")
    rows = read_rows(args.input)
    sources = read_sources(args.sources)
    if args.limit > 0:
        rows = rows[: args.limit]
    missing_sources = [canonical_topic(row["topic"]) for row in rows if canonical_topic(row["topic"]) not in sources]
    if missing_sources:
        raise RuntimeError(f"Missing source records for {len(missing_sources)} topics")

    ledger = read_ledger(args.ledger, args.model, args.budget_usd)
    if args.untracked_cost_reserve_usd > 0:
        if float(ledger["spent_usd"]) + args.untracked_cost_reserve_usd > args.budget_usd:
            raise RuntimeError("Untracked-cost reserve would exceed the authorized OpenRouter budget")
        ledger["spent_usd"] = round(float(ledger["spent_usd"]) + args.untracked_cost_reserve_usd, 9)
        ledger["requests"].append({
            "type": "conservative_untracked_cost_reserve",
            "cost_usd": args.untracked_cost_reserve_usd,
            "completed_at": timestamp(),
        })
        ledger["updated_at"] = timestamp()
        write_json_atomic(args.ledger, ledger)
    prompt_price, completion_price = (0.0000014, 0.0000044)
    if api_key:
        prompt_price, completion_price = model_prices(args.openrouter_url, api_key, args.model)
    print(
        f"[preflight] model={args.model} rows={len(rows)} budget=${args.budget_usd:.2f} "
        f"spent=${float(ledger['spent_usd']):.6f} prompt=${prompt_price * 1_000_000:.4f}/M "
        f"completion=${completion_price * 1_000_000:.4f}/M"
    )

    prepared: list[dict[str, Any]] = []
    for index, row in enumerate(rows, 1):
        topic = canonical_topic(row["topic"])
        cache_path = args.cache_dir / "sources" / f"{topic}.json"
        if cache_path.exists() and not args.refresh_sources:
            item = json.loads(cache_path.read_text(encoding="utf-8"))
            # Source extracts are immutable snapshots, while reviewed input fields may be corrected.
            item.update({
                "topic_list": row["topic"],
                "game_scope": str(sources[topic].get("game", "")),
                "current_category": row["category"],
                "basic_article": row["topic_desc_basic"],
            })
            write_json_atomic(cache_path, item)
        elif args.dry_run:
            item = {
                "topic": topic,
                "topic_list": row["topic"],
                "source_title": str(sources[topic].get("title", topic)),
                "source_url": str(sources[topic].get("source_url", "")),
                "source_revision": str(sources[topic].get("revision_id", "")),
                "game_scope": str(sources[topic].get("game", "")),
                "current_category": row["category"],
                "basic_article": row["topic_desc_basic"],
                "source_extract": row["topic_desc_basic"],
            }
        else:
            item = prepare_source(row, sources[topic], args.wiki_api, args.cache_dir / "wiki", args.refresh_sources)
            write_json_atomic(cache_path, item)
        prepared.append(item)
        if index % 50 == 0:
            print(f"[sources] prepared {index}/{len(rows)}")

    estimated_input = 0
    request_count = 0
    for index in range(0, len(prepared), args.batch_size):
        batch = prepared[index : index + args.batch_size]
        estimated_input += estimated_tokens(json.dumps(batch, ensure_ascii=False)) + 900
        request_count += 1
    worst_case_cost = estimated_input * prompt_price + request_count * args.max_tokens * completion_price
    remaining = args.budget_usd - float(ledger["spent_usd"])
    print(
        f"[preflight] requests={request_count} estimated_input_tokens={estimated_input} "
        f"worst_case_new_cost=${worst_case_cost:.4f} remaining=${remaining:.4f}"
    )
    if worst_case_cost > remaining:
        raise RuntimeError("Worst-case generation estimate exceeds the remaining authorized budget")
    if args.dry_run:
        return 0

    generated_by_topic: dict[str, dict[str, Any]] = {}
    for index in range(0, len(prepared), args.batch_size):
        batch = prepared[index : index + args.batch_size]
        digest = batch_hash(batch, args.model)
        cache_path = args.cache_dir / "generated" / f"{digest}.json"
        articles: list[dict[str, Any]] = []
        retry_feedback = ""
        # A resumable run must retain the validator feedback that caused the
        # previous process to stop, otherwise a restarted model repeats the
        # same rejected wording without seeing the correction it needs.
        for previous in reversed(ledger.get("requests", [])):
            if previous.get("batch_hash") == digest and not previous.get("valid_shape"):
                retry_feedback = str(previous.get("parse_error", "")).strip()
                break
        if cache_path.exists() and not args.refresh_generation:
            cached = json.loads(cache_path.read_text(encoding="utf-8"))
            articles = cached["articles"]
            valid_shape, cache_error = validate_generated_batch(batch, articles)
            if valid_shape:
                print(f"[generate] cache {index + 1}-{index + len(batch)}/{len(prepared)}")
            else:
                print(
                    f"[generate] ignoring invalid cache for {index + 1}-{index + len(batch)}: "
                    f"{cache_error}"
                )
                retry_feedback = cache_error
                articles = []

        if not articles:
            for attempt in range(1, 4):
                estimated_request_cost = (
                    (estimated_tokens(json.dumps(batch, ensure_ascii=False)) + 900) * prompt_price
                    + args.max_tokens * completion_price
                )
                if float(ledger["spent_usd"]) + estimated_request_cost > args.budget_usd:
                    raise RuntimeError("Next request could exceed the authorized OpenRouter budget")
                response, request_payload = generate_batch(
                    base_url=args.openrouter_url,
                    api_key=api_key,
                    model=args.model,
                    batch=batch,
                    max_tokens=args.max_tokens,
                    validation_feedback=retry_feedback,
                )
                usage = response.get("usage", {})
                parse_error = ""
                try:
                    articles = parse_content(response)
                except (RuntimeError, json.JSONDecodeError) as exc:
                    articles = []
                    parse_error = str(exc)
                actual_cost = float(usage.get("cost", 0.0) or 0.0)
                if actual_cost <= 0:
                    actual_cost = (
                        int(usage.get("prompt_tokens", 0) or 0) * prompt_price
                        + int(usage.get("completion_tokens", 0) or 0) * completion_price
                    )
                ledger["spent_usd"] = round(float(ledger["spent_usd"]) + actual_cost, 9)
                ledger["prompt_tokens"] = int(ledger["prompt_tokens"]) + int(usage.get("prompt_tokens", 0) or 0)
                ledger["completion_tokens"] = int(ledger["completion_tokens"]) + int(usage.get("completion_tokens", 0) or 0)
                ledger["requests"].append({
                    "batch_hash": digest,
                    "topics": [item["topic"] for item in batch],
                    "attempt": attempt,
                    "valid_shape": False,
                    "cost_usd": actual_cost,
                    "prompt_tokens": int(usage.get("prompt_tokens", 0) or 0),
                    "completion_tokens": int(usage.get("completion_tokens", 0) or 0),
                    "response_id": str(usage.get("id", "")),
                    "parse_error": parse_error,
                    "completed_at": timestamp(),
                })
                valid_shape, content_error = validate_generated_batch(batch, articles)
                if content_error:
                    parse_error = "; ".join(filter(None, [parse_error, content_error]))
                    retry_feedback = content_error
                ledger["requests"][-1]["valid_shape"] = valid_shape
                ledger["requests"][-1]["parse_error"] = parse_error
                ledger["updated_at"] = timestamp()
                write_json_atomic(args.ledger, ledger)
                print(
                    f"[generate] {index + 1}-{index + len(batch)}/{len(prepared)} attempt={attempt} "
                    f"articles={len(articles)} cost=${actual_cost:.6f} total=${float(ledger['spent_usd']):.6f}"
                )
                if valid_shape:
                    write_json_atomic(cache_path, {
                        "batch_hash": digest,
                        "model": args.model,
                        "articles": articles,
                        "usage": usage,
                        "request_hash": hashlib.sha256(json.dumps(request_payload, sort_keys=True).encode("utf-8")).hexdigest(),
                        "generated_at": timestamp(),
                    })
                    break
                if attempt == 3:
                    raise RuntimeError("GLM response did not return exactly the requested topics after three attempts")
                time.sleep(max(args.delay, 0.5))
            time.sleep(args.delay)
        for article in articles:
            topic = str(article.get("topic", "")).strip()
            if topic in generated_by_topic:
                raise RuntimeError(f"GLM returned duplicate topic {topic}")
            generated_by_topic[topic] = article

    output_rows = apply_editorial_overrides(
        [normalize_generated(item, generated_by_topic[item["topic"]]) for item in prepared],
        args.editorial_overrides,
    )
    issues = validate_output(output_rows)
    args.output.parent.mkdir(parents=True, exist_ok=True)
    with args.output.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=OUTPUT_FIELDS, lineterminator="\n")
        writer.writeheader()
        writer.writerows(output_rows)
    write_json_atomic(args.review, {
        "schema": "dialectic.worldknowledge-review.v1",
        "model": args.model,
        "row_count": len(output_rows),
        "issues": issues,
        "category_counts": {
            category: sum(1 for row in output_rows if row["category"] == category)
            for category in sorted(CATEGORIES)
        },
        "advanced_rows": sum(1 for row in output_rows if row["topic_desc"]),
        "tag_assignments": sum(len(row["tags"].split(",")) for row in output_rows),
        "spent_usd": float(ledger["spent_usd"]),
        "requires_editorial_approval": True,
        "generated_at": timestamp(),
    })
    print(f"[done] output={args.output} review={args.review} issues={len(issues)}")
    return 1 if issues else 0


def parser() -> argparse.ArgumentParser:
    result = argparse.ArgumentParser(description=__doc__)
    result.add_argument("--input", type=Path, default=DEFAULT_INPUT)
    result.add_argument("--sources", type=Path, default=DEFAULT_SOURCES)
    result.add_argument("--output", type=Path, default=DEFAULT_OUTPUT)
    result.add_argument("--review", type=Path, default=DEFAULT_REVIEW)
    result.add_argument("--ledger", type=Path, default=DEFAULT_LEDGER)
    result.add_argument("--cache-dir", type=Path, default=DEFAULT_CACHE)
    result.add_argument("--published-output", type=Path, default=DEFAULT_PUBLISHED_OUTPUT)
    result.add_argument("--manifest", type=Path, default=DEFAULT_MANIFEST)
    result.add_argument("--published-sources", type=Path, default=DEFAULT_PUBLISHED_SOURCES)
    result.add_argument("--additional-output", action="append", type=Path, default=[])
    result.add_argument("--additional-review", action="append", type=Path, default=[])
    result.add_argument("--additional-sources", action="append", type=Path, default=[])
    result.add_argument("--expansion-manifest", type=Path, default=DEFAULT_EXPANSION_MANIFEST)
    result.add_argument("--editorial-overrides", type=Path, default=DEFAULT_EDITORIAL_OVERRIDES)
    result.add_argument("--skip-editorial-overrides", action="store_true")
    result.add_argument("--expansion-input", type=Path, default=DEFAULT_EXPANSION_INPUT)
    result.add_argument("--expansion-sources", type=Path, default=DEFAULT_EXPANSION_SOURCES)
    result.add_argument("--model", default=DEFAULT_MODEL)
    result.add_argument("--openrouter-url", default=DEFAULT_OPENROUTER_URL)
    result.add_argument("--wiki-api", default=DEFAULT_WIKI_API)
    result.add_argument("--budget-usd", type=float, default=DEFAULT_BUDGET_USD)
    result.add_argument("--batch-size", type=int, default=4)
    result.add_argument("--max-tokens", type=int, default=3500)
    result.add_argument("--limit", type=int, default=0)
    result.add_argument("--delay", type=float, default=0.2)
    result.add_argument("--refresh-sources", action="store_true")
    result.add_argument("--refresh-generation", action="store_true")
    result.add_argument("--untracked-cost-reserve-usd", type=float, default=0.0)
    result.add_argument("--publish", action="store_true")
    result.add_argument("--prepare-expansion", action="store_true")
    result.add_argument("--editorial-approval", default="")
    result.add_argument("--editorial-reviewer", default="")
    result.add_argument("--catalog-version", default="parity-v1-2026-08-13")
    result.add_argument("--dry-run", action="store_true")
    return result


def main() -> int:
    args = parser().parse_args()
    if not 0 < args.budget_usd <= DEFAULT_BUDGET_USD:
        print(f"error: --budget-usd must be greater than 0 and no more than ${DEFAULT_BUDGET_USD:.2f}", file=sys.stderr)
        return 2
    if not 0 <= args.untracked_cost_reserve_usd <= 1:
        print("error: --untracked-cost-reserve-usd must be between 0 and 1", file=sys.stderr)
        return 2
    if not 1 <= args.batch_size <= 8 or not 500 <= args.max_tokens <= 8000:
        print("error: unsupported batch size or max token limit", file=sys.stderr)
        return 2
    try:
        if args.prepare_expansion:
            return prepare_expansion_inputs(args)
        if args.publish:
            if not args.editorial_reviewer.strip():
                raise RuntimeError("Publishing requires --editorial-reviewer")
            return publish_reviewed_catalog(args)
        return run(args)
    except Exception as exc:
        print(f"error: {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
