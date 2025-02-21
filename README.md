# nfsen-ng

[![GitHub license](https://img.shields.io/github/license/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/blob/master/LICENSE)
[![GitHub issues](https://img.shields.io/github/issues/mbolli/nfsen-ng.svg?style=flat-square)](https://github.com/mbolli/nfsen-ng/issues)
[![Donate a beer](https://img.shields.io/badge/paypal-donate-yellow.svg?style=flat-square)](https://paypal.me/bolli)

nfsen-ng is an in-place replacement for the ageing nfsen.

![nfsen-ng dashboard overview](https://github.com/mbolli/nfsen-ng/assets/722725/c3df942e-3d3c-4ef9-86ad-4e5780c7b6d8)

## Used components

* Front end: [jQuery](https://jquery.com), [dygraphs](http://dygraphs.com), [FooTable](http://fooplugins.github.io/FooTable/), [ion.rangeSlider](http://ionden.com/a/plugins/ion.rangeSlider/en.html)
* Back end: [RRDtool](http://oss.oetiker.ch/rrdtool/), [nfdump tools](https://github.com/phaag/nfdump)

## TOC

* [nfsen-ng](#nfsen-ng)
  * [Installation](#installation)
  * [Configuration](#configuration)
    * [Nfdump](#nfdump)
  * [CLI/Daemon](#cli--daemon)
    * [Daemon as a systemd service](#daemon-as-a-systemd-service)
  * [Logs](#logs)
  * [API](#api)

## Installation

Detailed installation instructions are available in [INSTALL.md](./INSTALL.md). Pull requests for additional distributions are welcome.

Software packages required:

* nfdump
* rrdtool
* git
* composer
* apache2
* php >= 8.1

Apache modules required:

* mod_rewrite
* mod_deflate
* mod_headers
* mod_expires

PHP modules required:

* mbstring
* rrd

## Configuration

> *Note:* nfsen-ng expects the `profiles_data` folder structure to be `PROFILES_DATA_PATH/PROFILE/SOURCE/YYYY/MM/DD/nfcapd.YYYYMMDDHHII`, e.g. `/var/nfdump/profiles_data/live/source1/2018/12/01/nfcapd.201812010225`.

The default settings file is `backend/settings/settings.php.dist`. Copy it to `backend/settings/settings.php` and start modifying it. Example values are in *italic*:

* **general**
  * **ports:** (*array(80, 23, 22, ...)*) The ports to examine. *Note:* If you use RRD as datasource and want to import existing data, you might keep the number of ports to a minimum, or the import time will be measured in moon cycles...
    * **sources:** (*array('source1', ...)*) The sources to scan.
    * **db:** (*RRD*) The name of the datasource class (case-sensitive).
  * **frontend**
    * **reload_interval:** Interval in seconds between graph reloads.
  * **nfdump**
    * **binary:** (*/usr/bin/nfdump*) The location of your nfdump executable
    * **profiles-data:** (*/var/nfdump/profiles_data*) The location of your nfcapd files
    * **profile:** (*live*) The profile folder to use
    * **max-processes:** (*1*) The maximum number of concurrently running nfdump processes. *Note:* Statistics and aggregations can use lots of system resources, even to aggregate one week of data might take more than 15 minutes. Put this value to > 1 if you want nfsen-ng to be usable while running another query.
  * **db** If the used data source needs additional configuration, you can specify it here, e.g. host and port.
  * **log**
    * **priority:** (*LOG_INFO*) see other possible values at [http://php.net/manual/en/function.syslog.php]

### Nfdump

Nfsen-ng uses nfdump to read the nfcapd files. You can specify the location of the nfdump binary in `backend/settings/settings.php`. The default location is `/usr/bin/nfdump`.

You should also have a look at the nfdump configuration file `/etc/nfdump.conf` and make sure that the `nfcapd` files are written to the correct location. The default location is `/var/nfdump/profiles_data`.

Hhere is an example of an nfdump configuration:

```ini
options='-z -S 1 -T all -l /var/nfdump/profiles_data/live/<source> -p <port>'
```

where

* `-z` is used to compress the nfcapd files
* `-S 1` is used to specify the nfcapd directory structure
* `-T all` is used to specify the extension of the nfcapd files
* `-l` is used to specify the destination location of the nfcapd files
* `-p` is used to specify the port of the nfcapd files.

#### Nfcapd x Sfcapd

To use sfcapd instead of nfcapd, you have to change the `nfdump` configuration file `/lib/systemd/system/nfdump@.service` to use `sfcapd` instead of `nfcapd`:

```ini
[Unit]
Description=netflow capture daemon, %I instance
Documentation=man:sfcapd(1)
After=network.target auditd.service
PartOf=nfdump.service

[Service]
Type=forking
EnvironmentFile=/etc/nfdump/%I.conf
ExecStart=/usr/bin/sfcapd -D -P /run/sfcapd.%I.pid $options
PIDFile=/run/sfcapd.%I.pid
KillMode=process
Restart=no

[Install]
WantedBy=multi-user.target
```

## CLI + Daemon

The command line interface is used to initially scan existing nfcapd.* files, or to administer the daemon.

Usage:

  `./cli.php [ options ] import`

or for the daemon

  `./cli.php start|stop|status`

* **Options:**
  * **-v**  Show verbose output
  * **-p**  Import ports data as well *Note:* Using RRD this will take quite a bit longer, depending on the number of your defined ports.
  * **-ps**  Import ports per source as well *Note:* Using RRD this will take quite a bit longer, depending on the number of your defined ports.
  * **-f**  Force overwriting database and start fresh

  * **Commands:**
    * **import** Import existing nfdump data to nfsen-ng. *Note:* If you have existing nfcapd files, better do this overnight or over a week-end.
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

### Daemon as a systemd service

You can use the daemon as a service. To do so, you can use the provided systemd service file below. You can copy it to `/etc/systemd/system/nfsen-ng.service` and then start it with `systemctl start nfsen-ng`.

```ini
[Unit]
Description=nfsen-ng
After=network-online.target

[Service]
Type=simple
RemainAfterExit=yes
restart=always
startLimitIntervalSec=0
restartSec=2
ExecStart=su - www-data --shell=/bin/bash -c '/var/www/html/nfsen-ng/backend/cli.php start'
ExecStop=su - www-data --shell=/bin/bash -c '/var/www/html/nfsen-ng/backend/cli.php stop'

[Install]
WantedBy=multi-user.target
```

Now, you should reload and enable the service to start on boot with `systemctl daemon-reload` and `systemctl enable nfsen-ng`.

## Logs

Nfsen-ng logs to syslog. You can find the logs in `/var/log/syslog` or `/var/log/messages` depending on your system. Some distributions might register it in `journalctl`. To access the logs, you can use `tail -f /var/log/syslog` or `journalctl -u nfsen-ng`

You can change the log priority in `backend/settings/settings.php`.

## API

The API is used by the frontend to retrieve data. The API endpoints are documented in [API_ENDPOINTS.md](./API_ENDPOINTS.md).
