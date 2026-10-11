#!/usr/bin/env bash
set -euo pipefail
cd "$(dirname "$0")/.."
if [[ ! -f .env ]]; then
  php <<'PHP'
<?php
$values = [
    'APP_ENV' => 'local', 'APP_DEBUG' => 'false',
    'DB_HOST' => 'db', 'DB_DATABASE' => 'nexora_preview', 'DB_USERNAME' => 'nexora',
    'DB_PASSWORD' => '', 'DEMO_ENABLED' => 'true', 'MAIL_MAILER' => 'array',
];
$name = getenv('CODESPACE_NAME');
$domain = getenv('GITHUB_CODESPACES_PORT_FORWARDING_DOMAIN');
if ($name && $domain) {
    $values['APP_URL'] = "https://{$name}-8000.{$domain}";
    $values['APP_FORCE_HTTPS'] = 'true';
    $values['SESSION_SECURE_COOKIE'] = 'true';
}
$contents = file_get_contents('.env.example');
foreach ($values as $key => $value) {
    $contents = preg_replace('/^'.preg_quote($key, '/').'=.*/m', $key.'='.$value, $contents);
}
file_put_contents('.env', $contents);
chmod('.env', 0600);
PHP
fi
composer install --prefer-dist --no-interaction
php artisan config:clear --no-interaction
php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); if (! app()->environment("local") || config("database.default") !== "pgsql" || config("database.connections.pgsql.database") !== "nexora_preview") { fwrite(STDERR, "Codespaces setup requires APP_ENV=local and the isolated nexora_preview PostgreSQL database.\n"); exit(1); }'
npm ci --ignore-scripts --no-audit --no-fund
if php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(empty(config("app.key"))?0:1);'; then
  php artisan key:generate --no-interaction
fi
php artisan migrate --seed --force --no-interaction
if php -r 'require "vendor/autoload.php"; $app=require "bootstrap/app.php"; $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap(); exit(app()->environment("local") && config("dealer.demo_enabled") ? 0 : 1);'; then
  php artisan db:seed --class=DemoSeeder --no-interaction
fi
npm run build
