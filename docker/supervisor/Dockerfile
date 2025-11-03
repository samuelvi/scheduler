FROM php:8.4-cli-alpine

# Install system dependencies including supervisor
RUN apk add --no-cache \
    bash \
    curl \
    git \
    unzip \
    postgresql-dev \
    icu-dev \
    zip \
    libzip-dev \
    supervisor

# Install PHP extensions
RUN docker-php-ext-install \
    pdo \
    pdo_pgsql \
    intl \
    zip \
    opcache

# Install Composer
COPY --from=composer:latest /usr/bin/composer /usr/bin/composer

# Set working directory
WORKDIR /var/www/html

# Create log directory for supervisor
RUN mkdir -p /var/log/supervisor && \
    mkdir -p /var/run && \
    chmod 755 /var/log/supervisor

# Start supervisor
CMD ["/usr/bin/supervisord", "-c", "/etc/supervisor/conf.d/supervisor.conf"]
