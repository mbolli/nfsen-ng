FROM php:8.3-apache

ENV TZ="Europe/Moscow"
WORKDIR /var/www/html

# Install system dependencies
RUN apt-get update && DEBIAN_FRONTEND=noninteractive apt-get install -y \
    software-properties-common \
    git \
    pkg-config \
    apache2 \
    libapache2-mod-php8.3 \
    php8.3 \
    php8.3-dev \
    php8.3-mbstring \
    rrdtool \
    librrd-dev \
    flex \
    libbz2-dev \
    yacc \
    unzip \
    wget \
    curl \
    && add-apt-repository -y ppa:ondrej/php \
    && apt-get update

# Compile and install latest nfdump
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

# Install PHP rrd extension
RUN pecl install rrd \
    && echo "extension=rrd.so" > /etc/php/8.3/mods-available/rrd.ini \
    && phpenmod rrd mbstring

# Configure Apache to allow .htaccess overrides
RUN sed -i '/<Directory \/var\/www\/>/,/<\/Directory>/ s/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

# Setup volumes for persistent data
VOLUME ["/data/nfsen", "/config/nfsen"]

# Install NFSen-NG
RUN git clone https://github.com/mbolli/nfsen-ng.git /var/www/html/nfsen-ng \
    && chmod +x /var/www/html/nfsen-ng/backend/cli.php

# Install composer and PHP dependencies
RUN curl -sS https://getcomposer.org/download/latest-stable/composer.phar -o /usr/local/bin/composer \
    && chmod +x /usr/local/bin/composer \
    && cd /var/www/html/nfsen-ng \
    && composer install --no-dev

# Auto-config script
COPY docker-entrypoint.sh /usr/local/bin/docker-entrypoint.sh
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

EXPOSE 80

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
