#!/usr/bin/env python3
"""Classify the official TTW xEdit CREA export and build production profile inputs."""

from __future__ import annotations

import argparse
import csv
import hashlib
import json
import re
from collections import Counter
from pathlib import Path


ROOT = Path(__file__).resolve().parents[1]

ROBOT_PATTERN = re.compile(
    r"robot|robobrain|protectron|sentry|securitron|mister|eyebot|turret|drone|robo.?scorpion|doctor (?:8|borous|dala|klein|mobius|o)\b",
    re.I,
)
TALKING_PATTERN = re.compile(
    r"super mutant|nightkin|swampfolk|brawler|bruiser|creeper|scrapper|tracker|vault dweller|vault security|alien|fawkes|lily|marcus|tabitha|davison|keene|mean sonofabitch|uncle leo|mr\. house|mother curie|button gwinnett",
    re.I,
)

# Ordered, explicit rules are the reviewed profile-granularity decision. EditorID
# rules precede display-name rules when the game reuses a generic display name.
PROFILE_RULES: tuple[tuple[str, str, str], ...] = (
    ("editor", r"deathclaw.*mother|mother.*deathclaw", "deathclaw_mother"),
    ("editor", r"ghost.*harvester", "ghost_harvester"),
    ("editor", r"ghost.*seeker", "ghost_seeker"),
    ("editor", r"ghost.*trapper", "ghost_trapper"),
    ("name", r"^Abomination$", "abomination"),
    ("name", r"^Albino Radscorpion$", "albino_radscorpion"),
    ("name", r"^Bark Scorpion Hunter$", "bark_scorpion_hunter"),
    ("name", r"^Bark Scorpion$", "bark_scorpion"),
    ("name", r"^Bighorner Bull$", "bighorner_bull"),
    ("name", r"^Bighorner Calf$", "bighorner_calf"),
    ("name", r"^Bighorner$", "bighorner"),
    ("name", r"^Bloatfly$", "bloatfly"),
    ("name", r"^(?:Pack )?Brahmin$", "brahmin"),
    ("name", r"^Giant Cazador$", "giant_cazador"),
    ("name", r"^Young Cazador$", "young_cazador"),
    ("name", r"^Cazador$", "cazador"),
    ("name", r"^Evolved Centaur$", "evolved_centaur"),
    ("name", r"^Centaur$", "centaur"),
    ("name", r"^Coyote Den Mother$", "coyote_den_mother"),
    ("name", r"^Coyote Pup$", "coyote_pup"),
    ("name", r"^Coyote$", "coyote"),
    ("name", r"^Deathclaw Alpha(?: Male)?$", "deathclaw_alpha"),
    ("name", r"^(?:Deathclaw Mother|Mother Deathclaw)$", "deathclaw_mother"),
    ("name", r"^Deathclaw Baby$", "deathclaw_baby"),
    ("name", r"^Blind Deathclaw$", "blind_deathclaw"),
    ("name", r"^Irradiated Deathclaw$", "irradiated_deathclaw"),
    ("name", r"^Legendary Deathclaw$", "legendary_deathclaw"),
    ("name", r"^Rawr$", "rawr"),
    ("name", r"^Young Deathclaw$", "young_deathclaw"),
    ("name", r"^Deathclaw$", "deathclaw"),
    ("name", r"^Gabe$", "gabe"),
    ("name", r"^(?:Large )?Dog$", "dog"),
    ("name", r"^(?:Large )?Wild Dog$", "wild_dog"),
    ("name", r"^Vicious Dog$", "vicious_dog"),
    ("name", r"^(?:Large )?White Leg Mongrel$", "white_leg_mongrel"),
    ("name", r"^Feral Ghoul Reaver$", "feral_ghoul_reaver"),
    ("name", r"^Feral Ghoul Roamer$", "feral_ghoul_roamer"),
    ("name", r"^(?:Glowing One|Glowing Trooper Ghoul)$", "glowing_one"),
    ("name", r"^(?:Feral Trooper Ghoul|Feral Ghoul)$", "feral_ghoul"),
    ("name", r"^Swamp Ghoul$", "swamp_ghoul"),
    ("name", r"^Fire Ant Warrior$", "fire_ant_warrior"),
    ("name", r"^(?:Fire Ant Queen|Marigold Ant Queen)$", "fire_ant_queen"),
    ("name", r"^(?:Giant Ant Queen|Ant Queen)$", "giant_ant_queen"),
    ("name", r"^Fire Ant Nest Guardian$", "fire_ant_nest_guardian"),
    ("name", r"^(?:Battle Ant|Invader Ant|Mutated Forager Ant|Fire Ant)$", "specialized_ant"),
    ("name", r"^Fire Ant Soldier$", "fire_ant_soldier"),
    ("name", r"^Fire Ant Worker$", "fire_ant_worker"),
    ("name", r"^Giant Soldier Ant$", "giant_soldier_ant"),
    ("name", r"^Giant Worker Ant$", "giant_worker_ant"),
    ("name", r"^Young Fire Gecko$", "young_fire_gecko"),
    ("name", r"^Fire Gecko$", "fire_gecko"),
    ("name", r"^Legendary Fire Gecko$", "legendary_fire_gecko"),
    ("name", r"^Young Golden Gecko$", "young_golden_gecko"),
    ("name", r"^Golden Gecko$", "golden_gecko"),
    ("name", r"^(?:Golden Gecko Hunter|Young Golden Gecko Hunter)$", "golden_gecko"),
    ("name", r"^Young Green Gecko$", "young_green_gecko"),
    ("name", r"^Giant Green Gecko$", "giant_green_gecko"),
    ("name", r"^Green Gecko$", "green_gecko"),
    ("name", r"^Young Gecko$", "young_gecko"),
    ("name", r"^Gecko$", "gecko"),
    ("name", r"^(?:Gecko Hunter|Young Gecko Hunter)$", "gecko"),
    ("name", r"^Ghost$", "ghost_person"),
    ("name", r"^Giant Zion Mantis$", "giant_zion_mantis"),
    ("name", r"^Zion Mantis Nymph$", "zion_mantis_nymph"),
    ("name", r"^Zion Mantis$", "zion_mantis"),
    ("name", r"^Giant Mantis Nymph$", "giant_mantis_nymph"),
    ("name", r"^Giant Mantis$", "giant_mantis"),
    ("name", r"^Giant Rat Pup$", "giant_rat_pup"),
    ("name", r"^(?:Giant Rat|Sewer Rat)$", "giant_rat"),
    ("name", r"^Mole Rat Pup$", "mole_rat_pup"),
    ("name", r"^Mole Rat$", "mole_rat"),
    ("name", r"^Nukalurk Hunter$", "nukalurk_hunter"),
    ("name", r"^Nukalurk$", "nukalurk"),
    ("name", r"^Swamplurk Queen$", "swamplurk_queen"),
    ("name", r"^Swamplurk Hunter$", "swamplurk_hunter"),
    ("name", r"^Swamplurk$", "swamplurk"),
    ("name", r"^Mirelurk King$", "mirelurk_king"),
    ("name", r"^(?:Lakelurk King|Alpha Male Lakelurk)$", "mirelurk_king"),
    ("name", r"^Mirelurk Hunter$", "mirelurk_hunter"),
    ("name", r"^(?:Mirelurk|Lakelurk)$", "mirelurk"),
    ("name", r"^Young Nightstalker$", "young_nightstalker"),
    ("name", r"^Nightstalker$", "nightstalker"),
    ("name", r"^Legendary Nightstalker$", "legendary_nightstalker"),
    ("name", r"^Radroach$", "radroach"),
    ("name", r"^Irradiated Radroach$", "irradiated_radroach"),
    ("name", r"^Small Radscorpion$", "small_radscorpion"),
    ("name", r"^Giant Radscorpion$", "giant_radscorpion"),
    ("name", r"^Radscorpion$", "radscorpion"),
    ("name", r"^Radscorpion Queen$", "radscorpion_queen"),
    ("name", r"^Giant Spore Plant$", "giant_spore_plant"),
    ("name", r"^Spore Plant$", "spore_plant"),
    ("name", r"^Spore Carrier Alpha$", "spore_carrier_alpha"),
    ("name", r"^Spore Carrier Beast$", "spore_carrier_beast"),
    ("name", r"^Spore Carrier Brute$", "spore_carrier_brute"),
    ("name", r"^Spore Carrier Runt$", "spore_carrier_runt"),
    ("name", r"^Spore Carrier Savage$", "spore_carrier_savage"),
    ("name", r"^Spore Carrier Scavenger$", "spore_carrier_scavenger"),
    ("name", r"^Spore Carrier$", "spore_carrier"),
    ("name", r"^Trog Fledgling$", "trog_fledgling"),
    ("name", r"^Trog Brute$", "trog_brute"),
    ("name", r"^Trog Savage$", "trog_savage"),
    ("name", r"^Trog$", "trog"),
    ("name", r"^Hulking Tunneler$", "hulking_tunneler"),
    ("name", r"^Venomous Tunneler$", "venomous_tunneler"),
    ("name", r"^Tunneler$", "tunneler"),
    ("name", r"^Tunneler Queen$", "tunneler_queen"),
    ("name", r"^Giant Yao Guai$", "giant_yao_guai"),
    ("name", r"^Yao Guai Cub$", "yao_guai_cub"),
    ("name", r"^Yao Guai$", "yao_guai"),
    ("name", r"^Scavenger's Yao Guai$", "yao_guai"),
    ("name", r"^(?:Rodent of Unusual Size|Unnaturally Large Sized Rodent)$", "giant_rat"),
    ("name", r"^Legendary Bloatfly$", "legendary_bloatfly"),
    ("name", r"^Legendary Cazador$", "legendary_cazador"),
    ("name", r"^Chimera Tank$", "chimera_tank"),
    ("name", r"^Dionaea Muscipula$", "dionaea_muscipula"),
)

