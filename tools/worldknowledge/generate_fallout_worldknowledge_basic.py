#!/usr/bin/env python3
"""Discover and build basic Fallout world knowledge for Dialectic."""

from __future__ import annotations

import argparse
import csv
import html
import json
import os
import re
import sys
import time
from dataclasses import dataclass
from pathlib import Path
from typing import Any, Iterable
from urllib.error import HTTPError, URLError
from urllib.parse import quote, urlencode
from urllib.request import Request, urlopen


SCRIPT_DIR = Path(__file__).resolve().parent
DEFAULT_API_URL = "https://fallout.wiki/api.php"
DEFAULT_WIKI_URL = "https://fallout.wiki/wiki/"
DEFAULT_OPENROUTER_URL = "https://openrouter.ai/api/v1/chat/completions"
DEFAULT_MODEL = "z-ai/glm-5.2"

MANIFEST_FIELDS = [
    "include",
    "topic",
    "page_title",
    "category",
    "game",
    "source_category",
    "source_url",
    "pageid",
]
OUTPUT_FIELDS = [
    "topic",
    "topic_desc",
    "knowledge_class",
    "topic_desc_basic",
    "knowledge_class_basic",
    "tags",
    "category",
]
ALLOWED_CATEGORIES = {"location", "creature", "faction", "person", "event"}
TRUE_VALUES = {"1", "true", "yes", "y", "include", "approved"}

DEFAULT_CATEGORY_ROOTS = [
    ("location", "fallout3", "Fallout 3 locations"),
    ("location", "fnv", "Fallout: New Vegas locations"),
    ("faction", "fallout3", "Fallout 3 factions"),
    ("faction", "fnv", "Fallout: New Vegas factions"),
    ("creature", "fallout3", "Fallout 3 creatures"),
    ("creature", "fnv", "Fallout: New Vegas creatures"),
    ("person", "fallout3", "Fallout 3 characters"),
    ("person", "fnv", "Fallout: New Vegas characters"),
]

DEFAULT_CURATED_QUOTAS = {
    "location": 110,
    "faction": 45,
    "creature": 40,
    "person": 125,
    "event": 30,
}

OTHER_PRODUCT_MARKERS = (
    "fallout 4",
    "fallout 76",
    "fallout shelter",
    "fallout: wasteland warfare",
    "fallout television",
    "magic: the gathering",
    "van buren",
    "winter of atom",
    "fallout tactics",
)

CURATION_TITLE_BLOCKLIST = (
    "cut content",
    "concept art",
    "ending slides",
    " reputation",
    " disguise",
    " armor paint",
    " military and ranks",
    " locations",
    " history",
    " unused",
    "test level",
    "test cell",
    "testmap",
    "player character housing",
)

CURATION_EXACT_TITLE_BLOCKLIST = {
    "arms merchant",
    "fallout 3 merchants",
    "legionary assassin",
    "lone wanderer",
}

CURATION_SOURCE_BLOCKLIST = (
    "mentioned-only",
    "cut content",
    "unused",
)

GAMEPLAY_TERMS = (
    "hit points",
    "skill check",
    "experience points",
    "quest marker",
    "player character must",
    "the player must",
    "walkthrough",
    "achievement",
    "trophy",
    "gameplay",
    "cut content",
    "fallout 3",
    "fallout: new vegas",
    "fallout new vegas",
    "in the game",
    "v.a.t.s.",
    "background once",
)


@dataclass(frozen=True)
class RootCategory:
    category: str
    game: str
    title: str


def normalize_space(value: str) -> str:
    return re.sub(r"\s+", " ", value).strip()


def ascii_punctuation(value: str) -> str:
    replacements = {
        "\u2013": "-",
        "\u2014": "-",
        "\u2018": "'",
        "\u2019": "'",
        "\u201c": '"',
        "\u201d": '"',
        "\u2026": "...",
        "\u00a0": " ",
    }
    for source, replacement in replacements.items():
        value = value.replace(source, replacement)
    return value


def topic_key(value: str) -> str:
    value = html.unescape(ascii_punctuation(value)).lower()
    value = re.sub(r"\([^)]*\)", " ", value)
    value = re.sub(r"[^a-z0-9]+", "_", value)
    return value.strip("_")


def split_sentences(value: str) -> list[str]:
    protected = normalize_space(value)
    sentinel = "\u0000"
    protected = re.sub(
        r"\b(?:Mr|Mrs|Ms|Dr|St|Lt|Col|Sgt|Capt|Cpl|Pvt|Gen)\.",
        lambda match: match.group(0).replace(".", sentinel),
        protected,
        flags=re.IGNORECASE,
    )
    protected = re.sub(
        r"\b(?:[A-Z]\.){2,}",
        lambda match: match.group(0).replace(".", sentinel),
        protected,
    )
    return [
        part.replace(sentinel, ".").strip()
        for part in re.split(r"(?<=[.!?])\s+", protected)
        if part.strip()
    ]


