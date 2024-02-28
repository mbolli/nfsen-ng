# nfsen-ng installation

These instructions install nfsen-ng on a fresh Ubuntu 20.04/22.04 LTS or Debian 11/12 system.

**Note that setup of nfcapd is not covered here, but nfsen-ng requires data captured by nfcapd to work.**


## Ubuntu 20.04/22.04 LTS

 ```bash
# run following commands as root

# add php repository
add-apt-repository -y ppa:ondrej/php

# install packages
apt install apache2 git pkg-config php8.3 php8.3-dev php8.3-mbstring libapache2-mod-php8.3 rrdtool librrd-dev

# compile nfdump (optional, if you want to use the most recent version)
apt install flex libbz2-dev yacc unzip
wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.4.zip
unzip v1.7.4.zip
cd nfdump-1.7.4/
./autogen.sh
./configure
make
make install
ldconfig
nfdump -V

# enable apache modules
a2enmod rewrite deflate headers expires

# install rrd library for php
pecl install rrd

# create rrd library mod entry for php
echo "extension=rrd.so" > /etc/php/8.3/mods-available/rrd.ini

# enable php mods
phpenmod rrd mbstring

# configure virtual host to read .htaccess files
vi /etc/apache2/apache2.conf # set AllowOverride All for /var/www directory

# restart apache web server
systemctl restart apache2

# install nfsen-ng
cd /var/www # or wherever, needs to be in the web root
git clone https://github.com/mbolli/nfsen-ng
chown -R www-data:www-data .
chmod +x nfsen-ng/backend/cli.php

cd nfsen-ng
# install composer with instructions from https://getcomposer.org/download/
php composer.phar install --no-dev

# next step: create configuration file from backend/settings/settings.php.dist
```

## Debian 11/12

```bash
# run following commands as root

# add php repository
apt install -y apt-transport-https lsb-release ca-certificates wget
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list
apt update

# install packages
apt install apache2 git pkg-config php8.3 php8.3-dev php8.3-mbstring libapache2-mod-php8.3 rrdtool librrd-dev

# compile nfdump (optional, if you want to use the most recent version)
apt install flex libbz2-dev yacc unzip
wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.4.zip
unzip v1.7.4.zip
cd nfdump-1.7.4/
./autogen.sh
./configure
make
make install
ldconfig
nfdump -V

# enable apache modules
a2enmod rewrite deflate headers expires

# install rrd library for php
pecl install rrd

# create rrd library mod entry for php
echo "extension=rrd.so" > /etc/php/8.3/mods-available/rrd.ini

# enable php mods
phpenmod rrd mbstring

# configure virtual host to read .htaccess files
vi /etc/apache2/apache2.conf # set AllowOverride All for /var/www

# restart apache web server
systemctl restart apache2

# install nfsen-ng
cd /var/www # or wherever
git clone https://github.com/mbolli/nfsen-ng
chown -R www-data:www-data .
chmod +x nfsen-ng/backend/cli.php
cd nfsen-ng

# install composer with instructions from https://getcomposer.org/download/
php composer.phar install --no-dev

# next step: create configuration file from backend/settings/settings.php.dist
```
