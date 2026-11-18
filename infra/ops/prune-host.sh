#!/usr/bin/env bash
# Frees disk space on the shared VPS without touching anything that is not Madina's.
#
#   infra/ops/prune-host.sh            dry run: lists what would be removed and the disk use
#   infra/ops/prune-host.sh --apply    removes it
#
# - Pre-deploy files in PREDEPLOY_DIR: keeps the newest PREDEPLOY_KEEP of each kind (database dumps, storage
#   and branding tars, release tarballs and folders, old prep/switch scripts, deploy logs).
# - Madina's own dangling (untagged) images, by the compose project label. Never another project's images,
#   never volumes, never `docker system prune -a`: the server also runs other companies' containers.
# - Docker build cache unused for 30 days (7 days when the disk is over DISK_PRUNE_PERCENT). Build cache is
#   only a speed-up; removing old entries never breaks a running container.
# Weekly cron, e.g. /etc/cron.d/madina-prune:  0 5 * * 0 root /opt/madina-nhac/infra/ops/prune-host.sh --apply >> /var/log/madina-prune.log 2>&1
set -euo pipefail

ENV_FILE=${MADINA_OPS_ENV:-/etc/madina-ops.env}
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
COMPOSE_PROJECT=${COMPOSE_PROJECT:-madina-production}
PREDEPLOY_DIR=${PREDEPLOY_DIR:-/root/madina-predeploy}
PREDEPLOY_KEEP=${PREDEPLOY_KEEP:-10}
DISK_PRUNE_PERCENT=${DISK_PRUNE_PERCENT:-80}
apply=false
[ "${1:-}" = "--apply" ] && apply=true

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') prune: $*"; }
disk_use() { df --output=pcent / | tail -1 | tr -dc '0-9'; }
remove() {
    if [ "$apply" = true ]; then rm -rf -- "$1" && log "removed $1"; else log "would remove $1"; fi
}

log "disk use before: $(disk_use)%"
if [ -d "$PREDEPLOY_DIR" ]; then
    for pattern in 'before-deploy-*.dump' 'before-rollback-*.dump' 'storage-*.tar' 'branding-*.tar' 'release-*.tar.gz' 'prep-*.sh' 'switch-*.sh' 'deploy-*.log'; do
        mapfile -t files < <(find "$PREDEPLOY_DIR" -maxdepth 1 -type f -name "$pattern" -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)
        for old in "${files[@]:$PREDEPLOY_KEEP}"; do remove "$old"; done
    done
    # Unpacked release folders: the newest few are kept for server-rollback.sh.
    mapfile -t dirs < <(find "$PREDEPLOY_DIR" -maxdepth 1 -mindepth 1 -type d -name 'release-*' -printf '%T@ %p\n' | sort -rn | cut -d' ' -f2-)
    for old in "${dirs[@]:${KEEP_RELEASES:-3}}"; do remove "$old"; done
fi

until_hours=720
[ "$(disk_use)" -ge "$DISK_PRUNE_PERCENT" ] && until_hours=168
if [ "$apply" = true ]; then
    docker image prune -f --filter "label=com.docker.compose.project=$COMPOSE_PROJECT" | tail -1
    docker builder prune -f --filter "until=${until_hours}h" | tail -1
else
    log "would remove dangling $COMPOSE_PROJECT images:"
    docker image ls --filter dangling=true --filter "label=com.docker.compose.project=$COMPOSE_PROJECT" --format '  {{.ID}} {{.CreatedSince}} {{.Size}}'
    log "would remove Docker build cache unused for ${until_hours} hours (docker system df shows the total)"
    docker system df | sed -n '1p;/Build Cache/p'
fi
log "disk use after: $(disk_use)%"
