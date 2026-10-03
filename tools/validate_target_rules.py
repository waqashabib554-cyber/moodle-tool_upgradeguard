#!/usr/bin/env python3
"""Validate the public Upgrade Guard target-rules feed."""

import datetime
import argparse
import json
import re
import sys
from pathlib import Path


STATUSES = {"stable", "security", "future", "unsupported"}
SOURCES = {"verified", "derived"}


def require(condition, message):
    if not condition:
        raise ValueError(message)


def validate(path, previous_path=None):
    with Path(path).open(encoding="utf-8") as source:
        dataset = json.load(source)

    require(isinstance(dataset, dict), "Dataset root must be a JSON object.")
    require(type(dataset.get("datasetversion")) is int and dataset["datasetversion"] > 0,
            "datasetversion must be a positive integer.")
    updated = dataset.get("updated")
    require(isinstance(updated, str), "updated must be a YYYY-MM-DD date.")
    parsed_date = datetime.date.fromisoformat(updated)
    require(parsed_date.isoformat() == updated, "updated must be a YYYY-MM-DD date.")

    targets = dataset.get("targets")
    require(isinstance(targets, list) and targets, "targets must be a non-empty list.")
    require(len(targets) <= 64, "The dataset cannot contain more than 64 targets.")
    versions = set()
    branches = set()

    for index, target in enumerate(targets):
        prefix = f"targets[{index}]"
        require(isinstance(target, dict), f"{prefix} must be an object.")
        for key in (
            "version", "branch", "requiresint", "requiresintsource", "phpmin",
            "minsource", "publiclayout", "status", "statussource", "environment",
        ):
            require(key in target, f"{prefix}.{key} is required.")

        version = target["version"]
        require(isinstance(version, str) and re.fullmatch(r"\d+\.\d+", version),
                f"{prefix}.version must be major.minor.")
        major, minor = map(int, version.split("."))
        branch = target["branch"]
        require(type(branch) is int and branch == major * 100 + minor,
                f"{prefix}.branch does not match {version}.")
        require(type(target["requiresint"]) is int and target["requiresint"] > 0,
                f"{prefix}.requiresint must be a positive integer.")
        require(isinstance(target["requiresintsource"], str)
                and target["requiresintsource"] in SOURCES,
                f"{prefix}.requiresintsource is invalid.")
        require(isinstance(target["phpmin"], str) and re.fullmatch(r"\d+\.\d+\.\d+", target["phpmin"]),
                f"{prefix}.phpmin must be a semantic PHP version.")
        require(isinstance(target["minsource"], str)
                and re.fullmatch(r"\d+\.\d+(?:\.\d+)?", target["minsource"]),
                f"{prefix}.minsource must be a Moodle version.")
        require(type(target["publiclayout"]) is bool, f"{prefix}.publiclayout must be boolean.")
        require(isinstance(target["status"], str) and target["status"] in STATUSES,
                f"{prefix}.status is invalid.")
        require(isinstance(target["statussource"], str) and target["statussource"].strip(),
                f"{prefix}.statussource must cite its verification source.")
        require(version not in versions, f"Duplicate target version: {version}.")
        require(branch not in branches, f"Duplicate Moodle branch: {branch}.")
        versions.add(version)
        branches.add(branch)

        environment = target["environment"]
        require(isinstance(environment, dict), f"{prefix}.environment must be an object.")
        for section in ("php", "database", "extensions"):
            value = environment.get(section)
            require(isinstance(value, dict), f"{prefix}.environment.{section} is required.")
            require(isinstance(value.get("source"), str), f"{prefix}.environment.{section}.source is required.")
            require(type(value.get("verified")) is bool, f"{prefix}.environment.{section}.verified must be boolean.")

        database = environment["database"]
        require(isinstance(database.get("minimums"), dict), f"{prefix}.database.minimums must be an object.")
        require(isinstance(database.get("exercised"), list)
                and all(isinstance(item, str) and item for item in database["exercised"]),
                f"{prefix}.database.exercised must be a list of strings.")
        require(all(isinstance(vendor, str) and isinstance(value, str) and value
                    for vendor, value in database["minimums"].items()),
                f"{prefix}.database.minimums must map vendors to versions.")

        extensions = environment["extensions"]
        for field in ("required", "optional"):
            require(isinstance(extensions.get(field), list)
                    and all(isinstance(item, str) and item for item in extensions[field]),
                    f"{prefix}.extensions.{field} must be a list of strings.")

    if previous_path and Path(previous_path).exists():
        with Path(previous_path).open(encoding="utf-8") as previousfile:
            previous = json.load(previousfile)
        require(isinstance(previous, dict), "Previous dataset root must be a JSON object.")
        if dataset != previous:
            require(type(previous.get("datasetversion")) is int
                    and dataset["datasetversion"] > previous["datasetversion"],
                    "Increase datasetversion whenever the target rules data changes.")


if __name__ == "__main__":
    try:
        parser = argparse.ArgumentParser(description=__doc__)
        parser.add_argument("dataset", help="Path to the target rules JSON file.")
        parser.add_argument("--previous", help="Optional previous release rules JSON file.")
        args = parser.parse_args()
        validate(args.dataset, args.previous)
    except (IndexError, OSError, json.JSONDecodeError, ValueError) as error:
        print(f"Target rules validation failed: {error}", file=sys.stderr)
        sys.exit(1)
    print("Target rules dataset is valid.")
