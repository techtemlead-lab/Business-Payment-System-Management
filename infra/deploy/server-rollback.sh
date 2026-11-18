#!/usr/bin/env bash
# Runs ON THE SERVER: go back to an earlier release in about a minute, without rebuilding.
#
#   bash server-rollback.sh <sha>                                  code only (the usual case)
#   bash server-rollback.sh <sha> --restore-database <file.dump>   code AND the database as it was before
#                                                                   that dump (loses everything entered since!)
#
# Needs the images madina-application:<sha> and madina-web:<sha>, which server-release.sh keeps for the last
# KEEP_RELEASES releases (`docker image ls madina-application`). Older releases need a normal deploy of that commit.
#
# Code-only rollback keeps the database as it is. That is safe when the newer release only ADDED tables or
# columns (the usual rule, see docs/RUNBOOK.md "Migrations without downtime"); the script lists the migrations
# the older code does not know so you can judge. Restoring the database is the last resort for a release whose
# migration damaged data: it first takes a fresh dump of the current database, then asks you to type RESTORE.
set -euo pipefail
exec 3>&1

sha=${1:?usage: server-rollback.sh <sha> [--restore-database <file.dump>]}
sha=${sha:0:8}
restore_dump=""
if [ "${2:-}" = "--restore-database" ]; then
    restore_dump=${3:?--restore-database needs a dump file, for example /root/madina-predeploy/before-deploy-<sha>-<time>.dump}
    [ -s "$restore_dump" ] || { echo "Dump file $restore_dump not found or empty" >&2; exit 1; }
fi
ENV_FILE=${MADINA_OPS_ENV:-/etc/madina-ops.env}
# shellcheck disable=SC1090
[ -f "$ENV_FILE" ] && . "$ENV_FILE"
STACK_DIR=${STACK_DIR:-/opt/madina-nhac}
PREDEPLOY_DIR=${PREDEPLOY_DIR:-/root/madina-predeploy}
stamp=$(date +%Y%m%d-%H%M%S)

log() { echo "$(date '+%Y-%m-%d %H:%M:%S') rollback to $sha: $*" >&3; }
finished=false
# A failed rollback may leave the site in maintenance mode; say how to get out of it.
trap '[ "$finished" = true ] || log "ROLLBACK FAILED (see above). If the site shows maintenance: cd $STACK_DIR && docker compose --env-file .env.production -f compose.production.yaml exec app php artisan up"' EXIT
compose() { (cd "$STACK_DIR" && MADINA_IMAGE_TAG=${tag:-release} docker compose --env-file .env.production -f compose.production.yaml "$@"); }

exec 9> /run/madina-deploy.lock
flock -n 9 || { log "another deploy or rollback is running"; exit 1; }
for image in madina-application madina-web; do
    docker image inspect "$image:$sha" > /dev/null 2>&1 || { log "image $image:$sha is not on this server; deploy that commit instead (infra/deploy/deploy.sh $sha)"; exit 1; }
done
current=$(cat "$STACK_DIR/DEPLOYED_COMMIT" 2>/dev/null || echo unknown)
current=${current:0:8}
log "running now: $current"

# Migrations the older code does not have (they stay in the database on a code-only rollback).
target_migrations=$(docker run --rm --entrypoint sh "madina-application:$sha" -c 'ls database/migrations' | sed 's/\.php$//' | sort)
ran_migrations=$(tag=release compose exec -T db psql -U madina -d madina -Atc 'SELECT migration FROM migrations ORDER BY 1' < /dev/null | sort)
newer=$(comm -13 <(printf '%s\n' "$target_migrations") <(printf '%s\n' "$ran_migrations") || true)
if [ -n "$newer" ]; then
    log "the database has migrations that $sha does not know:"
    printf '   %s\n' $newer
    [ -z "$restore_dump" ] && log "code-only rollback keeps them; that is fine if they only added tables or columns."
fi

if [ -n "$restore_dump" ]; then
    echo "This replaces the WHOLE database with $restore_dump. Everything entered after that dump is lost."
    read -r -p "Type RESTORE to continue: " answer
    [ "$answer" = "RESTORE" ] || { log "cancelled"; exit 1; }
fi

log "maintenance mode on"
tag=release compose exec -T app php artisan down --retry=15 < /dev/null || true
tag=release compose stop queue scheduler

if [ -n "$restore_dump" ]; then
    log "safety dump of the current database first"
    tag=release compose exec -T db pg_dump -U madina -d madina --format=custom < /dev/null > "$PREDEPLOY_DIR/before-rollback-$current-$stamp.dump"
    [ -s "$PREDEPLOY_DIR/before-rollback-$current-$stamp.dump" ]
    tag=release compose stop app
    log "restoring $restore_dump"
    tag=release compose exec -T db sh -c 'dropdb -U madina --force madina && createdb -U madina madina' < /dev/null
    tag=release compose exec -T db pg_restore -U madina -d madina --no-owner --no-acl --exit-on-error < "$restore_dump"
fi

log "starting $sha"
if [ -d "$PREDEPLOY_DIR/release-$sha" ]; then
    rsync -a --delete --exclude '.env' --exclude '.env.production' --exclude 'acme/' --exclude 'storage/' \
        --exclude 'DEPLOYED_COMMIT' --exclude 'RELEASES' --exclude 'build.log' "$PREDEPLOY_DIR/release-$sha/" "$STACK_DIR/"
fi
tag=$sha compose up -d --no-build
tag=$sha compose exec -T web nginx -s reload < /dev/null
tag=$sha compose exec -T app php artisan up < /dev/null
docker tag "madina-application:$sha" madina-application:release
docker tag "madina-web:$sha" madina-web:release
echo "$sha" > "$STACK_DIR/DEPLOYED_COMMIT"
echo "$(date -u +%Y-%m-%dT%H:%M:%SZ) rollback $sha previous=$current${restore_dump:+ database=$(basename "$restore_dump")}" >> "$STACK_DIR/RELEASES"
tag=$sha compose exec -T app php artisan app:readiness < /dev/null || true
finished=true
log "done. Tell the owner the site went back to $sha${restore_dump:+ and the data went back to the time of $(basename "$restore_dump")}."
