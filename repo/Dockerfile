FROM php:8.2-cli

# Install system dependencies
RUN apt-get update && apt-get install -y \
    libzip-dev \
    unzip \
    wget \
    curl \
    default-mysql-client \
    && docker-php-ext-install pdo pdo_mysql zip \
    && rm -rf /var/lib/apt/lists/*

# Install Composer
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# PHP runtime limits: 10 MB attachment cap needs ~13.3 MB after base64 overhead,
# so we size the upload/post ceilings with comfortable headroom.
RUN { \
      echo "upload_max_filesize = 16M"; \
      echo "post_max_size = 16M"; \
      echo "memory_limit = 256M"; \
    } > /usr/local/etc/php/conf.d/rural-lease.ini

WORKDIR /app

# Copy composer files and install dependencies first (layer cache)
COPY composer.json ./
RUN composer install --no-dev --no-scripts --no-autoloader 2>/dev/null || true

# Copy entire application
COPY . .

# Install all dependencies (including dev for testing)
RUN composer install --no-interaction

# Download Layui (unzip may warn about Chinese filenames, hence || true)
RUN set -e && mkdir -p public/static/layui \
    && curl -fSL -o /tmp/layui.zip "https://github.com/layui/layui/releases/download/v2.9.21/layui-v2.9.21.zip" \
    && (unzip -o /tmp/layui.zip -d /tmp/layui_tmp || true) \
    && cp -r /tmp/layui_tmp/*/layui/. public/static/layui/ \
    && ls public/static/layui/layui.js \
    && rm -rf /tmp/layui.zip /tmp/layui_tmp

# Create runtime directories
RUN mkdir -p runtime/cache runtime/log runtime/session storage/uploads storage/exports storage/keys \
    && chmod -R 777 runtime storage

# Make entrypoint executable
RUN chmod +x docker-entrypoint.sh

# Expose port
EXPOSE 8000

# Use entrypoint that runs migrations then starts server
ENTRYPOINT ["./docker-entrypoint.sh"]
