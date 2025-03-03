FROM php:8.3-apache 

ENV TZ="Europe/Moscow"
WORKDIR /var/www/html

COPY . /var/www/html/

RUN apt-get update && apt-get -y install rrdtool librrd-dev && \
    pecl install rrd && \
    docker-php-ext-enable rrd && \
    a2enmod rewrite deflate headers expires && \
    curl -o composer.phar https://getcomposer.org/download/2.8.3/composer.phar && \
    php composer.phar install --no-dev

RUN sed -ri -e 's!AllowOverride None!AllowOverride All!' /etc/apache2/apache2.conf

CMD ["sh", "-c", "php ./backend/cli.php start && apache2-foreground"]
