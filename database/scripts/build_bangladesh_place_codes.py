#!/usr/bin/env python3
"""Rebuild database/data/bangladesh-place-codes.json from bangladesh-geo.json."""

from __future__ import annotations

import json
from collections import Counter
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
SRC = ROOT / "data" / "bangladesh-geo.json"
OUT = ROOT / "data" / "bangladesh-place-codes.json"

DIVISION_CODES = {
    "Dhaka": "101",
    "Rangpur": "102",
    "Chattogram": "103",
    "Chattagram": "103",
    "Rajshahi": "104",
    "Khulna": "105",
    "Barishal": "106",
    "Barisal": "106",
    "Sylhet": "107",
    "Mymensingh": "108",
}
DIVISION_CANON = {"Chattagram": "Chattogram", "Barisal": "Barishal"}

CITY_CORPORATIONS = [
    ("Dhaka", ["Dhaka"], ["Dhaka Metro"], "Dhaka Metro", "Dhaka North City Corporation"),
    ("Dhaka", ["Dhaka"], ["Dhaka Metro"], "Dhaka Metro", "Dhaka South City Corporation"),
    ("Chattogram", ["Chattogram", "Chittagong", "Chattagram"], ["Chattogram City"], "Chattogram City", "Chattogram City Corporation"),
    ("Khulna", ["Khulna"], ["Khulna Sadar", "Kotwali", "Sonadanga"], "Khulna City", "Khulna City Corporation"),
    ("Rajshahi", ["Rajshahi"], ["Boalia", "Rajshahi Sadar", "Rajpara"], "Rajshahi City", "Rajshahi City Corporation"),
    ("Sylhet", ["Sylhet"], ["Sylhet Sadar", "Kotwali"], None, "Sylhet City Corporation"),
    ("Barishal", ["Barishal", "Barisal"], ["Barishal Sadar", "Barisal Sadar", "Kotwali"], None, "Barishal City Corporation"),
    ("Rangpur", ["Rangpur"], ["Rangpur Sadar", "Kotwali"], None, "Rangpur City Corporation"),
    ("Mymensingh", ["Mymensingh"], ["Mymensingh Sadar", "Kotwali"], None, "Mymensingh City Corporation"),
    ("Dhaka", ["Gazipur"], ["Gazipur Sadar", "Tongi"], None, "Gazipur City Corporation"),
    ("Dhaka", ["Narayanganj"], ["Narayanganj Sadar", "Fatullah"], None, "Narayanganj City Corporation"),
    ("Chattogram", ["Cumilla", "Comilla"], ["Cumilla Sadar", "Comilla Sadar", "Kotwali"], None, "Cumilla City Corporation"),
]