def normalize_summary(value: str) -> str:
    value = ascii_punctuation(html.unescape(value))
    value = re.sub(r"```(?:json)?|```", "", value, flags=re.IGNORECASE)
    value = normalize_space(value.strip().strip('"').strip("'"))
    result = value
    if result and result[-1] not in ".!?":
        result += "."
    return result


def fit_summary_limits(value: str) -> str:
    sentences = split_sentences(normalize_summary(value))[:4]
    while len(sentences) > 2 and len(" ".join(sentences).split()) > 260:
        sentences.pop()
    result = normalize_summary(" ".join(sentences))
    if len(result.split()) > 260:
        result = " ".join(result.split()[:260]).rstrip(" ,;:") + "."
    return result


def parse_generated_summary(content: str) -> str:
    cleaned = content.strip()
    cleaned = re.sub(r"^```(?:json)?\s*", "", cleaned, flags=re.IGNORECASE)
    cleaned = re.sub(r"\s*```$", "", cleaned)
    candidates = [cleaned]
    object_match = re.search(r"\{.*\}", cleaned, flags=re.DOTALL)
    if object_match and object_match.group(0) != cleaned:
        candidates.insert(0, object_match.group(0))
    for candidate in candidates:
        try:
            parsed = json.loads(candidate)
            if isinstance(parsed, dict) and isinstance(parsed.get("summary"), str):
                return fit_summary_limits(parsed["summary"])
        except json.JSONDecodeError:
            continue
    plain_summary = fit_summary_limits(cleaned)
    if plain_summary and not validate_summary(plain_summary):
        return plain_summary
    raise ValueError("response content was neither summary JSON nor a valid plain summary")


def wiki_page_url(base_url: str, title: str) -> str:
    return base_url.rstrip("/") + "/" + quote(title.replace(" ", "_"), safe="()'_-:")


def request_json(
    url: str,
    *,
    method: str = "GET",
    payload: dict[str, Any] | None = None,
    headers: dict[str, str] | None = None,
    timeout: float = 90.0,
    retries: int = 5,
) -> dict[str, Any]:
    body = None
    request_headers = {
        "User-Agent": "DialecticWorldKnowledgeBuilder/0.1 (+https://dwemerdynamics.com)",
        "Accept": "application/json",
    }
    if headers:
        request_headers.update(headers)
    if payload is not None:
        body = json.dumps(payload).encode("utf-8")
        request_headers["Content-Type"] = "application/json"

    for attempt in range(1, retries + 1):
        request = Request(url, data=body, headers=request_headers, method=method)
        try:
            with urlopen(request, timeout=timeout) as response:
                return json.loads(response.read().decode("utf-8"))
        except HTTPError as exc:
            retryable = exc.code in {408, 409, 425, 429, 500, 502, 503, 504}
            if attempt >= retries or not retryable:
                detail = exc.read().decode("utf-8", errors="replace")[:500]
                raise RuntimeError(f"HTTP {exc.code} for {url}: {detail}") from exc
        except (URLError, TimeoutError, json.JSONDecodeError) as exc:
            if attempt >= retries:
                raise RuntimeError(f"Request failed for {url}: {exc}") from exc
        time.sleep(min(8.0, 1.25 * attempt))
    raise RuntimeError(f"Request failed for {url}")


def mediawiki_query(api_url: str, params: dict[str, Any]) -> dict[str, Any]:
    query = {"format": "json", "formatversion": "2", **params}
    return request_json(f"{api_url}?{urlencode(query)}")


def category_members(api_url: str, category_title: str) -> Iterable[dict[str, Any]]:
    continuation: dict[str, Any] = {}
    while True:
        response = mediawiki_query(
            api_url,
            {
                "action": "query",
                "list": "categorymembers",
                "cmtitle": "Category:" + category_title.removeprefix("Category:"),
                "cmprop": "ids|title|type",
                "cmtype": "page|subcat",
                "cmlimit": "max",
                **continuation,
            },
        )
        yield from response.get("query", {}).get("categorymembers", [])
        continuation = response.get("continue", {})
        if not continuation:
            return


