#!/usr/bin/env bash
# Host-level backup for Madina: a second copy that does not depend on the app container.
#
#   1. pg_dump of the business database from the db container (custom format), checked with pg_restore --list.
#   2. One tar per upload volume (partner/staff photos, private evidence, website photos, category icons).
#   3. Both packed into one file and encrypted with a passphrase file (openssl AES-256, PBKDF2).
#   4. Old host backups pruned (keep HOST_BACKUP_KEEP_DAYS, never fewer than 7).
#   5. Off-site with rclone (when RCLONE_REMOTE is set): this file plus the app's own encrypted nightly
#      backups, daily copies kept RCLONE_DAILY_KEEP_DAYS, a monthly copy on the 1st. A record is written
#      beside the app backups so `app:readiness` knows the off-site copy works.
#   6. Pings HOST_BACKUP_HEARTBEAT_URL (or .../fail).
#
# Run as root from cron, e.g. /etc/cron.d/madina-backup:
#   30 3 * * * root /opt/madina-nhac/infra/ops/host-backup.sh >> /var/log/madina-host-backup.log 2>&1
# Settings: /etc/madina-ops.env (see infra/ops/madina-ops.env.example). Read-only for the live database.
set -Eeuo pipefail
umask 077

ENV_FILE=${MADINA_OPS_ENV:-/etc/madina-ops.env}
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
STACK_DIR=${STACK_DIR:-/opt/madina-nhac}
COMPOSE_PROJECT=${COMPOSE_PROJECT:-madina-production}
HOST_BACKUP_DIR=${HOST_BACKUP_DIR:-/var/backups/madina-host}
HOST_BACKUP_PASSPHRASE_FILE=${HOST_BACKUP_PASSPHRASE_FILE:-/root/.madina-host-backup-pass}
HOST_BACKUP_KEEP_DAYS=${HOST_BACKUP_KEEP_DAYS:-14}
RCLONE_REMOTE=${RCLONE_REMOTE:-}
RCLONE_DAILY_KEEP_DAYS=${RCLONE_DAILY_KEEP_DAYS:-35}
RCLONE_MONTHLY_KEEP_DAYS=${RCLONE_MONTHLY_KEEP_DAYS:-400}
HEARTBEAT_URL=${HOST_BACKUP_HEARTBEAT_URL:-}

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') host-backup: $*"; logger -t madina-host-backup -- "$*" 2>/dev/null || true; }
ping_heartbeat() { [ -n "$HEARTBEAT_URL" ] && curl -fsS -m 10 --retry 3 -o /dev/null --data-raw "${2:-}" "${HEARTBEAT_URL%/}$1" || true; }
fail() { log "FAILED: $*"; ping_heartbeat /fail "$*"; exit 1; }
trap 'fail "line $LINENO: $BASH_COMMAND"' ERR

compose() { (cd "$STACK_DIR" && docker compose --env-file .env.production -f compose.production.yaml "$@"); }
volume_dir() { docker volume inspect --format '{{ .Mountpoint }}' "${COMPOSE_PROJECT}_$1"; }

[ -s "$HOST_BACKUP_PASSPHRASE_FILE" ] || fail "passphrase file $HOST_BACKUP_PASSPHRASE_FILE is missing or empty"
[ "$(wc -c < "$HOST_BACKUP_PASSPHRASE_FILE")" -ge 32 ] || fail "the passphrase must have at least 32 characters"
mkdir -p "$HOST_BACKUP_DIR"
stamp=$(date -u +%Y%m%d-%H%M%S)
work=$(mktemp -d "$HOST_BACKUP_DIR/.work-XXXXXX")
trap 'rm -rf "$work"' EXIT
ping_heartbeat /start

log "dumping the database"
# </dev/null: `exec -T` would otherwise read the rest of this script from stdin.
compose exec -T db pg_dump -U madina -d madina --format=custom --no-owner --no-acl < /dev/null > "$work/database.dump"
[ -s "$work/database.dump" ] || fail "pg_dump wrote nothing"
compose exec -T db pg_restore --list < "$work/database.dump" | grep -q "TABLE DATA" || fail "pg_restore cannot read the new dump"

