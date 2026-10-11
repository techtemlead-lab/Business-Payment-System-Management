#!/usr/bin/env bash
set -euo pipefail
cd /workspace/Business-Payment-System-Management
runtime_root=/workspace/.runtime
export PATH="$runtime_root/bin:$runtime_root/root/usr/lib/postgresql/17/bin:$PATH"
if ! pg_ctl -D "$runtime_root/postgres" status >/dev/null 2>&1; then
  pg_ctl -D "$runtime_root/postgres" -l "$runtime_root/postgres.log" -o "-k $runtime_root -p 5432 -h 127.0.0.1" start
fi
app_port="${NEXORA_PORT:-8000}"
if ! curl --silent --fail "http://127.0.0.1:$app_port/up" >/dev/null; then
  nohup php artisan serve --host=0.0.0.0 --port="$app_port" --no-reload > "$runtime_root/nexora-$app_port.log" 2>&1 &
  printf '%s\n' "$!" > "$runtime_root/nexora-$app_port.pid"
fi
for attempt in $(seq 1 30); do
  if curl --silent --fail "http://127.0.0.1:$app_port/" > "$runtime_root/nexora-readiness.html"; then
    python - "$runtime_root/nexora-readiness.html" <<'PY'
from pathlib import Path
import sys
page=Path(sys.argv[1]).read_text()
assert 'data-page=' in page and 'Welcome' in page, 'Expected the public Inertia website'
PY
    printf 'Website responded successfully on port %s.\n' "$app_port"
    exit 0
  fi
  sleep 1
done
printf 'Website startup failed. Inspect %s/nexora-%s.log\n' "$runtime_root" "$app_port" >&2
exit 1
