# nfsen-ng
[![GitHub license](https://img.shields.io/github/license/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/issues)
[![Donate a beer](https://img.shields.io/badge/paypal-donate-yellow.svg?style=flat-square)](https://paypal.me/bolli)

nfsen-ng is an in-place replacement for the ageing nfsen.

<div><img src="https://github.com/mbolli/nfsen-ng/assets/722725/b3e6e8a5-185c-4347-9d43-d4aa9b09f35e" style="width: 100%" /></div>

**Used components**

 * Front end: [jQuery](https://jquery.com), [dygraphs](http://dygraphs.com), [FooTable](http://fooplugins.github.io/FooTable/), [ion.rangeSlider](http://ionden.com/a/plugins/ion.rangeSlider/en.html)
 * Back end: [RRDtool](http://oss.oetiker.ch/rrdtool/), [nfdump tools](https://github.com/phaag/nfdump)

## TOC

   * [nfsen-ng](#nfsen-ng)
      * [Installation](#installation)
      * [Configuration](#configuration)
      * [CLI](#cli)
      * [API](#api)
         * [/api/config](#apiconfig)
         * [/api/graph](#apigraph)
         * [/api/flows](#apiflows)
         * [/api/stats](#apistats)

## Installation

We try to support a decent range of Linux distributions. Additional installation instructions are available in [INSTALL.md](./INSTALL.md). Pull requests for additional distributions are welcome.

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
 # install composer via https://getcomposer.org/download/
 composer install --no-dev # bootstrap autoloading
 # next step: configuration
 ```

## Configuration

> *Note:* nfsen-ng expects the profiles-data folder structure to be `PROFILES_DATA_PATH/PROFILE/SOURCE/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`, e.g. `/var/nfdump/profiles_data/live/source1/2018/12/01/nfcapd.201812010225`.

The default settings file is `backend/settings/settings.php.dist`. Copy it to `backend/settings/settings.php` and start modifying it. Example values are in *italic*:

 * **general**
    * **ports:** (_array(80, 23, 22, ...)_) The ports to examine. _Note:_ If you use RRD as datasource and want to import existing data, you might keep the number of ports to a minimum, or the import time will be measured in moon cycles...
    * **sources:** (_array('source1', ...)_) The sources to scan.
    * **db:** (_RRD_) The name of the datasource class (case-sensitive).
 * **frontend**
    * **reload_interval:** Interval in seconds between graph reloads.
 * **nfdump**
    * **binary:** (_/usr/bin/nfdump_) The location of your nfdump executable
    * **profiles-data:** (_/var/nfdump/profiles_data_) The location of your nfcapd files
    * **profile:** (_live_) The profile folder to use
    * **max-processes:** (_1_) The maximum number of concurrently running nfdump processes. _Note:_ Statistics and aggregations can use lots of system resources, even to aggregate one week of data might take more than 15 minutes. Put this value to > 1 if you want nfsen-ng to be usable while running another query.
 * **db** If the used data source needs additional configuration, you can specify it here, e.g. host and port.
 * **log**
    * **priority:** (_LOG_INFO_) see other possible values at [http://php.net/manual/en/function.syslog.php]

## CLI

The command line interface is used to initially scan existing nfcapd.* files, or to administer the daemon.

Usage:

  `./cli.php [ options ] import`

or for the daemon

  `./cli.php start|stop|status`


 * **Options:**
     * **-v**  Show verbose output
     * **-p**  Import ports data as well _Note:_ Using RRD this will take quite a bit longer, depending on the number of your defined ports.
     * **-ps**  Import ports per source as well _Note:_ Using RRD this will take quite a bit longer, depending on the number of your defined ports.
     * **-f**  Force overwriting database and start fresh

 * **Commands:**
     * **import** Import existing nfdump data to nfsen-ng. _Note:_ If you have existing nfcapd files, better do this overnight.
     * **start** Start the daemon for continuous reading of new data
     * **stop** Stop the daemon
     * **status** Get the daemon's status

 * **Examples:**
     * `./cli.php -f import`
        Imports fresh data for sources

     * `./cli.php -f -p -ps import`
        Imports all data

     * `./cli.php start`
        Starts the daemon


## API
The API is used by the frontend to retrieve data. The API endpoints are documented in [API_ENDPOINTS.md](./API_ENDPOINTS.md).
