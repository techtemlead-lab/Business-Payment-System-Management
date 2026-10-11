FROM node:22.23.3-bookworm AS node
FROM composer:2 AS composer
FROM php:8.4-cli-bookworm

RUN sed -i 's|http://deb.debian.org|https://deb.debian.org|g' /etc/apt/sources.list.d/debian.sources \
    && apt-get update \
    && apt-get install -y --no-install-recommends git unzip curl libpq-dev libicu-dev libzip-dev \
    && docker-php-ext-install -j2 pdo_pgsql intl zip pcntl opcache \
    && rm -rf /var/lib/apt/lists/* \
    && useradd --create-home --shell /bin/bash --uid 1000 vscode

COPY --from=node /usr/local/bin/node /usr/local/bin/node
COPY --from=node /usr/local/lib/node_modules /usr/local/lib/node_modules
RUN ln -s /usr/local/lib/node_modules/npm/bin/npm-cli.js /usr/local/bin/npm \
    && ln -s /usr/local/lib/node_modules/npm/bin/npx-cli.js /usr/local/bin/npx \
    && npm install --global npm@12.2.0 \
    && npm cache clean --force
COPY --from=composer /usr/bin/composer /usr/local/bin/composer

# Keep dependency reads on Docker's Linux filesystem rather than a Windows bind mount.
RUN mkdir -p /workspaces/Business-Payment-System-Management/vendor /workspaces/Business-Payment-System-Management/node_modules \
    && chown -R vscode:vscode /workspaces \
    && printf '%s\n' 'opcache.enable_cli=1' 'opcache.memory_consumption=128' 'opcache.max_accelerated_files=20000' 'opcache.validate_timestamps=1' 'opcache.revalidate_freq=0' > /usr/local/etc/php/conf.d/preview-opcache.ini

USER vscode
WORKDIR /workspaces/Business-Payment-System-Management