log "packing the upload volumes"
volumes=(application-storage branding-site-photos branding-category-icons)
for volume in "${volumes[@]}"; do
    dir=$(volume_dir "$volume") || fail "volume ${COMPOSE_PROJECT}_$volume not found"
    # One archive per volume, restored with: tar -C <volume folder> -xzf <volume>.tar.gz. Logs and caches are left out.
    tar -C "$dir" --exclude='./logs/*' --exclude='./framework/cache/*' --exclude='./framework/sessions/*' --exclude='./framework/views/*' \
        -czf "$work/$volume.tar.gz" .
done
parts=(database.dump "${volumes[@]/%/.tar.gz}")

( cd "$work" && sha256sum "${parts[@]}" > SHA256SUMS )
file="$HOST_BACKUP_DIR/madina-host-$stamp.tar.enc"
tar -C "$work" -cf - "${parts[@]}" SHA256SUMS \
    | openssl enc -aes-256-cbc -pbkdf2 -iter 200000 -salt -pass "file:$HOST_BACKUP_PASSPHRASE_FILE" -out "$file.partial"
mv "$file.partial" "$file"
sha256sum "$file" > "$file.sha256"
# Prove the encrypted file opens with the passphrase before trusting it.
openssl enc -d -aes-256-cbc -pbkdf2 -iter 200000 -pass "file:$HOST_BACKUP_PASSPHRASE_FILE" -in "$file" | tar -tf - | grep -q '^database.dump$' \
    || fail "the encrypted backup cannot be opened again"
log "wrote $file ($(du -h "$file" | cut -f1))"

log "pruning host backups older than $HOST_BACKUP_KEEP_DAYS days (the newest 7 always stay)"
mapfile -t backups < <(ls -1t "$HOST_BACKUP_DIR"/madina-host-*.tar.enc 2>/dev/null)
for old in "${backups[@]:7}"; do
    if [ -n "$(find "$old" -mtime +"$HOST_BACKUP_KEEP_DAYS" -print)" ]; then
        rm -f -- "$old" "$old.sha256"
        log "removed $old"
    fi
done

if [ -n "$RCLONE_REMOTE" ]; then
    command -v rclone >/dev/null || fail "RCLONE_REMOTE is set but rclone is not installed (apt install rclone)"
    remote=${RCLONE_REMOTE%/}
    app_backups=$(volume_dir private-backups)
    log "copying off-site to $remote"
    # The folders must exist before old copies can be cleared (no-op on bucket storage).
    for folder in host/daily host/monthly app/daily; do rclone mkdir "$remote/$folder"; done
    rclone copyto "$file" "$remote/host/daily/$(basename "$file")"
    rclone copyto "$file.sha256" "$remote/host/daily/$(basename "$file").sha256"
    # The app's own nightly backups (already encrypted with MADINA_BACKUP_PASSWORD).
    rclone copy "$app_backups" "$remote/app/daily" --include 'madina-*.zip'
    if [ "$(date +%d)" = "01" ]; then
        rclone copyto "$file" "$remote/host/monthly/$(basename "$file")"
    fi
    rclone delete "$remote/host/daily" --min-age "${RCLONE_DAILY_KEEP_DAYS}d"
    rclone delete "$remote/app/daily" --min-age "${RCLONE_DAILY_KEEP_DAYS}d"
    rclone delete "$remote/host/monthly" --min-age "${RCLONE_MONTHLY_KEEP_DAYS}d"
    rclone check "$HOST_BACKUP_DIR" "$remote/host/daily" --one-way --include "$(basename "$file")" \
        || fail "the off-site copy does not match"
    # Tell the app (app:readiness) that the off-site copy works. www-data (uid 33) must be able to read it.
    printf '{"passed":true,"ran_at":"%s","by":"infra/ops/host-backup.sh","remote":"%s","file":"%s"}\n' \
        "$(date -u +%Y-%m-%dT%H:%M:%SZ)" "$remote" "$(basename "$file")" > "$app_backups/offsite-copy-last.json"
    chmod 644 "$app_backups/offsite-copy-last.json"
    log "off-site copy done"
else
    log "RCLONE_REMOTE is empty: no off-site copy made (the backups are still only on this server)"
fi

trap - ERR
ping_heartbeat "" "ok $(basename "$file")"
log "done"