def discover_root(
    api_url: str,
    root: RootCategory,
    *,
    depth: int,
    max_pages: int,
    delay: float,
) -> list[dict[str, Any]]:
    queue: list[tuple[str, int]] = [(root.title, 0)]
    visited_categories: set[str] = set()
    pages: list[dict[str, Any]] = []

    while queue:
        current_category, current_depth = queue.pop(0)
        normalized_category = current_category.lower()
        if normalized_category in visited_categories:
            continue
        visited_categories.add(normalized_category)

        for member in category_members(api_url, current_category):
            namespace = int(member.get("ns", -1))
            title = str(member.get("title", "")).strip()
            if namespace == 14 and current_depth < depth:
                queue.append((title.removeprefix("Category:"), current_depth + 1))
            elif namespace == 0 and title and is_candidate_title(title, root.category):
                pages.append(
                    {
                        "include": "",
                        "topic": topic_key(title),
                        "page_title": title,
                        "category": root.category,
                        "game": root.game,
                        "source_category": current_category,
                        "source_url": "",
                        "pageid": member.get("pageid", ""),
                    }
                )
                if max_pages and len(pages) >= max_pages:
                    return pages
        if delay:
            time.sleep(delay)
    return pages


def is_candidate_title(title: str, category: str) -> bool:
    lowered = normalize_space(title).lower()
    if lowered.startswith(("list of ", "category:")):
        return False
    if any(term in lowered for term in ("cut content", "developer test", "concept art")):
        return False
    category_index_terms = {
        "location": (" locations", " location list", " map"),
        "person": (" characters", " character list"),
        "creature": (" creatures", " creature list"),
        "faction": (" factions", " faction list"),
        "event": (" events", " event list"),
    }
    return not any(lowered.endswith(term) for term in category_index_terms.get(category, ()))


def merge_discovered_rows(rows: Iterable[dict[str, Any]], wiki_url: str) -> list[dict[str, Any]]:
    merged: dict[str, dict[str, Any]] = {}
    for row in rows:
        key = f'{row["category"]}:{str(row["page_title"]).strip().lower()}'
        if key not in merged:
            merged[key] = dict(row)
            merged[key]["source_url"] = wiki_page_url(wiki_url, str(row["page_title"]))
            continue
        existing = merged[key]
        games = {part for part in str(existing["game"]).split("|") if part}
        games.update(part for part in str(row["game"]).split("|") if part)
        existing["game"] = "both" if games == {"fallout3", "fnv"} else "|".join(sorted(games))
        roots = {part for part in str(existing["source_category"]).split("|") if part}
        roots.add(str(row["source_category"]))
        existing["source_category"] = "|".join(sorted(roots))
    return sorted(merged.values(), key=lambda row: (str(row["category"]), str(row["page_title"])))


def parse_root(value: str) -> RootCategory:
    parts = value.split("=", 2)
    if len(parts) != 3 or parts[0] not in ALLOWED_CATEGORIES:
        raise argparse.ArgumentTypeError("Root must use category=game=MediaWiki category title")
    return RootCategory(parts[0], parts[1], parts[2])


def write_csv(path: Path, fieldnames: list[str], rows: Iterable[dict[str, Any]]) -> None:
    path.parent.mkdir(parents=True, exist_ok=True)
    with path.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=fieldnames, extrasaction="ignore")
        writer.writeheader()
        writer.writerows(rows)


def read_csv_rows(path: Path) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        return [
            {str(key).strip().lower(): str(value or "").strip() for key, value in row.items()}
            for row in csv.DictReader(handle)
        ]


def chunked(values: list[str], size: int) -> Iterable[list[str]]:
    for index in range(0, len(values), size):
        yield values[index : index + size]


def fetch_candidate_metadata(
    api_url: str,
    rows: list[dict[str, str]],
    cache_path: Path,
    refresh: bool,
    delay: float,
) -> dict[str, dict[str, Any]]:
    metadata: dict[str, dict[str, Any]] = {}
    if cache_path.exists() and not refresh:
        try:
            metadata = json.loads(cache_path.read_text(encoding="utf-8"))
        except (OSError, json.JSONDecodeError):
            metadata = {}

    page_ids = sorted({row.get("pageid", "") for row in rows if row.get("pageid")})
    missing_ids = [page_id for page_id in page_ids if page_id not in metadata]
    for batch_number, batch in enumerate(chunked(missing_ids, 20), start=1):
        continuation: dict[str, Any] = {}
        while True:
            response = mediawiki_query(
                api_url,
                {
                    "action": "query",
                    "prop": "info|categories",
                    "pageids": "|".join(batch),
                    "cllimit": "max",
                    **continuation,
                },
            )
            for page in response.get("query", {}).get("pages", []):
                page_id = str(page.get("pageid", ""))
                if not page_id:
                    continue
                current = metadata.setdefault(
                    page_id,
                    {
                        "title": page.get("title", ""),
                        "length": int(page.get("length", 0) or 0),
                        "categories": [],
                    },
                )
                current["length"] = max(int(current.get("length", 0)), int(page.get("length", 0) or 0))
                categories = {
                    str(category.get("title", "")).removeprefix("Category:")
                    for category in page.get("categories", [])
                }
                categories.update(str(value) for value in current.get("categories", []))
                current["categories"] = sorted(value for value in categories if value)
            continuation = response.get("continue", {})
            if not continuation:
                break
        if batch_number % 10 == 0:
            cache_path.parent.mkdir(parents=True, exist_ok=True)
            cache_path.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
            print(f"[curate] metadata batches completed: {batch_number}/{(len(missing_ids) + 19) // 20}")
        if delay:
            time.sleep(delay)

    cache_path.parent.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(metadata, ensure_ascii=False, indent=2), encoding="utf-8")
    return metadata


