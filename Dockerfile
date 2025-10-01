FROM php:8.4.13-cli AS runtime

WORKDIR /app

COPY . /app/

RUN rm -f /etc/apt/sources.list \
    && rm -rf /etc/apt/sources.list.d/* \
    && echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie main contrib non-free non-free-firmware" > /etc/apt/sources.list \
    && echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-updates main contrib non-free non-free-firmware" >> /etc/apt/sources.list \
    && echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian/ trixie-backports main contrib non-free non-free-firmware" >> /etc/apt/sources.list \
    && echo "deb https://mirrors.tuna.tsinghua.edu.cn/debian-security/ trixie-security main contrib non-free non-free-firmware" >> /etc/apt/sources.list

RUN apt-get update

# 基础依赖与构建依赖（添加了libxslt1-dev）
RUN apt-get install -y --no-install-recommends \
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
    unzip \
    git \
    ca-certificates \
    openssh-client \
    $PHPIZE_DEPS \
    openssl \
    tzdata \
    bash \
    curl \
    wget \
    && update-ca-certificates

RUN docker-php-ext-configure gd \
    --with-freetype \
    --with-jpeg

# 安装PHP扩展
RUN docker-php-ext-install -j$(nproc) \
    sockets \
    intl \
    gd \
    mbstring \
    opcache \
    fileinfo \
    exif \
    xml \
    xsl \
    zip

RUN docker-php-ext-install -j$(nproc) \
    pdo_pgsql \
    pdo_sqlite \
    curl


RUN pecl install redis && \
    docker-php-ext-enable redis&& \
    rm -rf /tmp/* /var/tmp/*

RUN curl -sS https://getcomposer.org/installer | php -- \
    --install-dir=/usr/local/bin \
    --filename=composer \
    && composer --version

RUN composer install --no-dev --prefer-dist --no-progress --no-interaction --optimize-autoloader

RUN rm -rf /var/lib/apt/lists/*

RUN #cp $PHP_INI_DIR/php.ini-production $PHP_INI_DIR/php.ini && \
    sed -i 's/;date.timezone =/date.timezone = Asia\/Shanghai/' $PHP_INI_DIR/php.ini && \
    sed -i 's/memory_limit = 128M/memory_limit = 512M/' $PHP_INI_DIR/php.ini && \
    sed -i 's/;opcache.enable=1/opcache.enable=1/' $PHP_INI_DIR/php.ini

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
