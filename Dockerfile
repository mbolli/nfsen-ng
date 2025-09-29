# Base image: PHP 8.3 + Apache
FROM php:8.3-apache

ENV TZ="UTC"
WORKDIR /var/www/html

# Install dependencies required for nfdump and NFSen-NG
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y \
    git pkg-config rrdtool librrd-dev \
    flex bison libbz2-dev zlib1g-dev \
    build-essential autoconf automake libtool \
    unzip wget curl \
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
    && nfdump -V

# Enable Apache modules
RUN a2enmod rewrite deflate headers expires

# Install PHP RRD extension (mbstring already included in base image)
RUN pecl install rrd \
    && echo "extension=rrd.so" > /usr/local/etc/php/conf.d/rrd.ini

# Setup volumes for persistent data and configuration
VOLUME ["/data/nfsen-ng", "/config/nfsen-ng"]

# Install NFSen-NG
RUN git clone https://github.com/mbolli/nfsen-ng.git /var/www/html/nfsen-ng \
    && chmod +x /var/www/html/nfsen-ng/backend/cli.php

# Install composer and backend dependencies
RUN curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && cd /var/www/html/nfsen-ng \
    && composer install --no-dev

# Copy entrypoint script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
