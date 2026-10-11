#!/usr/bin/env bash
set -euo pipefail
cd /workspace/Business-Payment-System-Management
runtime_root=/workspace/.runtime
mkdir -p "$runtime_root/bin" "$runtime_root/php"
if [[ ! -x "$runtime_root/bin/php" ]]; then
  mkdir -p "$runtime_root/apt/lists/partial" "$runtime_root/apt/cache/archives/partial" "$runtime_root/apt/empty" "$runtime_root/root"
  cat > "$runtime_root/apt/sources.list" <<'SOURCES'
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian trixie main
deb [signed-by=/usr/share/keyrings/debian-archive-keyring.gpg] https://deb.debian.org/debian-security trixie-security main
SOURCES
  apt_options=(-o Debug::NoLocking=true -o "Dir::Etc::sourcelist=$runtime_root/apt/sources.list" -o Dir::Etc::sourceparts=- -o Dir::Etc::main=/dev/null -o "Dir::Etc::parts=$runtime_root/apt/empty" -o "Dir::State::lists=$runtime_root/apt/lists" -o "Dir::Cache=$runtime_root/apt/cache" -o "Dir::Cache::archives=$runtime_root/apt/cache/archives")
  /usr/bin/apt-get "${apt_options[@]}" update
  /usr/bin/apt-get "${apt_options[@]}" --download-only --no-install-recommends -y install php8.4-cli php8.4-mbstring php8.4-xml php8.4-curl php8.4-pgsql php8.4-sqlite3 php8.4-zip postgresql-17 composer
  for package in "$runtime_root"/apt/cache/archives/*.deb; do dpkg-deb -x "$package" "$runtime_root/root"; done
  python - <<'PY'
from pathlib import Path
root='/workspace/.runtime/root'
modules=['ctype','curl','dom','fileinfo','iconv','intl','mbstring','pdo','pdo_pgsql','pdo_sqlite','phar','posix','simplexml','tokenizer','xml','xmlreader','xmlwriter','zip']
Path('/workspace/.runtime/php/php.ini').write_text('date.timezone=Asia/Dhaka\nmemory_limit=512M\ninclude_path=".:/workspace/.runtime/root/usr/share/php"\nextension_dir="'+root+'/usr/lib/php/20240924"\n'+''.join('extension='+m+'\n' for m in modules))
for file in Path(root+'/usr/share/php').rglob('autoload.php'):
    old=file.read_text();new=old.replace('/usr/share/php/',root+'/usr/share/php/')
    if old!=new:file.write_text(new)
Path('/workspace/.runtime/bin/php').write_text('#!/bin/sh\nexport PHPRC=/workspace/.runtime/php\nexport LD_LIBRARY_PATH=/workspace/.runtime/root/usr/lib/x86_64-linux-gnu${LD_LIBRARY_PATH:+:$LD_LIBRARY_PATH}\nexec /workspace/.runtime/root/usr/bin/php8.4 -c /workspace/.runtime/php/php.ini "$@"\n')
Path('/workspace/.runtime/bin/composer').write_text('#!/bin/sh\nexport PATH=/workspace/.runtime/bin:$PATH\nexec php /workspace/.runtime/root/usr/bin/composer "$@"\n')
PY
  chmod +x "$runtime_root/bin/php" "$runtime_root/bin/composer"
fi
export PATH="$runtime_root/bin:$runtime_root/root/usr/lib/postgresql/17/bin:$PATH"
export COMPOSER_HOME="$runtime_root/composer"
php -r 'exit(version_compare(PHP_VERSION,"8.4.0",">=")?0:1);'
if [[ ! -f "$runtime_root/postgres/PG_VERSION" ]]; then
  initdb -D "$runtime_root/postgres" -U "$(id -un)" --auth-local=trust --auth-host=scram-sha-256 --no-locale --encoding=UTF8
fi
if ! pg_ctl -D "$runtime_root/postgres" status >/dev/null 2>&1; then
  pg_ctl -D "$runtime_root/postgres" -l "$runtime_root/postgres.log" -o "-k $runtime_root -p 5432 -h 127.0.0.1" start
fi
if [[ "$(psql -h "$runtime_root" -U "$(id -un)" -d postgres -Atqc "SELECT 1 FROM pg_database WHERE datname='dealer_management'")" != 1 ]]; then
  createdb -h "$runtime_root" -U "$(id -un)" dealer_management
fi
composer install --prefer-source --no-interaction
npm_version="$(cat .npm-version)"
npm_cli="$runtime_root/node-tools/node_modules/npm/bin/npm-cli.js"
if [[ ! -f "$npm_cli" ]] || [[ "$(node "$npm_cli" --version)" != "$npm_version" ]]; then
  npm install --prefix "$runtime_root/node-tools" --no-save --package-lock=false --ignore-scripts --cache "$runtime_root/npm" "npm@$npm_version"
fi
printf '%s\n' '#!/bin/sh' 'exec node /workspace/.runtime/node-tools/node_modules/npm/bin/npm-cli.js "$@"' > "$runtime_root/bin/npm"
chmod +x "$runtime_root/bin/npm"
npm ci --ignore-scripts --cache "$runtime_root/npm" --no-audit --no-fund
if [[ ! -f .env ]]; then
  python - <<'PY'
from pathlib import Path
import pwd,os
p=Path('.env');text=Path('.env.example').read_text().replace('DB_HOST=127.0.0.1','DB_HOST=/workspace/.runtime').replace('DB_USERNAME=\n','DB_USERNAME='+pwd.getpwuid(os.getuid()).pw_name+'\n').replace('DEMO_ENABLED=false','DEMO_ENABLED=true')
p.write_text(text);p.chmod(0o600)
PY
fi
if php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(empty(config("app.key"))?0:1);'; then
  php artisan key:generate --no-interaction
fi
php artisan migrate --seed --force --no-interaction
if php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(app()->environment("local") && config("dealer.demo_enabled") ? 0 : 1);'; then
  php artisan db:seed --class=DemoSeeder --no-interaction
fi
npm run build
php artisan view:cache --no-interaction
