FROM php:8.2-fpm-alpine

# Install security updates, system dependencies, and PHP extensions
RUN apk update && apk add --no-cache \
    mysql-client \
    openssl \
    ca-certificates \
    curl \
    git \
    libsodium-dev \
    && docker-php-ext-install -j$(nproc) \
       mysqli pdo pdo_mysql opcache \
    && docker-php-ext-enable opcache

# Set working directory
WORKDIR /app

# Copy application files
COPY . .

# Set proper permissions
RUN chown -R www-data:www-data /app && \
    chmod -R 755 /app && \
    chmod -R 770 /app/uploads 2>/dev/null || true && \
    chmod 644 /app/.htaccess 2>/dev/null || true

# Configure PHP-FPM pool
RUN { \
    echo "[www]"; \
    echo "user = www-data"; \
    echo "group = www-data"; \
    echo "listen = 0.0.0.0:9000"; \
    echo "pm = dynamic"; \
    echo "pm.max_children = 50"; \
    echo "pm.start_servers = 5"; \
    echo "pm.min_spare_servers = 5"; \
    echo "pm.max_spare_servers = 35"; \
    echo "pm.process_idle_timeout = 10s"; \
    echo "pm.max_requests = 500"; \
    echo "catch_workers_output = yes"; \
    } > /usr/local/etc/php-fpm.d/www.conf

# Configure PHP security and performance
RUN { \
    echo "opcache.enable=1"; \
    echo "opcache.enable_cli=0"; \
    echo "opcache.memory_consumption=128"; \
    echo "opcache.interned_strings_buffer=8"; \
    echo "opcache.max_accelerated_files=4000"; \
    echo "opcache.revalidate_freq=60"; \
    echo "opcache.fast_shutdown=1"; \
    echo "upload_max_filesize = 10M"; \
    echo "post_max_size = 10M"; \
    echo "max_input_time = 60"; \
    echo "max_execution_time = 300"; \
    echo "display_errors = 0"; \
    echo "log_errors = 1"; \
    echo "error_log = /var/log/php-errors.log"; \
    echo "error_reporting = E_ALL & ~E_DEPRECATED"; \
    echo "session.cookie_httponly = 1"; \
    echo "session.cookie_secure = 1"; \
    echo "session.cookie_samesite = Strict"; \
    echo "session.use_strict_mode = 1"; \
    echo "session.sid_length = 32"; \
    echo "session.gc_maxlifetime = 3600"; \
    echo "session.cache_limiter = nocache"; \
    } > /usr/local/etc/php/conf.d/security.ini

# Create log directory
RUN mkdir -p /var/log && touch /var/log/php-errors.log && chmod 666 /var/log/php-errors.log

# Health check
HEALTHCHECK --interval=30s --timeout=10s --start-period=10s --retries=3 \
    CMD curl -f http://localhost:9000/ping || exit 1

EXPOSE 9000

CMD ["php-fpm"]
