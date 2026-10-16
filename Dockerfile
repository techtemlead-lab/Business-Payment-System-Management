FROM php:8.5-cli-bookworm AS development
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev unzip git && docker-php-ext-install pdo_pgsql bcmath intl zip && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY vendor /var/www/html/vendor
EXPOSE 8000
CMD ["php", "-d", "opcache.enable_cli=1", "-S", "0.0.0.0:8000", "-t", "public", "infra/dev-router.php"]
