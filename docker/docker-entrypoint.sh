#!/bin/sh
set -eu

CONFIG_DIR="${MPAY_CONFIG_DIR:-/data/config}"
APP_ENV_FILE="${CONFIG_DIR}/app.env"

mkdir -p \
  "${CONFIG_DIR}" \
  /app/runtime/logs \
  /app/runtime/cache \
  /app/runtime/storage/private \
  /app/public/storage/uploads

# The web installer writes /app/.env. Keep it outside the immutable image so
# configuration survives docker compose up --build and container replacement.
if [ ! -e "${APP_ENV_FILE}" ]; then
  : > "${APP_ENV_FILE}"
  chmod 600 "${APP_ENV_FILE}"
fi

if [ -L /app/.env ] || [ ! -e /app/.env ]; then
  rm -f /app/.env
  ln -s "${APP_ENV_FILE}" /app/.env
fi

# Bind mounts are often created as root on first boot. Normalize ownership
# before dropping privileges to the unprivileged webman user.
chown -R www-data:www-data "${CONFIG_DIR}" /app/runtime /app/public/storage

if [ "$(id -u)" = "0" ]; then
  exec gosu www-data "$@"
fi

exec "$@"
