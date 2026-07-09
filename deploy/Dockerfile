# Production Dockerfile for nfsen-ng with Swoole
# Copies source from build context (repo root)

FROM php:8.4-cli

LABEL org.opencontainers.image.title="nfsen-ng" \
      org.opencontainers.image.description="nfsen-ng application server (PHP 8.4 + OpenSwoole + nfdump). Serves and Brotli-compresses its own static assets, so a fronting reverse proxy (nginx, Traefik, Caddy, …) only needs to handle TLS. See deploy/docker-compose.yml's bundled-Caddy profile for a batteries-included setup." \
      org.opencontainers.image.url="https://github.com/mbolli/nfsen-ng" \
      org.opencontainers.image.source="https://github.com/mbolli/nfsen-ng"

ENV TZ="UTC"
# Persistent app data lives under one volume, separate from the code, so it
# survives image upgrades and is a single thing to back up:
#   /var/lib/nfsen-ng/rrd    — RRD database (unused for the VictoriaMetrics datasource)
#   /var/lib/nfsen-ng/state  — preferences.json + alert rules/state/log
# Declared as a VOLUME below and mounted as a named volume by the compose files.
ENV NFSEN_RRD_PATH="/var/lib/nfsen-ng/rrd"
ENV NFSEN_STATE_DIR="/var/lib/nfsen-ng/state"
WORKDIR /var/www/html/nfsen-ng

# Install dependencies required for nfdump, nfsen-ng, and Swoole
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y \
    brotli pkg-config rrdtool librrd-dev \
    flex bison libbz2-dev zlib1g-dev \
    build-essential autoconf automake libtool \
    unzip wget curl \
    libssl-dev libcurl4-openssl-dev libnghttp2-dev \
    netbase bind9-host \
    procps \
    && rm -rf /var/lib/apt/lists/*

# Add nfdump to PATH
ENV PATH="/usr/local/nfdump/bin:${PATH}"

# Compile and install nfdump 1.7.8
RUN wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.8.zip \
    && unzip v1.7.8.zip \
    && cd nfdump-1.7.8 \
    && ./autogen.sh \
    && ./configure --prefix=/usr/local/nfdump \
    && make \
    && make install \
    && ldconfig \
    && nfdump -V \
    && cd .. \
    && rm -rf nfdump-1.7.8 v1.7.8.zip

# Install PHP extension installer helper
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install OpenSwoole, inotify, and brotli extensions
# brotli is required for php-via's ->withBrotli() compression support
RUN install-php-extensions openswoole inotify brotli

# Install PHP RRD extension
RUN pecl install rrd \
    && docker-php-ext-enable rrd

# Copy application source
COPY backend/ backend/
COPY frontend/ frontend/
COPY composer.json ./

# Install composer and production dependencies
RUN curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer install --no-dev --optimize-autoloader

# Setup volumes for persistent data and configuration.
# /data/nfsen-ng    — nfcapd capture tree (written by the collector, read by nfdump)
# /var/lib/nfsen-ng — nfsen-ng's own data: rrd/ (RRD database) + state/ (preferences + alerts)
RUN mkdir -p /var/lib/nfsen-ng/rrd /var/lib/nfsen-ng/state
VOLUME ["/data/nfsen-ng", "/var/lib/nfsen-ng"]

# Copy entrypoint script
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "/var/www/html/nfsen-ng/backend/app.php"]

