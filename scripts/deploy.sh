#!/usr/bin/env bash
set -euo pipefail
export COMPOSER_ALLOW_SUPERUSER=1
export PATH="/usr/local/bin:/usr/bin:/bin:$PATH"

ROOT=/www/wwwroot/takafol/backend
git config --global --add safe.directory "$ROOT" 2>/dev/null || true
cd "$ROOT"

git fetch origin main
git reset --hard origin/main

composer install --no-dev --optimize-autoloader --no-interaction --prefer-dist

php artisan migrate --force
php artisan db:seed --class=ProductionSeeder --force
php artisan storage:link --force || true
php artisan config:cache
php artisan route:cache
php artisan view:cache

chown -R www:www storage bootstrap/cache
if [ -d public/storage ]; then
  chown -R www:www public/storage
fi

systemctl restart takafol-queue

echo "takafol backend deployed"
