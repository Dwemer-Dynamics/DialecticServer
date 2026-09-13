#!/bin/sh
set -eu
umask 077
trap 'exit 0' TERM INT
while :; do
    if php /opt/dialectic-relay/public/index.php --clean > /dev/null; then
        touch "$DIALECTIC_RELAY_STORAGE/cleanup.ok"
    else
        echo '[public-relay] Scheduled cleanup failed; retrying next minute' >&2
    fi
    sleep 60 &
    wait $! || true
done