def candidate_matches_category(row: dict[str, str], metadata: dict[str, Any]) -> bool:
    category = row.get("category", "")
    title = row.get("page_title", "").lower()
    categories = [str(value).lower() for value in metadata.get("categories", [])]
    source_categories = row.get("source_category", "").lower()
    if any(marker in title for marker in OTHER_PRODUCT_MARKERS):
        return False
    if any(marker in title for marker in CURATION_TITLE_BLOCKLIST):
        return False
    if title in CURATION_EXACT_TITLE_BLOCKLIST:
        return False
    if re.search(r"(?:^|[^a-z])test|dlc\d+test|test(?:cell|map|level)", title):
        return False
    if any(marker in source_categories for marker in CURATION_SOURCE_BLOCKLIST):
        return False
    if int(metadata.get("length", 0) or 0) < 2000:
        return False

    if category == "location":
        return any("location" in value for value in categories)
    if category == "person":
        return any("character" in value for value in categories)
    if category == "creature":
        return any("creature" in value or "robot" in value for value in categories)
    if category == "faction":
        return any("faction" in value for value in categories) and not any(
            "character" in value for value in categories
        )
    return category == "event"


def curation_score(row: dict[str, str], metadata: dict[str, Any]) -> int:
    title = row.get("page_title", "")
    score = int(metadata.get("length", 0) or 0)
    if "(" not in title:
        score += 30000
    source_category = row.get("source_category", "").lower()
    if source_category in {
        "fallout 3 locations",
        "fallout: new vegas locations",
        "fallout 3 factions",
        "fallout: new vegas factions",
        "fallout 3 creatures",
        "fallout: new vegas creatures",
        "fallout 3 characters",
        "fallout: new vegas characters",
    }:
        score += 120000
    if any(marker in source_category for marker in ("companions", "settlements", "dlc characters")):
        score += 50000
    return score


def parse_quota(value: str) -> tuple[str, int]:
    parts = value.split("=", 1)
    if len(parts) != 2 or parts[0] not in ALLOWED_CATEGORIES:
        raise argparse.ArgumentTypeError("Quota must use category=count")
    try:
        count = int(parts[1])
    except ValueError as exc:
        raise argparse.ArgumentTypeError("Quota count must be an integer") from exc
    if count < 0:
        raise argparse.ArgumentTypeError("Quota count must not be negative")
    return parts[0], count


def run_curate(args: argparse.Namespace) -> int:
    candidate_rows: list[dict[str, str]] = []
    for path in args.candidates:
        candidate_rows.extend(read_csv_rows(path))
    seed_rows: list[dict[str, str]] = []
    for path in args.seed:
        seed_rows.extend(read_csv_rows(path))

    metadata = fetch_candidate_metadata(
        args.api_url,
        candidate_rows,
        args.metadata_cache,
        args.refresh_metadata,
        args.delay,
    )
    quotas = dict(DEFAULT_CURATED_QUOTAS)
    for category, count in args.quota or []:
        quotas[category] = count

    selected: list[dict[str, str]] = []
    selected_topics: set[str] = set()
    selected_titles: set[str] = set()
    for row in seed_rows:
        topic = topic_key(row.get("topic") or row.get("page_title", ""))
        title = topic_key(row.get("page_title", ""))
        if topic and topic not in selected_topics and title not in selected_titles:
            row["topic"] = topic
            row["include"] = "1"
            selected.append(row)
            selected_topics.add(topic)
            selected_titles.add(title)

    for category in ("location", "faction", "creature", "person", "event"):
        target = quotas.get(category, 0)
        current_count = sum(1 for row in selected if row.get("category") == category)
        if current_count >= target:
            continue
        candidates = []
        for row in candidate_rows:
            if row.get("category") != category:
                continue
            topic = topic_key(row.get("topic") or row.get("page_title", ""))
            title = topic_key(row.get("page_title", ""))
            page_metadata = metadata.get(row.get("pageid", ""), {})
            if (
                not topic
                or topic in selected_topics
                or title in selected_titles
                or not candidate_matches_category(row, page_metadata)
            ):
                continue
            candidates.append((curation_score(row, page_metadata), row))
        candidates.sort(key=lambda item: (-item[0], item[1].get("page_title", "")))

        game_buckets = {
            "fallout3": [item for item in candidates if "fallout3" in item[1].get("game", "")],
            "fnv": [item for item in candidates if "fnv" in item[1].get("game", "")],
            "both": [item for item in candidates if item[1].get("game") == "both"],
        }
        indexes = {key: 0 for key in game_buckets}
        game_order = ["fallout3", "fnv", "both"]
        while current_count < target:
            added = False
            for game in game_order:
                bucket = game_buckets[game]
                while indexes[game] < len(bucket):
                    _, row = bucket[indexes[game]]
                    indexes[game] += 1
                    topic = topic_key(row.get("topic") or row.get("page_title", ""))
                    title = topic_key(row.get("page_title", ""))
                    if topic in selected_topics or title in selected_titles:
                        continue
                    output_row = {field: row.get(field, "") for field in MANIFEST_FIELDS}
                    output_row["include"] = "1"
                    output_row["topic"] = topic
                    selected.append(output_row)
                    selected_topics.add(topic)
                    selected_titles.add(title)
                    current_count += 1
                    added = True
                    break
                if current_count >= target:
                    break
            if not added:
                raise RuntimeError(f"Unable to satisfy {category} quota {target}; selected {current_count}")

    selected.sort(key=lambda row: (row.get("category", ""), row.get("game", ""), row.get("page_title", "")))
    write_csv(args.output, MANIFEST_FIELDS, selected)
    print(f"[curate] wrote {len(selected)} approved topics to {args.output}")
    for category in sorted(ALLOWED_CATEGORIES):
        count = sum(1 for row in selected if row.get("category") == category)
        print(f"[curate] {category}: {count}")
    return 0


