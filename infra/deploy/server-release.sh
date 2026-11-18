#!/usr/bin/env bash
# Runs ON THE SERVER, started by infra/deploy/deploy.sh from the unpacked release folder.
#
#   bash /root/madina-predeploy/release-<sha>/infra/deploy/server-release.sh <sha> ["gate skip reason"]
#
# Steps (the manual routine used since 9 Nov 2026, scripted):
#   backup (pg_dump + upload volumes) -> rsync the release into the stack -> build images tagged <sha>
#   -> maintenance mode -> stop queue and scheduler -> migrate -> start everything on the new images
#   -> nginx reload -> leave maintenance -> health check -> record the release -> keep the last KEEP_RELEASES images.
# If anything fails after maintenance mode starts, the previous images are started again and the site is
# taken out of maintenance, so a failed deploy does not leave the site down. Then it prints DEPLOY FAILED.
set -euo pipefail

sha=${1:?usage: server-release.sh <sha> [gate skip reason]}
skip_reason=${2:-}
ENV_FILE=${MADINA_OPS_ENV:-/etc/madina-ops.env}
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
STACK_DIR=${STACK_DIR:-/opt/madina-nhac}
COMPOSE_PROJECT=${COMPOSE_PROJECT:-madina-production}
PREDEPLOY_DIR=${PREDEPLOY_DIR:-/root/madina-predeploy}
KEEP_RELEASES=${KEEP_RELEASES:-3}
release_dir="$PREDEPLOY_DIR/release-$sha"
stamp=$(date +%Y%m%d-%H%M%S)

# fd 3 is the real log: a failing step may have its output redirected into a file (the database dump).
exec 3>&1
log() { echo "$(date '+%Y-%m-%d %H:%M:%S') deploy $sha: $*" >&3; }
compose() { (cd "$STACK_DIR" && MADINA_IMAGE_TAG=${tag:-release} docker compose --env-file .env.production -f compose.production.yaml "$@"); }

previous=unknown
in_maintenance=false
finished=false
# Runs once when the script ends for any reason; set -e ends it at the first failing step.
on_exit() {
    local status=$?
    [ "$finished" = true ] && return
    log "a step failed (exit $status); see the lines above"
    if [ "$in_maintenance" = true ]; then
        log "starting the previous release ($previous) again"
        if docker image inspect "madina-application:$previous" > /dev/null 2>&1; then
            tag=$previous compose up -d --no-build >&3 2>&1 || true
        else
            tag=release compose up -d --no-build >&3 2>&1 || true
        fi
        tag=release compose exec -T web nginx -s reload < /dev/null >&3 2>&1 || true
        tag=release compose exec -T app php artisan up < /dev/null >&3 2>&1 || true
    fi
    echo "DEPLOY FAILED" >&3
}
trap on_exit EXIT

exec 9> /run/madina-deploy.lock
flock -n 9 || { log "another deploy or rollback is running"; exit 1; }
[ -d "$release_dir" ] || { log "release folder $release_dir is missing"; exit 1; }

previous=$(cat "$STACK_DIR/DEPLOYED_COMMIT" 2>/dev/null || echo unknown)
previous=${previous:0:8}

[ -n "$skip_reason" ] && log "WARNING: CI gate skipped by the operator: $skip_reason"
log "previous release: $previous"

log "backup before deploy"
tag=release compose exec -T db pg_dump -U madina -d madina --format=custom < /dev/null > "$PREDEPLOY_DIR/before-deploy-$sha-$stamp.dump"
[ -s "$PREDEPLOY_DIR/before-deploy-$sha-$stamp.dump" ]
storage=$(docker volume inspect --format '{{ .Mountpoint }}' "${COMPOSE_PROJECT}_application-storage")
tar -C "$storage" --exclude='./logs/*' --exclude='./framework/*' -cf "$PREDEPLOY_DIR/storage-$sha-$stamp.tar" .
tag=release compose exec -T app php artisan app:backup < /dev/null || log "app:backup failed (the pg_dump above is still there); continuing"

log "copying the release into $STACK_DIR"
rsync -a --delete \
    --exclude '.env' --exclude '.env.production' --exclude 'acme/' --exclude 'storage/' \
    --exclude 'DEPLOYED_COMMIT' --exclude 'RELEASES' --exclude 'build.log' \
    "$release_dir/" "$STACK_DIR/"

log "building images madina-application:$sha and madina-web:$sha"
tag=$sha compose build app web > "$STACK_DIR/build.log" 2>&1 || { tail -40 "$STACK_DIR/build.log"; false; }

log "maintenance mode on"
in_maintenance=true
tag=release compose exec -T app php artisan down --retry=15 < /dev/null || true
log "stopping queue and scheduler (they finish the job in hand first)"
tag=release compose stop queue scheduler

log "migrating"
tag=$sha compose run --rm --no-deps -T app php artisan migrate --force --no-interaction < /dev/null

log "starting the new release"
tag=$sha compose up -d --no-build
tag=$sha compose exec -T web nginx -s reload < /dev/null
tag=$sha compose exec -T app php artisan up < /dev/null
in_maintenance=false

# The plain "release" tag always points at what runs, so a manual `docker compose up -d` keeps this version.
docker tag "madina-application:$sha" madina-application:release
docker tag "madina-web:$sha" madina-web:release

log "health check"
domain=$(grep -E '^APP_DOMAIN=' "$STACK_DIR/.env.production" | cut -d= -f2- | tr -d '"' || true)
healthy=false
for _ in $(seq 1 30); do
    if curl -fsS -m 5 -o /dev/null -H "Host: ${domain:-localhost}" http://127.0.0.1:8095/up; then healthy=true; break; fi
    sleep 2
done
if [ "$healthy" != true ]; then
    log "WARNING: /up did not answer 200 within a minute. Check now; to go back run: bash $release_dir/infra/deploy/server-rollback.sh $previous"
fi

echo "$sha" > "$STACK_DIR/DEPLOYED_COMMIT"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) deploy $sha previous=$previous${skip_reason:+ gate-skipped=\"$skip_reason\"}" >> "$STACK_DIR/RELEASES"

log "keeping the images of the last $KEEP_RELEASES releases"
mapfile -t keep < <(awk '$2 == "deploy" || $2 == "rollback" { print $3 }' "$STACK_DIR/RELEASES" | tac | awk '!seen[$0]++' | head -n "$KEEP_RELEASES")
for image in madina-application madina-web; do
    docker image ls "$image" --format '{{.Tag}}' | while read -r old; do
        case " ${keep[*]} release " in *" $old "*) ;; *) docker image rm "$image:$old" > /dev/null 2>&1 && log "removed $image:$old" || true ;; esac
    done
done

tag=$sha compose exec -T app php artisan app:readiness < /dev/null || true
finished=true
log "rollback if needed: bash $release_dir/infra/deploy/server-rollback.sh $previous"
echo "DEPLOY DONE" >&3
