FROM php:8.4.13-cli AS runtime

# 基础依赖与构建依赖
RUN apt update && apt install -y --no-install-recommends \
    $PHPIZE_DEPS \
    libicu-dev \
    libevent-dev \
    libfreetype6-dev \
    libjpeg62-turbo-dev \
    libpng-dev \
    libpq-dev \
    libssl-dev \
    libicu70 \
    libevent-2.1-7 \
    libjpeg62-turbo \
    libpng16-16 \
    libfreetype6 \
    libpq5 \
    openssl \
    tzdata \
    bash \
    curl \
    wget \
    && rm -rf /var/lib/apt/lists/*

RUN docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        intl gd mbstring opcache sockets pdo_pgsql fileinfo opcache exif intl pdo_sqlite PDO xml json curl \

RUN pecl install redis && \
    pecl install event && \
    docker-php-ext-enable redis event && \
    rm -rf /tmp/* /var/tmp/*

RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

WORKDIR /app

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