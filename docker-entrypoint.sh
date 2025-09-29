#!/bin/bash
set -e

# If settings.php does not exist in config volume, copy default
if [ ! -f /config/nfsen-ng/settings.php ]; then
    echo "No settings.php found in /config/nfsen-ng. Copying default..."
    cp /var/www/html/nfsen-ng/backend/settings/settings.php.dist /config/nfsen-ng/settings.php
fi

# Link settings.php to nfsen-ng directory
ln -sf /config/nfsen-ng/settings.php /var/www/html/nfsen-ng/backend/settings/settings.php

# Ensure correct ownership
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /data/nfsen-ng
chown -R www-data:www-data /config/nfsen-ng

# Execute container command
exec "$@"
