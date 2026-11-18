#!/usr/bin/env bash
# Monthly restore drill on the host: proves a host backup opens and restores, and how long it takes (RTO).
#
#   infra/ops/restore-drill.sh [path/to/madina-host-*.tar.enc]     (default: the newest host backup)
#
# Decrypts the backup into a temporary folder, restores the database into a throwaway PostgreSQL 16 container
# with no network, compares row counts of key tables with live, checks the journal balances, and writes the
# result beside the app backups (restore-drill-last.json, read by `app:readiness`). The live database is only
# read (row counts). The container and the temporary files are removed at the end.
#
# The app-level drill is `php artisan app:backup-restore-drill` (restores the app's own nightly backup into a
# scratch database on the live server; needs a database user that may create databases).
set -Eeuo pipefail
umask 077

ENV_FILE=${MADINA_OPS_ENV:-/etc/madina-ops.env}
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
STACK_DIR=${STACK_DIR:-/opt/madina-nhac}
COMPOSE_PROJECT=${COMPOSE_PROJECT:-madina-production}
HOST_BACKUP_DIR=${HOST_BACKUP_DIR:-/var/backups/madina-host}
HOST_BACKUP_PASSPHRASE_FILE=${HOST_BACKUP_PASSPHRASE_FILE:-/root/.madina-host-backup-pass}
HEARTBEAT_URL=${RESTORE_DRILL_HEARTBEAT_URL:-}
KEY_TABLES=(users orders order_lines payment_claims withdrawals wallets journal_entries journal_lines money_events audit_events products)

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') restore-drill: $*"; logger -t madina-restore-drill -- "$*" 2>/dev/null || true; }
compose() { (cd "$STACK_DIR" && docker compose --env-file .env.production -f compose.production.yaml "$@"); }

file=${1:-$(ls -1t "$HOST_BACKUP_DIR"/madina-host-*.tar.enc 2>/dev/null | head -1)}
[ -n "$file" ] && [ -f "$file" ] || { log "no host backup found in $HOST_BACKUP_DIR; run infra/ops/host-backup.sh first"; exit 1; }
container="madina-restore-drill-$(date +%s)"
work=$(mktemp -d /tmp/madina-restore-drill-XXXXXX)
record_dir=$(docker volume inspect --format '{{ .Mountpoint }}' "${COMPOSE_PROJECT}_private-backups")
started=$(date +%s)
problems=()

finish() {
    local status=$?
    docker rm -f "$container" >/dev/null 2>&1 || true
    rm -rf "$work"
    local passed=false
    [ "$status" -eq 0 ] && [ "${#problems[@]}" -eq 0 ] && passed=true
    local joined=""
    [ "${#problems[@]}" -gt 0 ] && joined=$(printf '%s; ' "${problems[@]}")
    printf '{"passed":%s,"ran_at":"%s","by":"infra/ops/restore-drill.sh","file":"%s","restore_seconds":%s,"problems":"%s"}\n' \
        "$passed" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$(basename "$file")" "${restore_seconds:-null}" "${joined//\"/}" > "$record_dir/restore-drill-last.json"
    chmod 644 "$record_dir/restore-drill-last.json"
    if [ "$passed" = true ]; then
        log "PASSED: $(basename "$file") restored in ${restore_seconds}s"
        [ -n "$HEARTBEAT_URL" ] && curl -fsS -m 10 -o /dev/null "$HEARTBEAT_URL" || true
    else
        log "FAILED (exit $status): ${joined:-see the lines above}"
        [ -n "$HEARTBEAT_URL" ] && curl -fsS -m 10 -o /dev/null "${HEARTBEAT_URL%/}/fail" || true
    fi
}
trap finish EXIT

log "decrypting $(basename "$file")"
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$HOST_BACKUP_PASSPHRASE_FILE" -in "$file" | tar -C "$work" -xf -
( cd "$work" && sha256sum -c --quiet SHA256SUMS )

log "starting a throwaway PostgreSQL 16 (no network)"
docker run -d --name "$container" --network none -e POSTGRES_PASSWORD=drill-only -e POSTGRES_DB=drill postgres:16-bookworm >/dev/null
# The image first runs a temporary server to create the database, then restarts; wait for the real one.
for _ in $(seq 1 120); do
    if docker logs "$container" 2>&1 | grep -q 'PostgreSQL init process complete' \
        && docker exec "$container" pg_isready -U postgres -d drill >/dev/null 2>&1; then
        break
    fi
    sleep 1
done

log "restoring"
restore_started=$(date +%s)
docker exec -i "$container" pg_restore -U postgres -d drill --no-owner --no-acl --exit-on-error < "$work/database.dump"
restore_seconds=$(( $(date +%s) - restore_started ))

drill_sql() { docker exec "$container" psql -U postgres -d drill -Atc "$1"; }
live_sql() { compose exec -T db psql -U madina -d madina -Atc "$1" < /dev/null; }

[ "$(drill_sql "SELECT count(*) FROM migrations")" -gt 0 ] || problems+=("no migrations in the restored copy")
difference=$(drill_sql "SELECT COALESCE(SUM(debit),0) - COALESCE(SUM(credit),0) FROM journal_lines")
[ "$difference" = "0.00" ] || [ "$difference" = "0" ] || problems+=("restored journal does not balance ($difference)")
printf '%-18s %12s %12s\n' "table" "in backup" "live now"
for table in "${KEY_TABLES[@]}"; do
    if [ "$(drill_sql "SELECT to_regclass('public.$table') IS NOT NULL")" = "t" ]; then
        printf '%-18s %12s %12s\n' "$table" "$(drill_sql "SELECT count(*) FROM $table")" "$(live_sql "SELECT count(*) FROM $table")"
    fi
done
for archive in "$work"/*.tar.gz; do
    tar -tzf "$archive" > /dev/null || problems+=("the upload archive $(basename "$archive") is damaged")
done
log "whole drill took $(( $(date +%s) - started ))s (decrypt + restore + checks); write it down as the database RTO"
[ "${#problems[@]}" -eq 0 ]
