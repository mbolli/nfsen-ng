# nfsen-ng installation

Ubuntu 20.04 LTS:

 ```bash
 # run following commands as root
 # install packages
 apt install apache2 git nfdump pkg-config php7.4 php7.4-dev libapache2-mod-php7.4 rrdtool librrd-dev
 # enable apache modules
 a2enmod rewrite deflate headers expires
 # install rrd library for php
 pecl install rrd
 # create rrd library mod entry for php
 echo "extension=rrd.so" > /etc/php/7.4/mods-available/rrd.ini
 # enable php mod
 phpenmod rrd
 # configure virtual host to read .htaccess files
 vi /etc/apache2/apache2.conf # set AllowOverride All for /var/www
 # restart apache web server
 systemctl restart apache2
 # install nfsen-ng
 cd /var/www/html # or wherever
 git clone https://github.com/mbolli/nfsen-ng
 chown -R www-data:www-data .
 chmod +x nfsen-ng/backend/cli.php
 # next step: configuration
 ```

 Ubuntu 22.04 LTS:

 ```bash
 # run following commands as root
 # install packages
 apt install apache2 git nfdump pkg-config php8.1 php8.1-dev libapache2-mod-php8.1 rrdtool librrd-dev
 # enable apache modules
 a2enmod rewrite deflate headers expires
 # install rrd library for php
 pecl install rrd
 # create rrd library mod entry for php
 echo "extension=rrd.so" > /etc/php/8.1/mods-available/rrd.ini
 # enable php mod
 phpenmod rrd
 # configure virtual host to read .htaccess files
 vi /etc/apache2/apache2.conf # set AllowOverride All for /var/www
 # restart apache web server
 systemctl restart apache2
 # install nfsen-ng
 cd /var/www/html # or wherever
 git clone https://github.com/mbolli/nfsen-ng
 chown -R www-data:www-data .
 chmod +x nfsen-ng/backend/cli.php
 # next step: configuration
 ```
 Debian 11 :

 ```bash
# run following commands as root
# install packages
apt install apache2 git nfdump pkg-config php php-dev libapache2-mod-php rrdtool librrd-dev
# enable apache modules
a2enmod rewrite deflate headers expires
# install rrd library for php
pecl install rrd
# create rrd library mod entry for php
echo "extension=rrd.so" > /etc/php/7.4/mods-available/rrd.ini
# enable php mod
phpenmod rrd
# configure virtual host to read .htaccess files
vi /etc/apache2/apache2.conf # set AllowOverride All for /var/www
# restart apache web server
systemctl restart apache2
# install nfsen-ng
cd /var/www/html # or wherever
git clone https://github.com/mbolli/nfsen-ng
chown -R www-data:www-data .
chmod +x nfsen-ng/backend/cli.php
# next step: configuration
 ```


 CentOS 7:

 ```bash
 # run following commands as root
 # update packages
 yum update
 # enable EPEL repo
 yum -y install epel-release
 # install yum utils
 yum install yum-utils
 # install remi release
 yum install http://rpms.remirepo.net/enterprise/remi-release-7.rpm
 # enable the repository for PHP 7.2
 yum-config-manager --enable remi-php72
 # install packages
 yum install git httpd mod_php nfdump php72 php72-php-devel php-devel php-pear php-pecl-rrd rrdtool rrdtool-devel
 # configure virtual host to read .htaccess files
 vim /etc/httpd/conf/httpd.conf # set AllowOverride All for /var/www/html
 # start httpd service
 systemctl start httpd
 # enable httpd service
 systemctl enable httpd
 # install nfsen-ng
 cd /var/www/html # or wherever
 git clone https://github.com/mbolli/nfsen-ng
 chown -R apache:apache .
 chmod +x nfsen-ng/backend/cli.php
 # next step: configuration
 ```
  CentOS 8:

 ```bash
 # run following commands as root
 # update packages
 dnf update
 # enable EPEL repo and update epel-release package
 dnf -y install epel-release && dnf -y update epel-release
 # install dnf-utils
 dnf -y install dnf-utils
 # enable PowerTools repo
 dnf config-manager --set-enabled PowerTools
 # install packages
 dnf -y install git httpd make mod_php nfdump php php-devel php-json php-pear rrdtool rrdtool-devel
 # install rrd library for php
 pecl install rrd
 # create rrd library mod entry for php
 echo "extension=rrd.so" > /etc/php.d/rrd.ini
 # configure virtual host to read .htaccess files
 vim /etc/httpd/conf/httpd.conf # set AllowOverride All for /var/www/html
 # start httpd service
 systemctl start httpd
 # enable httpd service
 systemctl enable httpd
 # install nfsen-ng
 cd /var/www/html # or wherever
 git clone https://github.com/mbolli/nfsen-ng
 chown -R apache:apache .
 chmod +x nfsen-ng/backend/cli.php
 # next step: configuration
 ```