DISPLAY_OVERRIDES = {
    "gabe": "Gabe",
    "ghost_person": "Ghost Person",
}

VOICE_PALETTE = {
    "small": {"voiceid": "maleadult10", "reason": "A light, quick vanilla voice suits young and small creatures."},
    "social": {"voiceid": "maleadult01default", "reason": "A grounded vanilla voice suits herd and pack animals."},
    "predator": {"voiceid": "maleadult02", "reason": "A terse, forceful vanilla voice suits territorial predators."},
    "massive": {"voiceid": "maleold02", "reason": "A slow, weighty vanilla voice suits large and deliberate creatures."},
    "sharp": {"voiceid": "femaleadult03", "reason": "A precise, alert vanilla voice suits fast hunters and insects."},
    "hungry": {"voiceid": "femaleadult04", "reason": "A practical, appetite-forward vanilla voice suits scavengers."},
    "matriarch": {"voiceid": "femaleold03", "reason": "A mature vanilla voice suits mothers, queens, and den leaders."},
    "strange": {"voiceid": "maleadult07", "reason": "A curious vanilla voice suits altered and unusually perceptive creatures."},
}


def profile_for(row: dict[str, str]) -> str | None:
    for field, pattern, profile in PROFILE_RULES:
        value = row["editor_id"] if field == "editor" else row["display_name"]
        if re.search(pattern, value, re.I):
            return profile
    return None


