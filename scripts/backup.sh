#!/usr/bin/env bash
set -euo pipefail
umask 077
: "${BACKUP_CERT_PATH:?Set BACKUP_CERT_PATH to the public certificate of your backup recipient}"
: "${DB_DATABASE:?Set DB_DATABASE}"
: "${DB_USERNAME:?Set DB_USERNAME}"
backup_directory="${BACKUP_DIRECTORY:-storage/app/backups}"
mkdir -p "$backup_directory"
chmod 700 "$backup_directory"
backup_file="$backup_directory/dealer-$(date -u +%Y%m%dT%H%M%SZ)-$$.dump.cms"
backup_partial="$backup_file.partial"
trap 'rm -f "$backup_partial"' EXIT
export PGPASSWORD="${DB_PASSWORD:-}"
pg_dump --host="${DB_HOST:-127.0.0.1}" --port="${DB_PORT:-5432}" --username="$DB_USERNAME" --dbname="$DB_DATABASE" --format=custom --no-owner --no-acl |
  openssl cms -encrypt -binary -aes-256-gcm -outform DER -out "$backup_partial" "$BACKUP_CERT_PATH"
mv "$backup_partial" "$backup_file"
printf 'Encrypted backup created: %s\n' "$backup_file"
