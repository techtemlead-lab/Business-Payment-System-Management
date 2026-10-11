#!/usr/bin/env bash
set -euo pipefail
# Use the existing isolated checkout; do not create a Git worktree.
if [[ -x /workspace/.runtime/bin/php ]]; then export PATH=/workspace/.runtime/bin:$PATH; fi
server_pid=''
queue_pid=''
vite_pid=''
cleanup() {
  trap - EXIT INT TERM
  for task_pid in "$server_pid" "$queue_pid" "$vite_pid"; do
    if [[ -n "$task_pid" ]]; then kill -TERM -- "-$task_pid" 2>/dev/null || true; fi
  done
  wait 2>/dev/null || true
  rm -f public/hot
}
trap cleanup EXIT
trap 'exit 130' INT TERM
setsid php artisan serve --host=0.0.0.0 --port="${NEXORA_PORT:-8000}" --no-reload &
server_pid=$!
setsid php artisan queue:work --tries=1 --timeout=90 &
queue_pid=$!
setsid npm run dev &
vite_pid=$!
wait "$vite_pid"
