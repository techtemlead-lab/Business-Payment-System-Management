FROM php:8.4-cli-bookworm AS development
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev libicu-dev libpng-dev libjpeg62-turbo-dev libfreetype6-dev unzip git && docker-php-ext-configure gd --with-freetype --with-jpeg && docker-php-ext-install pdo_pgsql bcmath intl zip gd && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/local/bin/composer
WORKDIR /var/www/html
COPY vendor /var/www/html/vendor
EXPOSE 8000
CMD ["php", "-d", "opcache.enable_cli=1", "-S", "0.0.0.0:8000", "-t", "public", "infra/dev-router.php"]
