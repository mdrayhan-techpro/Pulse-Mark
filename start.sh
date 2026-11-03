#!/bin/bash
set -e

# Render provides the web service port in the PORT environment variable
PORT="${PORT:-80}"

echo "Starting Apache on port $PORT..."

# Update Apache port configuration dynamically
sed -i "s/Listen 80/Listen ${PORT}/g" /etc/apache2/ports.conf
sed -i "s/<VirtualHost \*:80>/<VirtualHost \*:${PORT}>/g" /etc/apache2/sites-available/000-default.conf

# Run Apache in foreground
exec apache2-foreground