def palette_for(profile: str) -> str:
    if any(word in profile for word in ("mother", "queen", "den_mother")):
        return "matriarch"
    if any(word in profile for word in ("young", "pup", "calf", "cub", "nymph", "runt", "fledgling", "small")):
        return "small"
    if any(word in profile for word in ("brahmin", "bighorner", "dog", "coyote")):
        return "social"
    if any(word in profile for word in ("giant", "alpha", "hulking", "brute", "beast", "yao_guai", "deathclaw")):
        return "massive"
    if any(word in profile for word in ("ant", "cazador", "mantis", "scorpion", "nightstalker", "tunneler")):
        return "sharp"
    if any(word in profile for word in ("ghoul", "trog", "mole_rat", "giant_rat", "radroach", "bloatfly")):
        return "hungry"
    if any(word in profile for word in ("spore", "ghost", "centaur", "abomination", "gabe")):
        return "strange"
    return "predator"


def display_name(profile: str) -> str:
    return DISPLAY_OVERRIDES.get(profile, profile.replace("_", " ").title())


def main() -> int:
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--xedit", type=Path, required=True)
    parser.add_argument("--catalog", type=Path, default=ROOT / "data" / "fallout_creature_catalog.csv")
    parser.add_argument("--profiles", type=Path, default=ROOT / "tmp" / "fallout_creature_profiles_input.json")
    args = parser.parse_args()

    raw = args.xedit.read_bytes()
    with args.xedit.open("r", encoding="utf-8-sig", newline="") as handle:
        all_rows = list(csv.DictReader(handle))
    candidates = [row for row in all_rows if int(row["total_references"]) > 0]
    existing_sql = (ROOT / "data" / "fallout_bio_templates.sql").read_text(encoding="utf-8")
    existing_templates = {
        value.replace("''", "'").casefold()
        for value in re.findall(r"^\('((?:''|[^'])*)',", existing_sql, re.M)
    }

    output: list[dict[str, str]] = []
    included_profiles: dict[str, dict[str, object]] = {}
    for row in candidates:
        combined = f"{row['editor_id']} {row['display_name']} {row['voice_type']}"
        codename = re.sub(r"[^\w+-]", "", row["display_name"].strip().lower().replace(" ", "_").replace("'", "+"))
        if ROBOT_PATTERN.search(combined):
            classification, reason, profile = "exclude_not_actor", "Robot or automated turret reserved for the robot pass.", None
        elif TALKING_PATTERN.search(combined):
            classification, reason, profile = "exclude_talking", "Conversational humanoid or intelligible speaking creature.", None
        elif codename in existing_templates or row["display_name"].strip().casefold() in existing_templates:
            classification, reason, profile = "exclude_unused", "A maintained named biography already exists; do not duplicate it.", None
        elif not row["display_name"].strip() or row["display_name"].strip().lower() == "raven":
            classification, reason, profile = "exclude_not_actor", "Ambient/scenery actor or record without a usable identity.", None
        else:
            profile = profile_for(row)
            if profile:
                classification, reason = "include_nonverbal", "Reachable official non-verbal creature mapped to a reviewed archetype."
            else:
                classification, reason = "exclude_unused", "Template, corpse, test, cut, or non-reachable archetype record without a distinct new roleplay profile."

        output.append({
            **row,
            "classification": classification,
            "classification_reason": reason,
            "profile_id": profile or "",
        })
        if profile:
            palette = palette_for(profile)
            included_profiles.setdefault(profile, {
                "template_name": f"creature_{profile}",
                "display_name": display_name(profile),
                "species_or_variant": display_name(profile),
                "voiceid": VOICE_PALETTE[palette]["voiceid"],
                "voice_reason": VOICE_PALETTE[palette]["reason"],
                "sample_question": f"What matters most to a {display_name(profile).lower()} right now?",
                "stable_identities": [],
            })["stable_identities"].append(row["stable_identity"])

    raw_counts = Counter(row["classification"] for row in output)
    counts = {
        category: raw_counts.get(category, 0)
        for category in ("include_nonverbal", "exclude_talking", "exclude_unused", "exclude_not_actor", "review")
    }
    if counts["review"]:
        unmatched = sorted({row["display_name"] for row in output if row["classification"] == "review"})
        raise RuntimeError(f"{counts['review']} records remain in review: {', '.join(unmatched)}")
    if len(output) != sum(counts.values()):
        raise RuntimeError("Inventory accounting mismatch")
    identities = [row["stable_identity"].lower() for row in output]
    if len(identities) != len(set(identities)):
        raise RuntimeError("Duplicate stable identities in reviewed catalog")

    args.catalog.parent.mkdir(parents=True, exist_ok=True)
    with args.catalog.open("w", encoding="utf-8", newline="") as handle:
        writer = csv.DictWriter(handle, fieldnames=list(output[0]))
        writer.writeheader()
        writer.writerows(output)
    profile_payload = {
        "catalog": "official-ttw-nonverbal-creatures-v1",
        "xedit_source_sha256": hashlib.sha256(raw).hexdigest(),
        "inventory_counts": counts,
        "candidate_count": len(output),
        "profile_count": len(included_profiles),
        "profiles": list(included_profiles.values()),
    }
    args.profiles.parent.mkdir(parents=True, exist_ok=True)
    args.profiles.write_text(json.dumps(profile_payload, indent=2) + "\n", encoding="utf-8", newline="\n")
    print(json.dumps({"candidates": len(output), "profiles": len(included_profiles), "counts": counts}, default=dict))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