def read_manifest(path: Path, include_all: bool) -> list[dict[str, str]]:
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        fields = {str(field).strip().lower() for field in (reader.fieldnames or [])}
        required = {"topic", "page_title", "category", "game"}
        missing = required - fields
        if missing:
            raise RuntimeError(f"Manifest is missing columns: {', '.join(sorted(missing))}")
        rows = []
        for raw_row in reader:
            row = {str(key).strip().lower(): str(value or "").strip() for key, value in raw_row.items()}
            if include_all or row.get("include", "").lower() in TRUE_VALUES:
                rows.append(row)
        return rows


def fetch_page(
    api_url: str,
    title: str,
    topic: str,
    cache_dir: Path,
    refresh: bool,
) -> dict[str, Any]:
    cache_path = cache_dir / f"{topic}.json"
    if cache_path.exists() and not refresh:
        return json.loads(cache_path.read_text(encoding="utf-8"))

    response = mediawiki_query(
        api_url,
        {
            "action": "query",
            "prop": "extracts|categories|info|revisions",
            "titles": title,
            "redirects": "1",
            "explaintext": "1",
            "exsectionformat": "plain",
            "cllimit": "max",
            "inprop": "url",
            "rvprop": "ids|timestamp",
        },
    )
    pages = response.get("query", {}).get("pages", [])
    if not pages or pages[0].get("missing") is True:
        raise RuntimeError(f"Fallout Wiki page not found: {title}")

    page = pages[0]
    payload = {
        "requested_title": title,
        "pageid": page.get("pageid"),
        "title": page.get("title", title),
        "url": page.get("fullurl", ""),
        "extract": page.get("extract", ""),
        "categories": [
            str(category.get("title", "")).removeprefix("Category:")
            for category in page.get("categories", [])
        ],
        "revision": (page.get("revisions") or [{}])[0],
        "fetched_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
    }
    cache_dir.mkdir(parents=True, exist_ok=True)
    cache_path.write_text(json.dumps(payload, ensure_ascii=False, indent=2), encoding="utf-8")
    return payload


def generated_summary_cache_path(cache_dir: Path, topic: str) -> Path:
    return cache_dir / f"{topic}.json"


def read_generated_summary_cache(
    cache_dir: Path,
    topic: str,
    model: str,
    revision_id: Any,
) -> str:
    path = generated_summary_cache_path(cache_dir, topic)
    if not path.exists():
        return ""
    try:
        payload = json.loads(path.read_text(encoding="utf-8"))
    except (OSError, json.JSONDecodeError):
        return ""
    if payload.get("model") != model or payload.get("revision_id") != revision_id:
        return ""
    summary = str(payload.get("summary", "")).strip()
    return summary if summary and not validate_summary(summary) else ""


def write_generated_summary_cache(
    cache_dir: Path,
    *,
    topic: str,
    model: str,
    revision_id: Any,
    summary: str,
) -> None:
    cache_dir.mkdir(parents=True, exist_ok=True)
    path = generated_summary_cache_path(cache_dir, topic)
    path.write_text(
        json.dumps(
            {
                "topic": topic,
                "model": model,
                "revision_id": revision_id,
                "summary": summary,
                "generated_at": time.strftime("%Y-%m-%dT%H:%M:%SZ", time.gmtime()),
            },
            ensure_ascii=False,
            indent=2,
        ),
        encoding="utf-8",
    )


