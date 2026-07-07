# SPDX-FileCopyrightText: 2026 Nextcloud GmbH and Nextcloud contributors
# SPDX-License-Identifier: AGPL-3.0-or-later

# --- Build stage: install PHP dependencies ---
FROM composer:2 AS vendor
WORKDIR /app
COPY composer.json ./
RUN composer install --no-dev --no-interaction --prefer-dist --no-scripts --ignore-platform-reqs
COPY src ./src
COPY public ./public
RUN composer dump-autoload --no-dev --optimize

# --- Runtime stage ---
FROM php:8.3-cli-alpine

RUN apk add --no-cache libzip oniguruma \
	&& apk add --no-cache --virtual .build-deps libzip-dev oniguruma-dev \
	&& docker-php-ext-install -j"$(nproc)" zip mbstring \
	&& apk del .build-deps

WORKDIR /app
COPY --from=vendor /app /app

# Non-root for safety
RUN addgroup -S ssg && adduser -S ssg -G ssg
USER ssg

ENV COLLECTIVES_SSG_SECRET=""
EXPOSE 8080

# Built-in PHP server with public/index.php acting as the front controller/router.
CMD ["php", "-d", "memory_limit=512M", "-d", "upload_max_filesize=256M", "-d", "post_max_size=256M", "-S", "0.0.0.0:8080", "-t", "public", "public/index.php"]
