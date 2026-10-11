#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
preview_port="${NEXORA_PORT:-8000}"
mkdir -p storage/logs
if ! curl --silent --fail --max-time 3 "http://127.0.0.1:$preview_port/up" >/dev/null; then
  nohup php artisan serve --host=0.0.0.0 --port="$preview_port" --no-reload > storage/logs/preview.log 2>&1 < /dev/null &
fi
for attempt in $(seq 1 30); do
  if curl --silent --fail --max-time 3 "http://127.0.0.1:$preview_port/" | php -r '$html=stream_get_contents(STDIN); exit(str_contains($html,"data-page=") && str_contains($html,"Welcome") ? 0 : 1);'; then
    printf 'Nexora is ready. Open port %s in the Codespaces Ports tab.\n' "$preview_port"
    exit 0
  fi
  sleep 1
done
printf 'Preview startup failed. Check storage/logs/preview.log.\n' >&2
exit 1
