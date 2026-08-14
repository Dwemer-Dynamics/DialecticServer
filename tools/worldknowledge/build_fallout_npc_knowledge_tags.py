#!/usr/bin/env python3
"""Build controlled World Knowledge tags from the shipped Fallout NPC templates."""

from __future__ import annotations

import csv
import re
from pathlib import Path

ROOT = Path(__file__).resolve().parents[2]
SOURCE = ROOT / "data" / "fallout_bio_templates.sql"
OUTPUT = ROOT / "data" / "fallout_worldknowledge_npc_tags.csv"

COMMUNITIES = {
    "vault 101": ("vault_101", "capital_wasteland"),
    "megaton": ("megaton", "capital_wasteland"),
    "rivet city": ("rivet_city", "capital_wasteland"),
    "underworld": ("underworld", "capital_wasteland"),
    "canterbury commons": ("canterbury_commons", "capital_wasteland"),
    "little lamplight": ("little_lamplight", "capital_wasteland"),
    "big town": ("big_town", "capital_wasteland"),
    "tenpenny tower": ("tenpenny_tower", "capital_wasteland"),
    "paradise falls": ("paradise_falls", "capital_wasteland"),
    "point lookout": ("point_lookout", "capital_wasteland"),
    "the pitt": ("the_pitt", "capital_wasteland"),
    "mothership zeta": ("mothership_zeta", "capital_wasteland"),
    "goodsprings": ("goodsprings", "mojave"),
    "primm": ("primm", "mojave"),
    "novac": ("novac", "mojave"),
    "freeside": ("freeside", "mojave"),
    "westside": ("westside", "mojave"),
    "new vegas": ("new_vegas", "mojave"),
    "the strip": ("new_vegas_strip", "mojave"),
    "nelly air force base": ("nellis_air_force_base", "mojave"),
    "nellis air force base": ("nellis_air_force_base", "mojave"),
    "jacobstown": ("jacobstown", "mojave"),
    "red rock canyon": ("red_rock_canyon", "mojave"),
    "camp mccarran": ("camp_mccarran", "mojave"),
    "hoover dam": ("hoover_dam", "mojave"),
    "hidden valley": ("hidden_valley", "mojave"),
    "zion canyon": ("zion", "mojave"),
    "big mt": ("big_mt", "mojave"),
    "sierra madre": ("sierra_madre", "mojave"),
}

FACTIONS = {
    "brotherhood of steel": "brotherhood_of_steel",
    "caesar's legion": "caesars_legion",
    "caesars legion": "caesars_legion",
    "followers of the apocalypse": "followers_of_the_apocalypse",
    "great khan": "great_khans",
    "new california republic": "ncr",
    " ncr ": "ncr",
    "powder ganger": "powder_gangers",
    "the enclave": "enclave",
    "boomers": "boomers",
    "the kings": "the_kings",
    "tunnel snakes": "tunnel_snakes",
    "white glove society": "white_glove_society",
    "the chairmen": "chairmen",
    "the fiends": "fiends",
    "talon company": "talon_company",
    "reilly's rangers": "reillys_rangers",
    "brotherhood outcast": "brotherhood_outcasts",
}

ROLE_RULES = {
    "caravan": "traveler", "courier": "traveler", "doctor": "doctor",
    "engineer": "engineer", "gunsmith": "gunsmith", "historian": "historian",
    "hunter": "hunter", "merchant": "merchant", "medic": "medic",
    "leader": "leader", "mayor": "leader", "overseer": "leader",
    "raider": "raider", "researcher": "researcher", "scientist": "scientist",
    "soldier": "soldier", "guard": "soldier", "military": "soldier",
    "survival": "survivalist", "tribal": "tribal",
    "vault dweller": "vault_dweller",
}

DOMAIN_RULES = {
    "agricultur": "agriculture", "archaeolog": "archaeology", "biolog": "biology",
    "chemistry": "chemistry", "energy weapon": "energy_weapons", "engineer": "engineering",
    "explosive": "explosives", "firearm": "firearms", "gun": "firearms",
    "histor": "history", "medic": "medicine", "military": "military",
    "politic": "politics", "robot": "robotics", "survival": "survival",
    "technolog": "technology", "trade": "trade", "vault-tec": "vault_tec",
    "wildlife": "wildlife", "alien": "xenotechnology",
}

RACE_RULES = {
    "human": [
        r"\bhuman\b", r"\bcaucasian\b", r"\bafrican-american\b",
        r"\basian\b", r"\bhispanic\b",
    ],
    "nightkin": [r"\bnightkin\b"],
    "super_mutant": [r"\bsuper mutant\b"],
    "ghoul": [r"\b(?:feral )?ghoul\b"],
    "robot": [
        r"\brobot(?:ic)?\b", r"\bsecuritron\b", r"\bprotectron\b",
        r"\b(?:mister|mr\.) handy\b", r"\beyebot\b", r"\brobobrain\b",
    ],
    "dog": [r"\b(?:a |the )?dog\b", r"\bcanine\b"],
}


