# syntax=docker/dockerfile:1

############################
# 1) 依赖构建阶段（含脚本）
############################
# 使用官方 composer 镜像运行 composer install，并携带完整项目以执行 post scripts
FROM composer:2 AS builder
WORKDIR /app
COPY . /app
# 可选：使用国内镜像
# RUN composer config -g repo.packagist composer https://mirrors.aliyun.com/composer/
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

############################
# 2) 运行时镜像（PHP 8.4 Alpine）
############################
FROM php:8.4-cli-alpine AS runtime

# 基础依赖与构建依赖
RUN set -eux; \
    apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libevent-dev \
        freetype-dev \
        libjpeg-turbo-dev \
        libpng-dev \
        postgresql-dev \
        openssl-dev \
    && apk add --no-cache \
        icu-libs \
        libevent \
        libjpeg-turbo \
        libpng \
        freetype \
        postgresql-libs \
        openssl \
        tzdata \
        bash \
        curl \
        wget \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        intl gd mbstring opcache sockets pdo_pgsql fileinfo opcache exif intl pdo_sqlite PDO xml json curl \
    && pecl install redis \
    && pecl install event \
    && docker-php-ext-enable redis event \
    && apk del .build-deps \
    && rm -rf /tmp/* /var/tmp/*

WORKDIR /app

# 复制应用与依赖
COPY --from=builder /app /app

# 运行环境变量
ENV TZ=Asia/Shanghai \
    APP_ENV=prod \
    APP_PORT=8787

# 暴露端口（可通过 APP_PORT 自定义，默认 8787）
EXPOSE 8787

# 健康检查（如首页可返回 200）
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD wget -qO- http://127.0.0.1:${APP_PORT} >/dev/null 2>&1 || exit 1

# 前台运行 Workerman，便于容器编排管理
CMD ["php", "start.php", "start"]