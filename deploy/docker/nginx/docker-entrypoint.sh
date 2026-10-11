#!/bin/sh
set -eu

# Official nginx image runs scripts in /docker-entrypoint.d/ before nginx starts.
CONF_DIR=/etc/nginx/conf.d
SRC=/etc/growth-planet/nginx

mkdir -p "$CONF_DIR"
rm -f "$CONF_DIR/default.conf"

if [ -f /etc/nginx/certs/fullchain.pem ] && [ -f /etc/nginx/certs/privkey.pem ]; then
  echo "TLS certificates found; enabling HTTPS (HTTP -> HTTPS redirect)."
  cp "$SRC/prod-ssl.conf" "$CONF_DIR/default.conf"
else
  echo "No TLS certificates at /etc/nginx/certs; serving HTTP only."
  cp "$SRC/prod.conf" "$CONF_DIR/default.conf"
fi
