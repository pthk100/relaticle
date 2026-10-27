# syntax=docker/dockerfile:1

###########################################
# Stage 1: Composer dependencies
###########################################
FROM composer:2 AS composer

WORKDIR /app

COPY composer.json composer.lock ./
RUN composer install \
    --no-dev \
    --no-interaction \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --ignore-platform-reqs

###########################################
# Stage 2: Build frontend assets
###########################################
FROM node:26-alpine AS frontend

WORKDIR /app

# The DEV050 build host blackholes registry.npmjs.org (curl to it returns 000 /
# times out, while registry.npmmirror.com answers in ~0.1s), so an unqualified
# install hangs until npm gives up. Overridable, so a host with normal egress can
# point at the canonical registry:
#   docker build --build-arg NPM_REGISTRY=https://registry.npmjs.org .
ARG NPM_REGISTRY=https://registry.npmmirror.com

# Corepack is gone from Node 25+, and letting pnpm fetch its own pinned version
# needs glibc, so install the exact version package.json already pins.
COPY package.json pnpm-lock.yaml pnpm-workspace.yaml ./
RUN npm install -g --registry=$NPM_REGISTRY "$(node -p 'require("./package.json").packageManager')" \
    && pnpm install --frozen-lockfile --ignore-scripts --registry=$NPM_REGISTRY

# Copy source files needed for build
COPY vite.config.js ./
COPY resources ./resources
COPY public ./public
COPY packages ./packages

# Copy vendor for Filament theme CSS
COPY --from=composer /app/vendor ./vendor

RUN pnpm run build

###########################################
# Stage 3: Production image
###########################################
# Pinned from serversideup/php:8.5-fpm-nginx, resolved 2026-10-27. Minimum acceptable is 8.5.12, the PHP2Shell fix (CVE-2026-109618/109619).
# Re-resolve this digest when PHP 8.5 ships a security fix; a digest never moves on its own, a floating tag moves backwards silently.
FROM serversideup/php@sha256:7b3b42fb8b9bd4b5225d51585d039d19fe01fe421f3d94676c8c2e5a3369b103 AS production

LABEL org.opencontainers.image.title="Relaticle CRM"
LABEL org.opencontainers.image.description="Modern, open-source CRM platform"
LABEL org.opencontainers.image.source="https://github.com/Relaticle/relaticle"

# Switch to root to install dependencies
USER root

# Install required PHP extensions
RUN install-php-extensions intl exif gd imagick bcmath

# Install PostgreSQL client for health checks
RUN apt-get update \
    && apt-get install -y --no-install-recommends postgresql-client \
    && apt-get clean \
    && rm -rf /var/lib/apt/lists/*

# Switch back to www-data
USER www-data

WORKDIR /var/www/html

# Copy application source
COPY --chown=www-data:www-data . .

# Copy vendor from composer stage
COPY --chown=www-data:www-data --from=composer /app/vendor ./vendor

# Copy built frontend assets
COPY --chown=www-data:www-data --from=frontend /app/public/build ./public/build

# Generate optimized autoloader
RUN composer dump-autoload --optimize --no-dev

# Create storage directories
RUN mkdir -p \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache

# Default environment for serversideup/php Laravel automations
ENV AUTORUN_ENABLED=true
ENV AUTORUN_LARAVEL_STORAGE_LINK=true
ENV AUTORUN_LARAVEL_MIGRATION=true
ENV AUTORUN_LARAVEL_MIGRATION_ISOLATION=true
ENV AUTORUN_LARAVEL_CONFIG_CACHE=true
ENV AUTORUN_LARAVEL_ROUTE_CACHE=true
ENV AUTORUN_LARAVEL_VIEW_CACHE=true
ENV AUTORUN_LARAVEL_EVENT_CACHE=true
ENV AUTORUN_LARAVEL_OPTIMIZE=false
ENV PHP_OPCACHE_ENABLE=1
ENV SSL_MODE=off

EXPOSE 8080
