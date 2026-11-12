#!/usr/bin/env bash
# Prints the PHPUnit test files a pull request needs, one per line, or "ALL" when the
# change can reach every page (routes, config, migrations, middleware, the test base class).
# The full suite still runs on every push to main, so nothing is skipped for long.
#
# Usage: related-tests.sh <base-ref>   (for example origin/main)
set -euo pipefail

base="${1:?base ref, e.g. origin/main}"
changed=$(git diff --name-only "$base"...HEAD)

# Changes that can break any test in ways the picker below cannot see: run everything.
# A new migration is not here: every picked test builds the database, so a broken one fails them all.
if grep -qE '^(bootstrap/|app/Providers/|tests/TestCase\.php|tests/Support/|phpunit\.xml|composer\.(json|lock)|\.github/)' <<<"$changed"; then
    echo ALL
    exit 0
fi

declare -A picked=()
add() { [[ -f $1 ]] && picked["$1"]=1 || true; }
# Test files that mention a name as a whole word (class, component or view name).
tests_mentioning() { grep -rlwF --include='*.php' -- "$1" tests/Feature tests/Unit 2>/dev/null || true; }
# Test files that contain an exact piece of text.
tests_containing() { grep -rlF --include='*.php' -- "$1" tests/Feature tests/Unit 2>/dev/null || true; }

while IFS= read -r file; do
    [[ -z $file ]] && continue
    case "$file" in
        tests/Feature/*.php | tests/Unit/*.php)
            add "$file"
            ;;
        app/*.php)
            for test in $(tests_mentioning "$(basename "$file" .php)"); do add "$test"; done
            ;;
        lang/*/*.php)
            group=$(basename "$file" .php)
            # Tests that read the whole word file (both languages, same keys) or load the group.
            for test in $(tests_containing "/$group.php"; tests_containing "'$group'"); do add "$test"; done
            ;;
        resources/views/*)
            view=$(basename "$file" .blade.php)
            for test in $(tests_mentioning "$view"); do add "$test"; done
            ;;
        resources/js/*)
            name=$(basename "${file%.*}")
            for test in $(tests_mentioning "$name"); do add "$test"; done
            ;;
        config/*.php)
            name=$(basename "$file" .php)
            for test in $(tests_containing "config('$name" ; tests_containing "config(\"$name"; tests_containing "/$name.php"); do add "$test"; done
            ;;
        routes/*.php)
            # Tests that call an address added or changed in this route file.
            while IFS= read -r uri; do
                [[ ${#uri} -gt 3 ]] && for test in $(tests_containing "$uri"); do add "$test"; done
            done < <(git diff "$base"...HEAD -- "$file" | grep -E '^\+' | grep -oE "'/[a-z0-9/_-]+" | sed -E "s#^'##; s#/\{.*##" | sort -u)
            ;;
        database/migrations/*.php)
            for test in $(tests_containing "$(basename "$file")"); do add "$test"; done
            ;;
        database/factories/*.php | database/seeders/*.php)
            for test in $(tests_mentioning "$(basename "$file" .php)"); do add "$test"; done
            ;;
    esac
done <<<"$changed"

# Every page still opens, whatever changed.
add tests/Feature/EveryPageSmokeTest.php
add tests/Feature/InertiaPagesRegisteredTest.php

printf '%s\n' "${!picked[@]}" | sort