def fallback_summary(extract: str) -> str:
    cleaned = re.sub(r"^=+.*?=+$", " ", extract, flags=re.MULTILINE)
    sentences = []
    for sentence in split_sentences(cleaned):
        lowered = sentence.lower()
        if any(term in lowered for term in GAMEPLAY_TERMS):
            continue
        candidate = " ".join([*sentences, sentence])
        if sentences and len(candidate.split()) > 100:
            break
        sentences.append(sentence)
        if len(sentences) >= 4 or len(" ".join(sentences).split()) >= 75:
            break
    return normalize_summary(" ".join(sentences))


def call_openrouter(
    *,
    api_url: str,
    api_key: str,
    model: str,
    title: str,
    category: str,
    game: str,
    extract: str,
) -> str:
    system_prompt = (
        "Write broadly known in-world Fallout lore for an NPC context database. "
        "Return two to four plain sentences and 40 to 220 words. Use only the supplied source. "
        "Mention common aliases naturally. Do not use Markdown, gameplay mechanics, statistics, "
        "quest instructions, player decisions, endings, or outcomes caused by a player character. "
        "For Fallout 3, do not treat events after the beginning of Fallout 3 in 2277 as established. "
        "For New Vegas, do not treat events after the beginning of New Vegas in 2281 as established."
    )
    payload = {
        "model": model,
        "temperature": 0.2,
        "reasoning": {"effort": "low", "exclude": True},
        "messages": [
            {"role": "system", "content": system_prompt},
            {
                "role": "user",
                "content": (
                    f"Page: {title}\nDataset category: {category}\nGame scope: {game}\n\n"
                    f"Source introduction:\n{extract[:14000]}"
                ),
            },
        ],
        "response_format": {
            "type": "json_schema",
            "json_schema": {
                "name": "fallout_world_knowledge_summary",
                "strict": True,
                "schema": {
                    "type": "object",
                    "properties": {"summary": {"type": "string"}},
                    "required": ["summary"],
                    "additionalProperties": False,
                },
            },
        },
    }
    last_error = "missing response content"
    for max_tokens in (900, 1400, 1800):
        payload["max_tokens"] = max_tokens
        response = request_json(
            api_url,
            method="POST",
            payload=payload,
            headers={"Authorization": f"Bearer {api_key}"},
            timeout=120.0,
        )
        try:
            choice = response["choices"][0]
            content = choice["message"]["content"]
            if not isinstance(content, str) or not content.strip():
                last_error = f"finish_reason={choice.get('finish_reason', 'unknown')} with no content"
                continue
            return parse_generated_summary(content)
        except (KeyError, IndexError, TypeError, ValueError) as exc:
            last_error = f"{exc}; content={content[:160]!r}"
    raise RuntimeError(f"OpenRouter did not return a valid structured summary after retry: {last_error}")


def validate_summary(summary: str) -> list[str]:
    issues = []
    word_count = len(summary.split())
    sentence_count = len(split_sentences(summary))
    if not 40 <= word_count <= 260:
        issues.append(f"summary has {word_count} words; expected 40-260")
    if not 2 <= sentence_count <= 4:
        issues.append(f"summary has {sentence_count} sentences; expected 2-4")
    if any(marker in summary for marker in ("#", "```", "**", "<", ">")):
        issues.append("summary contains markup")
    if summary.lstrip().startswith("{") or '"summary"' in summary[:80].lower():
        issues.append("summary contains a JSON response wrapper")
    if re.search(r"\b(?:Mr|Mrs|Ms|Dr|Lt|Col|Sgt|Capt|Cpl|Pvt|Gen|St)\.$", summary):
        issues.append("summary ends with an incomplete abbreviation")
    lowered = summary.lower()
    matched_terms = [term for term in GAMEPLAY_TERMS if term in lowered]
    if matched_terms:
        issues.append("summary contains gameplay terms: " + ", ".join(matched_terms))
    return issues


def validate_dataset(path: Path) -> dict[str, Any]:
    errors: list[dict[str, Any]] = []
    topics: set[str] = set()
    with path.open("r", encoding="utf-8-sig", newline="") as handle:
        reader = csv.DictReader(handle)
        if reader.fieldnames != OUTPUT_FIELDS:
            errors.append({"row": 1, "topic": "", "issues": ["CSV columns do not match the Dialectic contract"]})
        rows = list(reader)

    for index, row in enumerate(rows, start=2):
        topic = str(row.get("topic", "")).strip()
        issues = []
        if not topic:
            issues.append("topic is blank")
        elif topic != topic_key(topic):
            issues.append("topic is not a normalized key")
        elif topic in topics:
            issues.append("topic is duplicated")
        topics.add(topic)
        category = str(row.get("category", "")).strip()
        if category not in ALLOWED_CATEGORIES:
            issues.append(f"unsupported category: {category}")
        for blank_field in ("topic_desc", "knowledge_class", "knowledge_class_basic", "tags"):
            if str(row.get(blank_field, "")).strip():
                issues.append(f"{blank_field} must remain blank")
        summary = str(row.get("topic_desc_basic", "")).strip()
        if not summary:
            issues.append("topic_desc_basic is blank")
        else:
            issues.extend(validate_summary(summary))
        if issues:
            errors.append({"row": index, "topic": topic, "issues": issues})
    return {"valid": not errors, "rows": len(rows), "errors": errors}