def normalize(value: str) -> str:
    value = re.sub(r"[^a-z0-9]+", "_", value.lower()).strip("_")
    return value


def parse_sql_strings(tuple_text: str) -> list[str | None]:
    values: list[str | None] = []
    index = 0
    while index < len(tuple_text):
        while index < len(tuple_text) and tuple_text[index] in " \r\n\t,":
            index += 1
        if index >= len(tuple_text):
            break
        if tuple_text[index] == "'":
            index += 1
            chars = []
            while index < len(tuple_text):
                if tuple_text[index] == "'" and index + 1 < len(tuple_text) and tuple_text[index + 1] == "'":
                    chars.append("'")
                    index += 2
                elif tuple_text[index] == "'":
                    index += 1
                    break
                else:
                    chars.append(tuple_text[index])
                    index += 1
            values.append("".join(chars))
        else:
            end = tuple_text.find(",", index)
            if end < 0:
                end = len(tuple_text)
            raw = tuple_text[index:end].strip()
            values.append(None if raw.upper() == "NULL" else raw)
            index = end
    return values


def extract_rows(sql: str) -> list[list[str | None]]:
    rows = []
    depth = 0
    in_string = False
    start = None
    index = 0
    while index < len(sql):
        char = sql[index]
        if char == "'":
            if in_string and index + 1 < len(sql) and sql[index + 1] == "'":
                index += 2
                continue
            in_string = not in_string
        elif not in_string and char == "(":
            depth += 1
            if depth == 1:
                start = index + 1
        elif not in_string and char == ")":
            if depth == 1 and start is not None:
                values = parse_sql_strings(sql[start:index])
                if len(values) == 15 and values[0] not in {None, "npc_name", '"npc_name"'}:
                    rows.append(values)
                start = None
            depth = max(0, depth - 1)
        index += 1
    return rows


def build_tags(row: list[str | None]) -> list[str]:
    npc_name = str(row[0] or "")
    identity = normalize(npc_name.split("__", 1)[0])
    identity = {"powder_ganger": "powder_gangers"}.get(identity, identity)
    tags = {identity}
    core, bio, appearance, occupation, skills, race = (
        str(row[2] or ""), str(row[3] or ""), str(row[4] or ""),
        str(row[7] or ""), str(row[8] or ""), str(row[13] or ""),
    )
    profile_text = f" {core} {bio} {occupation} {skills} ".lower()
    communities = []
    for phrase, (community, region) in COMMUNITIES.items():
        if phrase in profile_text:
            communities.append((profile_text.find(phrase), community, region))
    if communities:
        _, community, region = min(communities)
        tags.add(community)
        tags.add(region)
    elif any(term in profile_text for term in ["mojave", "new california", "las vegas"]):
        tags.add("mojave")
    elif any(term in profile_text for term in ["capital wasteland", "washington, d.c.", "washington dc"]):
        tags.add("capital_wasteland")
    # Occupation is the strongest affiliation signal. The short core prefix
    # captures forms such as "NCR ranger" without treating enemies mentioned
    # later in a biography as the NPC's own faction.
    faction_text = f" {core[:100]} {occupation} ".lower()
    matched_factions = set()
    for phrase, faction in FACTIONS.items():
        if phrase in faction_text:
            tags.add(faction)
            matched_factions.add(faction)
    if not tags.intersection({"capital_wasteland", "mojave"}) and matched_factions.intersection({
        "ncr", "caesars_legion", "followers_of_the_apocalypse", "great_khans",
        "powder_gangers", "boomers", "the_kings", "white_glove_society",
        "chairmen", "fiends",
    }):
        tags.add("mojave")
    role_text = f" {occupation} {skills} ".lower()
    for phrase, role in ROLE_RULES.items():
        if phrase in role_text:
            tags.add(role)
    for phrase, domain in DOMAIN_RULES.items():
        if phrase in role_text:
            tags.add(domain)
    # Appearance is explicit enough to distinguish a human who studies or
    # resembles a creature from an NPC who actually is one. Fall back to the
    # short role summary only when appearance carries no controlled race.
    appearance_race_text = f" {race} {appearance} ".lower()
    core_race_text = f" {race} {core[:120]} ".lower()
    for controlled_race, patterns in RACE_RULES.items():
        if any(re.search(pattern, appearance_race_text) for pattern in patterns):
            tags.add(controlled_race)
            break
    else:
        for controlled_race, patterns in RACE_RULES.items():
            if any(re.search(pattern, core_race_text) for pattern in patterns):
                tags.add(controlled_race)
                break
    return sorted(tags)


def main() -> int:
    rows = extract_rows(SOURCE.read_text(encoding="utf-8"))
    if len(rows) < 1200:
        raise RuntimeError(f"Expected at least 1200 Fallout templates, parsed {len(rows)}")
    with OUTPUT.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.writer(handle, lineterminator="\n")
        writer.writerow(["npc_name", "worldknowledge_tags"])
        for row in rows:
            writer.writerow([row[0], ",".join(build_tags(row))])
    print(f"templates={len(rows)} output={OUTPUT}")
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
