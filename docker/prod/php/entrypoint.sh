#!/bin/sh
set -e

# nginx runs as a separate container without access to this image's layers,
# so public/ (assets/, bundles/, images/) is copied into a volume shared with
# nginx on every boot. uploads/images is excluded: it's its own long-lived
# volume, mounted at the same path in both containers already.
mkdir -p public_shared
# Non-fatal: a partial sync failure on one asset (e.g. a permission/quota
# quirk on the host volume) must not crash-loop the whole app over a static
# file mirror. rsync exits non-zero on partial transfer errors (code 23).
rsync -a --delete --exclude='uploads/images' public/ public_shared/ \
    || echo "WARNING: public_shared rsync reported errors (exit $?), continuing boot"

if [ -f bin/console ]; then
    php bin/console cache:clear --env=prod --no-debug
    php bin/console cache:warmup --env=prod --no-debug
fi

# Database migrations are intentionally NOT run automatically here.
# Run them manually after each deploy:
#   docker compose -f docker-compose.prod.yml exec php php bin/console doctrine:migrations:migrate --env=prod

exec "$@"
