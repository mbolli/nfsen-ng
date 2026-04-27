# Production Dockerfile for nfsen-ng with Swoole
# Clones code from git and installs production dependencies

FROM php:8.4-cli

ENV TZ="UTC"
WORKDIR /var/www/html

# Install dependencies required for nfdump, nfsen-ng, and Swoole
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git brotli pkg-config rrdtool librrd-dev \
    flex bison libbz2-dev zlib1g-dev \
    build-essential autoconf automake libtool \
    unzip wget curl \
    libssl-dev libcurl4-openssl-dev libnghttp2-dev \
    netbase bind9-host \
    && rm -rf /var/lib/apt/lists/*

# Add nfdump to PATH
ENV PATH="/usr/local/nfdump/bin:${PATH}"

# Compile and install nfdump 1.7.6
RUN wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.6.zip \
    && unzip v1.7.6.zip \
    && cd nfdump-1.7.6 \
    && ./autogen.sh \
    && ./configure --prefix=/usr/local/nfdump \
    && make \
    && make install \
    && ldconfig \
    && nfdump -V \
    && cd .. \
    && rm -rf nfdump-1.7.6 v1.7.6.zip

# Install PHP extension installer helper
ADD --chmod=0755 https://github.com/mlocati/docker-php-extension-installer/releases/latest/download/install-php-extensions /usr/local/bin/

# Install OpenSwoole, inotify, and brotli extensions
# brotli is required for php-via's ->withBrotli() compression support
RUN install-php-extensions openswoole inotify brotli

# Install PHP RRD extension
RUN pecl install rrd \
    && docker-php-ext-enable rrd

# Setup volumes for persistent data and configuration
VOLUME ["/data/nfsen-ng"]

# Clone nfsen-ng from git (production) - v1 branch
RUN git clone -b v1 https://github.com/mbolli/nfsen-ng.git /var/www/html/nfsen-ng \
    && chmod +x /var/www/html/nfsen-ng/backend/cli.php

# Install composer and production dependencies
RUN curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && cd /var/www/html/nfsen-ng \
    && composer install --no-dev --optimize-autoloader

# Copy entrypoint script
COPY docker/docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 9000

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["php", "/var/www/html/nfsen-ng/backend/app.php"]