def main() -> None:
    src = json.loads(SRC.read_text())
    records: list[dict] = [
        {
            "code": "100",
            "level": "management",
            "name": "Management",
            "parent_code": None,
            "sort_order": 0,
            "bn_name": None,
            "kind": "management",
        }
    ]

    div_items = []
    for division in src:
        name = DIVISION_CANON.get(division["name"], division["name"])
        code = DIVISION_CODES[division["name"]]
        div_items.append((code, name, division))
    div_items.sort(key=lambda item: item[0])

    district_code_n = 201
    upazila_code_n = 1001
    union_code_n = 100001
    poura_code_n = 500001
    city_code_n = 600001
    synthetic_upa_n = 9001

    district_by_key: dict[tuple[str, str], str] = {}
    upazilas_by_district: dict[str, list[dict[str, str]]] = {}

    for sort_i, (division_code, division_name, division) in enumerate(div_items, start=1):
        records.append(
            {
                "code": division_code,
                "level": "division",
                "name": division_name,
                "parent_code": "100",
                "sort_order": sort_i,
                "bn_name": division.get("bn_name"),
                "kind": "division",
            }
        )
        districts = sorted(division.get("districts") or [], key=lambda item: item["name"].lower())
        for district_index, district in enumerate(districts, start=1):
            district_code = str(district_code_n)
            district_code_n += 1
            district_name = "Cumilla" if district["name"] == "Comilla" else district["name"]
            records.append(
                {
                    "code": district_code,
                    "level": "district",
                    "name": district_name,
                    "parent_code": division_code,
                    "sort_order": district_index,
                    "bn_name": district.get("bn_name"),
                    "kind": "district",
                }
            )
            district_by_key[(division_name, district_name)] = district_code
            district_by_key[(division_name, district["name"])] = district_code
            upazilas_by_district[district_code] = []
            upazilas = sorted(district.get("upazilas") or [], key=lambda item: item["name"].lower())
            for upazila_index, upazila in enumerate(upazilas, start=1):
                upazila_code = str(upazila_code_n)
                upazila_code_n += 1
                records.append(
                    {
                        "code": upazila_code,
                        "level": "upazila",
                        "name": upazila["name"],
                        "parent_code": district_code,
                        "sort_order": upazila_index,
                        "bn_name": upazila.get("bn_name"),
                        "kind": "upazila",
                    }
                )
                upazilas_by_district[district_code].append({"code": upazila_code, "name": upazila["name"]})
                unions = sorted(upazila.get("unions") or [], key=lambda item: item["name"].lower())
                for union_index, union in enumerate(unions, start=1):
                    records.append(
                        {
                            "code": str(union_code_n),
                            "level": "union",
                            "name": union["name"],
                            "parent_code": upazila_code,
                            "sort_order": union_index,
                            "bn_name": union.get("bn_name"),
                            "kind": "union",
                        }
                    )
                    union_code_n += 1
                pourashavas = sorted(upazila.get("pourashavas") or [], key=lambda item: item["name"].lower())
                base_sort = len(unions)
                for poura_index, pourashava in enumerate(pourashavas, start=1):
                    name = pourashava["name"]
                    lowered = name.lower()
                    if "pourashava" not in lowered and "municipality" not in lowered and "city corporation" not in lowered:
                        name = f"{name} Pourashava"
                    records.append(
                        {
                            "code": str(poura_code_n),
                            "level": "union",
                            "name": name,
                            "parent_code": upazila_code,
                            "sort_order": base_sort + poura_index,
                            "bn_name": pourashava.get("bn_name"),
                            "kind": "pourashava",
                            "category": pourashava.get("category"),
                        }
                    )
                    poura_code_n += 1

    synthetic_created: dict[tuple[str, str], str] = {}
    added_corps: set[str] = set()
    for division_name, district_aliases, prefs, synthetic_name, corp_name in CITY_CORPORATIONS:
        if corp_name in added_corps:
            continue
        district_code = None
        for alias in district_aliases:
            alias = "Cumilla" if alias == "Comilla" else alias
            district_code = district_by_key.get((division_name, alias))
            if district_code:
                break
        if not district_code:
            raise SystemExit(f"Missing district for {corp_name}: {division_name} {district_aliases}")
        upazilas = upazilas_by_district[district_code]
        target = None
        for pref in prefs:
            for upazila in upazilas:
                if pref.lower() == upazila["name"].lower() or pref.lower() in upazila["name"].lower():
                    target = upazila
                    break
            if target:
                break
        if not target and synthetic_name:
            key = (district_code, synthetic_name)
            if key not in synthetic_created:
                code = str(synthetic_upa_n)
                synthetic_upa_n += 1
                records.append(
                    {
                        "code": code,
                        "level": "upazila",
                        "name": synthetic_name,
                        "parent_code": district_code,
                        "sort_order": 9800,
                        "bn_name": None,
                        "kind": "metro_upazila",
                    }
                )
                synthetic_created[key] = code
                upazilas_by_district[district_code].append({"code": code, "name": synthetic_name})
            target = {"code": synthetic_created[key], "name": synthetic_name}
        if not target:
            target = next((item for item in upazilas if "sadar" in item["name"].lower()), upazilas[0])
        records.append(
            {
                "code": str(city_code_n),
                "level": "union",
                "name": corp_name,
                "parent_code": target["code"],
                "sort_order": 9900,
                "bn_name": None,
                "kind": "city_corporation",
            }
        )
        city_code_n += 1
        added_corps.add(corp_name)

    rangpur_div = next(item for item in records if item["level"] == "division" and item["name"] == "Rangpur")
    rangpur_dist = next(
        item
        for item in records
        if item["level"] == "district" and item["name"] == "Rangpur" and item["parent_code"] == rangpur_div["code"]
    )
    sadar = next(
        item
        for item in records
        if item["level"] == "upazila"
        and item["parent_code"] == rangpur_dist["code"]
        and item["name"].lower() == "rangpur sadar"
    )
    corp = next(item for item in records if item.get("kind") == "city_corporation" and item["name"] == "Rangpur City Corporation")

    if len(records) != len({item["code"] for item in records}):
        raise SystemExit("Duplicate place codes generated.")

    payload = {
        "source": "https://iqbalhasandev.github.io/bangladesh-geo-json/bangladesh-geo.json",
        "notes": "Unions, pourashavas, and city corporations share level=union. Synthetic metro upazilas cover city corporations when ward lists are absent.",
        "counts": dict(Counter(item["level"] for item in records)),
        "kinds": dict(Counter(item.get("kind") for item in records)),
        "base_links": [
            {"code": rangpur_div["code"], "base_code": rangpur_dist["code"]},
            {"code": rangpur_dist["code"], "base_code": sadar["code"]},
            {"code": sadar["code"], "base_code": corp["code"]},
        ],
        "places": records,
    }
    OUT.write_text(json.dumps(payload, ensure_ascii=False, separators=(",", ":")))
    print(json.dumps({"counts": payload["counts"], "kinds": payload["kinds"], "total": len(records)}, indent=2))


if __name__ == "__main__":
    main()
