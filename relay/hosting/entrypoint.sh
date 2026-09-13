#!/bin/sh
set -eu
umask 077
case "${PORT:-}" in ''|*[!0-9]*) echo 'Invalid relay port' >&2; exit 1;; esac
test "$PORT" -ge 1 && test "$PORT" -le 65535
# The volume mount is operator-controlled; never recursively alter its contents.
test "$DIALECTIC_RELAY_STORAGE" = /var/lib/dialectic-relay
test ! -L "$DIALECTIC_RELAY_STORAGE"
mkdir -p "$DIALECTIC_RELAY_STORAGE"
chown www-data:www-data "$DIALECTIC_RELAY_STORAGE"
chmod 0700 "$DIALECTIC_RELAY_STORAGE"
sed "s/__PORT__/$PORT/g" /etc/nginx/nginx.conf.template > /etc/nginx/nginx.conf
nginx -t
exec supervisord -c /etc/supervisord.conf
