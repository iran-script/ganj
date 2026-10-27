FROM php:8.4-fpm-bookworm AS production
RUN apt-get update && apt-get install -y --no-install-recommends libpq-dev libzip-dev unzip git \
    && docker-php-ext-install -j1 pdo_pgsql pcntl opcache zip \
    && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN COMPOSER_MEMORY_LIMIT=256M composer install --no-dev --no-interaction --prefer-dist --no-scripts --no-autoloader --no-progress
COPY . .
RUN mkdir -p storage/app/private storage/app/public storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs bootstrap/cache \
    && COMPOSER_MEMORY_LIMIT=256M composer dump-autoload --no-dev --optimize --no-interaction \
    && chown -R www-data:www-data storage bootstrap/cache \
    && chmod -R u=rwX,g=rX,o= storage bootstrap/cache
COPY docker/php-production.ini /usr/local/etc/php/conf.d/zz-nexo.ini
COPY docker/php-fpm-production.conf /usr/local/etc/php-fpm.d/zz-nexo.conf
COPY docker/production-entrypoint.sh /usr/local/bin/nexo-entrypoint
RUN sed -i 's/\r$//' /usr/local/bin/nexo-entrypoint && chmod 755 /usr/local/bin/nexo-entrypoint
USER www-data
ENTRYPOINT ["nexo-entrypoint"]
CMD ["php-fpm", "-F"]

FROM node:22-alpine AS control-build
WORKDIR /app
ENV NEXT_TELEMETRY_DISABLED=1
ENV NEXT_PUBLIC_API_BASE_URL=/api/v1
ENV NODE_OPTIONS=--max-old-space-size=512
COPY control-center/package.json control-center/package-lock.json ./
RUN npm ci --no-audit --no-fund
COPY control-center/ ./
RUN rm -f .env.local .env.*.local && NEXT_PUBLIC_API_BASE_URL=/api/v1 npm run build

FROM node:22-alpine AS production-control
WORKDIR /app
ENV NODE_ENV=production
ENV NEXT_TELEMETRY_DISABLED=1
ENV HOSTNAME=0.0.0.0
ENV PORT=3000
COPY --from=control-build --chown=node:node /app/.next/standalone ./
COPY --from=control-build --chown=node:node /app/.next/static ./.next/static
USER node
CMD ["node", "server.js"]

FROM nginx:1.27-alpine AS production-nginx
COPY public /var/www/html/public
COPY docker/nginx-production.conf /etc/nginx/conf.d/default.conf

FROM php:8.4-cli AS development
RUN printf 'upload_max_filesize=25M\npost_max_size=26M\n' > /usr/local/etc/php/conf.d/nexo-uploads.ini
RUN apt-get update && apt-get install -y libpq-dev libzip-dev unzip git && docker-php-ext-install pdo_pgsql pcntl && rm -rf /var/lib/apt/lists/*
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
WORKDIR /var/www/html
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts
COPY . .
RUN mkdir -p storage/framework/cache/data storage/framework/sessions storage/framework/views storage/logs && composer dump-autoload --optimize
EXPOSE 8000
CMD ["php", "artisan", "serve", "--host=0.0.0.0", "--port=8000"]
