FROM node:22-alpine AS assets
WORKDIR /app
COPY package.json package-lock.json vite.config.js ./
COPY resources ./resources
COPY public ./public
RUN npm ci --no-audit --no-fund && npm run build

FROM php:8.3-cli-alpine AS app
RUN apk add --no-cache \
        freetype-dev libjpeg-turbo-dev libpng-dev icu-dev libzip-dev oniguruma-dev \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install pdo_mysql mbstring gd intl zip bcmath pcntl opcache
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /app
COPY composer.json composer.lock artisan ./
COPY app ./app
COPY bootstrap ./bootstrap
COPY config ./config
COPY database ./database
COPY routes ./routes
RUN composer install --no-dev --optimize-autoloader --no-interaction --no-scripts
COPY --from=assets /app/public/build ./public/build
COPY . .
ENV COMPOSER_ALLOW_SUPERUSER=1
CMD ["sh", "docker/entrypoint.sh"]
