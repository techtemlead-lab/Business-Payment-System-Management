#!/usr/bin/env bash
# One-command deploy from an operator's computer (Git Bash on Windows, or any Linux/macOS shell).
#
#   MADINA_DEPLOY_SSH=root@<server> MADINA_DEPLOY_KEY=~/.ssh/madina_deploy infra/deploy/deploy.sh <sha>
#
# 1. Gate: refuses unless <sha> is on main and main's whole test suite passed for it (check-main-green.sh).
#    Emergency only: --skip-gate "<reason>" deploys anyway and writes the reason in the server's release log.
# 2. Sends `git archive <sha>` to the server and runs infra/deploy/server-release.sh from that same release.
# 3. Follows the server log until it says DEPLOY DONE or DEPLOY FAILED.
# The server address and key are never stored in the repository (the repository is public).
set -euo pipefail

sha=""
skip_reason=""
while [ $# -gt 0 ]; do
    case "$1" in
        --skip-gate) skip_reason=${2:?--skip-gate needs a reason in quotes}; shift 2 ;;
        -*) echo "Unknown option $1" >&2; exit 2 ;;
        *) sha=$1; shift ;;
    esac
done
[ -n "$sha" ] || { echo "usage: deploy.sh <sha> [--skip-gate \"reason\"]" >&2; exit 2; }
target=${MADINA_DEPLOY_SSH:?set MADINA_DEPLOY_SSH=root@<server>}
key=${MADINA_DEPLOY_KEY:-$HOME/.ssh/madina_deploy}
remote_dir=${MADINA_PREDEPLOY_DIR:-/root/madina-predeploy}
ssh_opts=(-i "$key" -o BatchMode=yes -o ServerAliveInterval=30)
here=$(cd "$(dirname "$0")" && pwd)

full=$(git rev-parse --verify "$sha^{commit}")
short=${full:0:8}
if [ -z "$skip_reason" ]; then
    "$here/check-main-green.sh" "$full"
else
    echo "WARNING: deploy gate skipped: $skip_reason" >&2
fi

echo "Sending release $short to $target"
git archive --format=tar.gz "$full" | ssh "${ssh_opts[@]}" "$target" "mkdir -p '$remote_dir' && cat > '$remote_dir/release-$short.tar.gz'"
# The server script comes from the release itself, so the steps always match the code being deployed.
ssh "${ssh_opts[@]}" "$target" "set -e; cd '$remote_dir'; rm -rf 'release-$short'; mkdir 'release-$short'; tar -xzf 'release-$short.tar.gz' -C 'release-$short';
    nohup bash 'release-$short/infra/deploy/server-release.sh' '$short' $(printf '%q' "$skip_reason") > 'deploy-$short.log' 2>&1 < /dev/null &
    echo started"

echo "Following deploy-$short.log (Ctrl+C stops watching, not the deploy)"
seen=0
while true; do
    sleep 5
    output=$(ssh "${ssh_opts[@]}" "$target" "tail -n +$((seen + 1)) '$remote_dir/deploy-$short.log'" || true)
    if [ -n "$output" ]; then
        printf '%s\n' "$output"
        seen=$((seen + $(printf '%s\n' "$output" | wc -l)))
    fi
    case "$output" in
        *"DEPLOY DONE"*) exit 0 ;;
        *"DEPLOY FAILED"*) exit 1 ;;
    esac
done
