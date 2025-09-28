#!/bin/bash
set -e

# Check if config exists, if not copy default
if [ ! -f /config/nfsen/settings.php ]; then
    echo "No settings.php found in /config/nfsen. Copying default..."
    cp /var/www/html/nfsen-ng/backend/settings/settings.php.dist /config/nfsen/settings.php
fi

# Link settings.php from config volume to nfsen-ng directory
ln -sf /config/nfsen/settings.php /var/www/html/nfsen-ng/backend/settings/settings.php

# Ensure permissions
chown -R www-data:www-data /var/www/html
chown -R www-data:www-data /data/nfsen
chown -R www-data:www-data /config/nfsen

# Execute main container command
exec "$@"
