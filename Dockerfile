FROM php:8.2-fpm-alpine

# 安装必要的系统包和PHP扩展
RUN apk add --no-cache \
    $PHPIZE_DEPS \
    linux-headers \
    git \
    sqlite-dev \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && docker-php-ext-install pdo_sqlite

# 安装 Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# 设置工作目录
WORKDIR /var/www/html

# 复制项目文件
COPY . .

# 安装依赖
RUN composer install --no-dev --no-interaction --prefer-dist --optimize-autoloader

# 设置权限
RUN chown -R www-data:www-data /var/www/html \
    && chmod -R 755 /var/www/html/storage

# 暴露端口
EXPOSE 8080

# 启动命令
CMD ["php", "-S", "0.0.0.0:8080", "-t", "public"]
