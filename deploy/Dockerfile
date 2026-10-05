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
#   /var/lib/nfsen-ng/rrd    RRD database (unused for the VictoriaMetrics datasource)
#   /var/lib/nfsen-ng/state  preferences.json, alert rule state and the SQLite store
#                            nfsen-ng.sqlite (saved filters, alert history, top-N data)
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

# Compile and install nfdump. 1.7.9 fixed remotely triggerable crashes in the
# collectors and file parsers; 1.7.10 fixed -b/-B pairing in gcc builds.
ARG NFDUMP_VERSION=1.7.10
RUN wget https://github.com/phaag/nfdump/archive/refs/tags/v${NFDUMP_VERSION}.zip \
    && unzip v${NFDUMP_VERSION}.zip \
    && cd nfdump-${NFDUMP_VERSION} \
    && ./autogen.sh \
    && ./configure --prefix=/usr/local/nfdump \
    && make \
    && make install \
    && ldconfig \
    && nfdump -V \
    && nfdump -V | grep -q "Version: ${NFDUMP_VERSION}-" \
    && cd .. \
    && rm -rf nfdump-${NFDUMP_VERSION} v${NFDUMP_VERSION}.zip

# Install PHP extension installer helper
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install OpenSwoole, inotify, and brotli extensions
# brotli is required for php-via's ->withBrotli() compression support.
# pdo_sqlite, which the SQLite store needs, already ships with php:8.4-cli.
RUN install-php-extensions openswoole inotify brotli

# Install PHP RRD extension. rrd 2.0.4 passes char ** where librrd 1.9.0 wants
# const char **, an error since GCC 14; trixie has 1.7.2, Debian testing 1.9.0.
RUN CFLAGS="-g -O2 -Wno-error=incompatible-pointer-types" pecl install rrd \
    && docker-php-ext-enable rrd

# One worker holds every tab, the import and the top-N collector, and a crash ends them all.
# PHP's default 128M leaves little room: a 10,000-row Flows listing alone takes the heap to about 75 MB.
RUN echo 'memory_limit = 512M' > "$PHP_INI_DIR/conf.d/nfsen-ng.ini"

# Copy application source
COPY backend/ backend/
COPY frontend/ frontend/
COPY composer.json composer.lock ./

# Install composer and production dependencies
RUN curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && composer install --no-dev --optimize-autoloader

# composer exits 0 when a locked git source is out of reach (a local checkout) and leaves the package empty.
RUN php -r 'require "vendor/autoload.php"; if (!class_exists(Mbolli\PhpVia\Via::class)) { fwrite(STDERR, "php-via is missing from vendor/: composer.lock must name a published php-via release\n"); exit(1); }'

# Setup volumes for persistent data and configuration.
# /data/nfsen-ng     nfcapd capture tree (written by the collector, read by nfdump)
# /var/lib/nfsen-ng  nfsen-ng's own data: rrd/ (RRD database) and state/ (preferences,
#                    alert rule state, nfsen-ng.sqlite)
RUN mkdir -p /var/lib/nfsen-ng/rrd /var/lib/nfsen-ng/state
VOLUME ["/data/nfsen-ng", "/var/lib/nfsen-ng"]

# Copy entrypoint script
COPY deploy/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "/var/www/html/nfsen-ng/backend/app.php"]

