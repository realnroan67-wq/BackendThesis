#!/bin/sh
set -eu
umask 027

# Fail closed for this initial staging artifact, including job command overrides.
if [ "${APP_ENV:-}" != production ] || [ "${APP_DEBUG:-}" != false ]; then
    echo 'Staging requires APP_ENV=production and APP_DEBUG=false.' >&2
    exit 1
fi
if [ "${REGISTRATION_ENABLED:-false}" != false ] || [ "${INQUIRY_SUBMISSION_ENABLED:-false}" != false ]; then
    echo 'Both release gates must remain false for initial staging.' >&2
    exit 1
fi
if [ -z "${APP_KEY:-}" ]; then
    echo 'Inject a staging-only APP_KEY at runtime.' >&2
    exit 1
fi

# Build only after secret injection. No key generation, database commands or mail.
php artisan config:cache --no-interaction
php artisan package:discover --no-interaction
exec "$@"