def run_discover(args: argparse.Namespace) -> int:
    roots = args.root or [RootCategory(*values) for values in DEFAULT_CATEGORY_ROOTS]
    rows: list[dict[str, Any]] = []
    for root in roots:
        print(f"[discover] {root.game} {root.category}: {root.title}")
        rows.extend(
            discover_root(
                args.api_url,
                root,
                depth=args.depth,
                max_pages=args.max_pages_per_root,
                delay=args.delay,
            )
        )
    merged = merge_discovered_rows(rows, args.wiki_url)
    write_csv(args.output, MANIFEST_FIELDS, merged)
    print(f"[discover] wrote {len(merged)} candidates to {args.output}")
    print("[discover] review the file and set include=1 for approved pages")
    return 0


def run_build(args: argparse.Namespace) -> int:
    manifest_rows = read_manifest(args.manifest, args.include_all)
    if args.limit:
        manifest_rows = manifest_rows[: args.limit]
    if not manifest_rows:
        raise RuntimeError("No approved manifest rows were found")

    api_key = os.environ.get("OPENROUTER_API_KEY", "").strip()
    if not args.no_llm and not api_key:
        raise RuntimeError("OPENROUTER_API_KEY is required unless --no-llm is used")

    output_rows: list[dict[str, str]] = []
    source_rows: list[dict[str, Any]] = []
    generation_issues: list[dict[str, Any]] = []

    for index, manifest_row in enumerate(manifest_rows, start=1):
        title = manifest_row["page_title"]
        topic = topic_key(manifest_row.get("topic") or title)
        category = manifest_row["category"].lower()
        game = manifest_row["game"].lower()
        print(f"[build {index}/{len(manifest_rows)}] {title}")

        page = fetch_page(args.api_url, title, topic, args.cache_dir, args.refresh)
        extract = normalize_space(str(page.get("extract", "")))
        if len(extract) < 80:
            generation_issues.append({"topic": topic, "issues": ["source introduction is too short"]})
            continue

        revision = page.get("revision", {}) or {}
        revision_id = revision.get("revid")
        summary = ""
        if not args.no_llm and not args.refresh_generation:
            summary = read_generated_summary_cache(
                args.generation_cache_dir,
                topic,
                args.model,
                revision_id,
            )
            if summary:
                print(f"[build {index}/{len(manifest_rows)}] using cached GLM summary")
        try:
            if not summary:
                summary = (
                    fallback_summary(extract)
                    if args.no_llm
                    else call_openrouter(
                        api_url=args.openrouter_url,
                        api_key=api_key,
                        model=args.model,
                        title=str(page.get("title", title)),
                        category=category,
                        game=game,
                        extract=extract,
                    )
                )
                if not args.no_llm and not validate_summary(summary):
                    write_generated_summary_cache(
                        args.generation_cache_dir,
                        topic=topic,
                        model=args.model,
                        revision_id=revision_id,
                        summary=summary,
                    )
        except RuntimeError as exc:
            generation_issues.append({"topic": topic, "issues": [str(exc)]})
            print(f"[build {index}/{len(manifest_rows)}] failed: {exc}")
            continue
        issues = validate_summary(summary)
        if issues:
            generation_issues.append({"topic": topic, "issues": issues})

        output_rows.append(
            {
                "topic": topic,
                "topic_desc": "",
                "knowledge_class": "",
                "topic_desc_basic": summary,
                "knowledge_class_basic": "",
                "tags": "",
                "category": category,
            }
        )
        source_rows.append(
            {
                "topic": topic,
                "title": page.get("title", title),
                "pageid": page.get("pageid"),
                "revision_id": revision.get("revid"),
                "revision_timestamp": revision.get("timestamp"),
                "source_url": page.get("url") or wiki_page_url(args.wiki_url, title),
                "game": game,
                "category": category,
                "model": "source-extract" if args.no_llm else args.model,
                "fetched_at": page.get("fetched_at"),
            }
        )
        if args.delay:
            time.sleep(args.delay)

    write_csv(args.output, OUTPUT_FIELDS, output_rows)
    args.sources.parent.mkdir(parents=True, exist_ok=True)
    with args.sources.open("w", encoding="utf-8") as handle:
        for source_row in source_rows:
            handle.write(json.dumps(source_row, ensure_ascii=False) + "\n")

    validation = validate_dataset(args.output)
    if generation_issues:
        validation["generation_issues"] = generation_issues
        validation["valid"] = False
    args.validation.parent.mkdir(parents=True, exist_ok=True)
    args.validation.write_text(json.dumps(validation, indent=2), encoding="utf-8")

    print(f"[build] wrote {len(output_rows)} rows to {args.output}")
    print(f"[build] sources: {args.sources}")
    print(f"[build] validation: {args.validation}")
    if not validation["valid"]:
        print(f"[build] validation found {len(validation.get('errors', [])) + len(generation_issues)} issue groups")
        return 0 if args.allow_invalid else 2
    return 0


