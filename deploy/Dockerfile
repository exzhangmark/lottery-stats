FROM php:8.2-fpm

# 安装 PDO_SQLite 扩展
RUN docker-php-ext-install pdo_sqlite

WORKDIR /www/lottery
COPY . .

# 安装 Workerman（composer）
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer
RUN composer install --no-dev -o 2>/dev/null || true

EXPOSE 9000
CMD ["php-fpm"]
