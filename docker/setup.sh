#!/bin/sh
set -eu

cd "$(dirname "$0")/.."

if [ ! -f .env ]; then
  cp .env.docker.example .env

  random_hex() {
    if command -v openssl >/dev/null 2>&1; then
      openssl rand -hex 24
    else
      od -An -N24 -tx1 /dev/urandom | tr -d ' \n'
    fi
  }

  MYSQL_PASSWORD="$(random_hex)"
  MYSQL_ROOT_PASSWORD="$(random_hex)"
  REDIS_PASSWORD="$(random_hex)"

  # Values are hex, so no escaping is required here.
  sed -i "s/^MYSQL_PASSWORD=.*/MYSQL_PASSWORD=${MYSQL_PASSWORD}/" .env
  sed -i "s/^MYSQL_ROOT_PASSWORD=.*/MYSQL_ROOT_PASSWORD=${MYSQL_ROOT_PASSWORD}/" .env
  sed -i "s/^REDIS_PASSWORD=.*/REDIS_PASSWORD=${REDIS_PASSWORD}/" .env
  chmod 600 .env
  echo "Created .env with random MySQL/Redis passwords."
else
  echo ".env already exists; leaving it unchanged."
fi

mkdir -p data/config data/runtime data/public-storage data/mysql data/redis

echo "Starting MPAY..."
docker compose up -d --build

echo
echo "MPAY is listening on http://127.0.0.1:${MPAY_PORT:-8787}"
echo "Open it through your reverse-proxy domain and complete /install."
echo "Installer Docker hosts: MySQL=mysql, Redis=redis"
