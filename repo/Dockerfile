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

# Layui is vendored in public/static/layui/ (no internet required at build time).
# Verify the asset is present.
RUN ls public/static/layui/layui.js

# Create runtime directories
RUN mkdir -p runtime/cache runtime/log runtime/session storage/uploads storage/exports storage/keys \
    && chmod -R 777 runtime storage

# Strip Windows CRLF line endings from all shell scripts (if any) and make executable.
# .gitattributes enforces LF at checkout, but this is a belt-and-suspenders guard
# for machines that bypass gitattributes or use tarball exports.
RUN find . -name '*.sh' -exec sed -i 's/\r$//' {} + \
    && chmod +x docker-entrypoint.sh run_tests.sh

# Expose port
EXPOSE 8000

# Use absolute entrypoint that runs migrations then starts server
ENTRYPOINT ["/app/docker-entrypoint.sh"]
