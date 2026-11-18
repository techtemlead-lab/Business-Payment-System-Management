#!/usr/bin/env bash
# Deploy gate: succeed only when <sha> is a commit on main whose whole test suite ran green in GitHub Actions.
#
#   infra/deploy/check-main-green.sh <sha>
#
# Pull-request CI runs only the related test files (owner rule since PR 396); the whole suite runs on each
# push to main. A deploy must wait for that run: on 17-18 Nov 2026 two deploys went out while main was red.
# Needs the GitHub CLI (`gh auth status` must pass). Exit 0 = green, 1 = not green or not known yet.
set -euo pipefail

sha=${1:?usage: check-main-green.sh <commit sha>}
workflow=${MADINA_CI_WORKFLOW:-quality.yml}
full=$(git rev-parse --verify "$sha^{commit}") || { echo "Unknown commit: $sha" >&2; exit 1; }

git fetch --quiet origin main
if ! git merge-base --is-ancestor "$full" origin/main; then
    echo "REFUSED: $full is not on origin/main. Merge it to main and let the whole suite run first." >&2
    exit 1
fi

# The newest run of the quality workflow for exactly this commit on a push to main.
read -r status conclusion url < <(gh run list --workflow "$workflow" --branch main --event push --commit "$full" \
    --limit 1 --json status,conclusion,url --jq '.[0] | "\(.status) \(.conclusion // "-") \(.url)"' 2>/dev/null || true)

if [ -z "${status:-}" ] || [ "$status" = "null" ]; then
    echo "REFUSED: no whole-suite run on main found for $full. Push it to main and wait for CI." >&2
    exit 1
fi
if [ "$status" != "completed" ]; then
    echo "WAIT: the whole-suite run for $full is still $status: $url" >&2
    exit 1
fi
if [ "$conclusion" != "success" ]; then
    echo "REFUSED: the whole-suite run for $full ended '$conclusion': $url" >&2
    echo "Fix main until it is green, then deploy the green commit." >&2
    exit 1
fi

head=$(git rev-parse origin/main)
if [ "$full" != "$head" ]; then
    echo "NOTE: $full is green but is not the newest main ($head). Deploying an older green commit is allowed (for example to go back)." >&2
fi
echo "GREEN: $full passed the whole suite on main: $url"
