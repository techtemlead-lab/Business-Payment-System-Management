#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
until [[ -f vendor/autoload.php && -f public/build/manifest.json && -f storage/app/preview-ready ]]; do
  sleep 1
done
exec php artisan serve --host=0.0.0.0 --port=8000 --no-reload