def run_validate(args: argparse.Namespace) -> int:
    report = validate_dataset(args.csv_path)
    print(json.dumps(report, indent=2))
    return 0 if report["valid"] else 2


def build_parser() -> argparse.ArgumentParser:
    parser = argparse.ArgumentParser(description=__doc__)
    subparsers = parser.add_subparsers(dest="command", required=True)

    discover = subparsers.add_parser("discover", help="Discover reviewable topic candidates")
    discover.add_argument("--api-url", default=DEFAULT_API_URL)
    discover.add_argument("--wiki-url", default=DEFAULT_WIKI_URL)
    discover.add_argument("--root", action="append", type=parse_root, help="category=game=MediaWiki category title")
    discover.add_argument("--depth", type=int, default=1, help="Subcategory recursion depth")
    discover.add_argument("--max-pages-per-root", type=int, default=0, help="0 means unlimited")
    discover.add_argument("--delay", type=float, default=0.1)
    discover.add_argument("--output", type=Path, default=SCRIPT_DIR / "output" / "discovered_topics.csv")
    discover.set_defaults(handler=run_discover)

    curate = subparsers.add_parser("curate", help="Select a game-balanced release manifest from discovered pages")
    curate.add_argument("--candidates", type=Path, action="append", required=True)
    curate.add_argument("--seed", type=Path, action="append", default=[])
    curate.add_argument("--api-url", default=DEFAULT_API_URL)
    curate.add_argument("--metadata-cache", type=Path, default=SCRIPT_DIR / "cache" / "candidate_metadata.json")
    curate.add_argument("--refresh-metadata", action="store_true")
    curate.add_argument("--quota", action="append", type=parse_quota)
    curate.add_argument("--delay", type=float, default=0.05)
    curate.add_argument("--output", type=Path, default=SCRIPT_DIR / "output" / "curated_topics.csv")
    curate.set_defaults(handler=run_curate)

    build = subparsers.add_parser("build", help="Build approved topics into a Dialectic CSV")
    build.add_argument("--manifest", type=Path, default=SCRIPT_DIR / "fallout_worldknowledge_topics.csv")
    build.add_argument("--api-url", default=DEFAULT_API_URL)
    build.add_argument("--wiki-url", default=DEFAULT_WIKI_URL)
    build.add_argument("--openrouter-url", default=DEFAULT_OPENROUTER_URL)
    build.add_argument("--model", default=DEFAULT_MODEL)
    build.add_argument("--cache-dir", type=Path, default=SCRIPT_DIR / "cache")
    build.add_argument("--generation-cache-dir", type=Path, default=SCRIPT_DIR / "cache" / "generated")
    build.add_argument("--output", type=Path, default=SCRIPT_DIR / "output" / "fallout_worldknowledge_basic.csv")
    build.add_argument("--sources", type=Path, default=SCRIPT_DIR / "output" / "fallout_worldknowledge_sources.jsonl")
    build.add_argument("--validation", type=Path, default=SCRIPT_DIR / "output" / "fallout_worldknowledge_validation.json")
    build.add_argument("--limit", type=int, default=0)
    build.add_argument("--delay", type=float, default=0.2)
    build.add_argument("--include-all", action="store_true")
    build.add_argument("--refresh", action="store_true")
    build.add_argument("--refresh-generation", action="store_true")
    build.add_argument("--no-llm", action="store_true", help="Use wiki introductions as local drafts")
    build.add_argument("--allow-invalid", action="store_true", help="Return success while smoke-testing draft output")
    build.set_defaults(handler=run_build)

    validate = subparsers.add_parser("validate", help="Validate a generated Dialectic CSV")
    validate.add_argument("csv_path", type=Path)
    validate.set_defaults(handler=run_validate)
    return parser


def main() -> int:
    parser = build_parser()
    args = parser.parse_args()
    try:
        return int(args.handler(args))
    except (OSError, RuntimeError, ValueError) as exc:
        print(f"[error] {exc}", file=sys.stderr)
        return 1


if __name__ == "__main__":
    raise SystemExit(main())
