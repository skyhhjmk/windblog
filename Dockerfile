# syntax=docker/dockerfile:1.6

############################
# Stage 1: vendor builder  #
############################
FROM php:8.4.13-cli AS vendor

ARG MIRROR=tsinghua
ARG APP_ENV=prod
ARG APP_PORT=8787

ENV COMPOSER_ALLOW_SUPERUSER=1

WORKDIR /app

# 可选切换为清华源（默认 tsinghua；设置 MIRROR=official 则保留官方源）
RUN if [ "$MIRROR" = "tsinghua" ]; then \
      rm -f /etc/apt/sources.list && rm -rf /etc/apt/sources.list.d/* && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie main contrib non-free non-free-firmware" > /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-updates main contrib non-free non-free-firmware" >> /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-backports main contrib non-free non-free-firmware" >> /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian-security/ trixie-security main contrib non-free non-free-firmware" >> /etc/apt/sources.list ; \
    fi && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
      unzip git curl ca-certificates && \
    update-ca-certificates && \
    rm -rf /var/lib/apt/lists/*

# 安装 composer
RUN curl -sS https://getcomposer.org/installer | php -- \
      --install-dir=/usr/local/bin \
      --filename=composer \
    && composer --version

# 注意：为避免项目内 scripts 中的 support\\Plugin::install 等依赖缺失，这里直接复制全部源码
# .dockerignore 已排除了 vendor 与运行期目录，依然具有良好缓存命中
COPY . /app/

# 安装生产依赖
RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader --ignore-platform-req=ext-xsl --ignore-platform-req=ext-sockets --ignore-platform-req=ext-gd

############################
# Stage 2: runtime image   #
############################
FROM php:8.4.13-cli AS runtime

ARG MIRROR=tsinghua
ARG APP_ENV=prod
ARG APP_PORT=8787

ENV TZ=UTC \
    APP_ENV=${APP_ENV} \
    APP_PORT=${APP_PORT} \
    IN_CONTAINER=true

WORKDIR /app

# 可选切换为清华源（默认 tsinghua；设置 MIRROR=official 则保留官方源）
RUN if [ "$MIRROR" = "tsinghua" ]; then \
      rm -f /etc/apt/sources.list && rm -rf /etc/apt/sources.list.d/* && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie main contrib non-free non-free-firmware" > /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-updates main contrib non-free non-free-firmware" >> /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-backports main contrib non-free non-free-firmware" >> /etc/apt/sources.list && \
      echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian-security/ trixie-security main contrib non-free non-free-firmware" >> /etc/apt/sources.list ; \
    fi && \
    apt-get update && \
    apt-get install -y --no-install-recommends \
      zlib1g-dev \
      libfreetype6-dev \
      libjpeg62-turbo-dev \
      libpng-dev \
      libicu-dev \
      libpq-dev \
      libsqlite3-dev \
      libcurl4-openssl-dev \
      libonig-dev \
      libxml2-dev \
      libevent-dev \
      libxslt1-dev \
      libzip-dev \
      libmagickwand-dev \
      unzip \
      git \
      ca-certificates \
      openssh-client \
      $PHPIZE_DEPS \
      openssl \
      tzdata \
      bash \
      curl \
    && update-ca-certificates

# PHP 扩展
RUN docker-php-ext-configure gd --with-freetype --with-jpeg && \
    docker-php-ext-install -j"$(nproc)" \
      sockets intl gd mbstring opcache fileinfo exif xml xsl zip pcntl && \
    docker-php-ext-install -j"$(nproc)" pdo_pgsql pdo_mysql pdo_sqlite curl && \
    pecl install redis imagick && docker-php-ext-enable redis imagick && \
    rm -rf /tmp/* /var/tmp/*

# 复制应用与 vendor（vendor 来自 builder 以复用缓存）
COPY . /app/
COPY --from=vendor /app/vendor /app/vendor

# php.ini 基线 + 性能优化（JIT/OPcache/PCRE）
RUN cp "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" && \
    sed -i 's/;date.timezone =/date.timezone = UTC/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/memory_limit = 128M/memory_limit = 512M/' "$PHP_INI_DIR/php.ini" && \
    sed -i 's/;opcache.enable=1/opcache.enable=1/' "$PHP_INI_DIR/php.ini" && \
    { \
      echo 'opcache.enable=1'; \
      echo 'opcache.enable_cli=1'; \
      echo 'opcache.memory_consumption=256'; \
      echo 'opcache.interned_strings_buffer=16'; \
      echo 'opcache.max_accelerated_files=65407'; \
      echo 'opcache.validate_timestamps=0'; \
      echo 'opcache.save_comments=1'; \
      echo 'opcache.jit=tracing'; \
      echo 'opcache.jit_buffer_size=256M'; \
      echo 'pcre.jit=1'; \
    } > "$PHP_INI_DIR/conf.d/zz-opcache.ini"

# 清理 apt 缓存
RUN rm -rf /var/lib/apt/lists/*

# 非 root 运行与目录权限
# 创建 UID 1000 的用户（与 docker-compose.yml 中的 user 保持一致）
RUN groupadd -g 1000 appuser && \
    useradd -u 1000 -g appuser -m -s /bin/bash appuser && \
    mkdir -p /app/runtime /app/runtime/logs /app/public/uploads && \
    chown -R appuser:appuser /app
USER appuser

# 暴露端口（可通过 APP_PORT 自定义，默认 8787）
EXPOSE 8787

# 健康检查（使用 curl）
HEALTHCHECK --interval=30s --timeout=5s --start-period=20s --retries=3 \
    CMD curl -fsS "http://127.0.0.1:${APP_PORT}" >/dev/null || exit 1

# 前台运行 Workerman，便于容器编排管理
CMD ["php", "start.php", "start"]
