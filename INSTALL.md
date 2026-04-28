# nfsen-ng installation

## Option A: Docker (recommended)

Docker is the easiest way to get nfsen-ng running. See [QUICKSTART.md](./QUICKSTART.md) for the fastest path and [DOCKER_SETUP.md](./docs/DOCKER_SETUP.md) for dev vs production details.

Prerequisites: Docker, Docker Compose, and nfcapd writing files to `/var/nfdump/profiles-data`.

```bash
git clone https://github.com/mbolli/nfsen-ng
cd nfsen-ng
cp backend/settings/settings.php.dist backend/settings/settings.php
# Edit settings.php with your sources and ports
docker-compose up -d
```

Visit **http://localhost** (production) or **http://localhost:8080** (dev).

---

## Option B: Bare-metal install (Ubuntu 22.04/24.04 LTS or Debian 11/12)

**Note: setup of nfcapd is not covered here, but nfsen-ng requires data captured by nfcapd to work.**

```bash
# run following commands as root

# add php repository (Ubuntu)
add-apt-repository -y ppa:ondrej/php

# OR for Debian:
apt install -y apt-transport-https lsb-release ca-certificates curl gpg
echo "deb https://packages.sury.org/php/ $(lsb_release -sc) main" | tee /etc/apt/sources.list.d/php.list
curl -fsSL https://packages.sury.org/php/apt.gpg | gpg --dearmor | tee /etc/apt/trusted.gpg.d/sury-php.gpg > /dev/null
apt update

# install packages
apt install git pkg-config php8.4 php8.4-dev php8.4-mbstring rrdtool librrd-dev

# compile nfdump (optional, if you want the most recent version)
apt install flex libbz2-dev bison unzip wget
wget https://github.com/phaag/nfdump/archive/refs/tags/v1.7.6.zip
unzip v1.7.6.zip && cd nfdump-1.7.6
./autogen.sh && ./configure && make && make install && ldconfig
nfdump -V
cd ..

# install OpenSwoole PHP extension
pecl install openswoole
echo "extension=openswoole.so" > /etc/php/8.4/mods-available/openswoole.ini

# install inotify PHP extension (for file watching)
pecl install inotify
echo "extension=inotify.so" > /etc/php/8.4/mods-available/inotify.ini

# install rrd PHP extension
pecl install rrd
echo "extension=rrd.so" > /etc/php/8.4/mods-available/rrd.ini

# enable PHP extensions
phpenmod openswoole inotify rrd mbstring

# install nfsen-ng
cd /var/www
git clone https://github.com/mbolli/nfsen-ng
chown -R www-data:www-data nfsen-ng

cd nfsen-ng
# install Composer: https://getcomposer.org/download/
php composer.phar install --no-dev

# copy and edit settings
cp backend/settings/settings.php.dist backend/settings/settings.php
# vi backend/settings/settings.php

# start the HTTP server (listens on port 9000)
# app.php runs a gap-fill on startup; use Admin panel → Initial Import for a fresh install.
sudo -u www-data php backend/app.php
```

For TLS and compression, put Caddy in front of the OpenSwoole server. See [HTTP_COMPRESSION.md](./docs/HTTP_COMPRESSION.md) and the provided `Caddyfile` for configuration.

For running the server as a systemd service, see the unit files in `deploy/systemd/` and [deploy/systemd/README.md](./deploy/systemd/README.md).
