#!/usr/bin/env bash
set -euo pipefail

if [[ ${EUID} -ne 0 ]]; then
    echo "Run this deployment script as root." >&2
    exit 2
fi

source_root=${1:-}
target_root=/var/www/html/DialecticServer
if [[ -z ${source_root} || ${source_root} != /* || ! -f ${source_root}/main.php || ! -f ${source_root}/vsx.php ]]; then
    echo "Usage: tools/deploy-local-wsl.sh <absolute-DialecticServer-source-path>" >&2
    exit 2
fi

for command in find install rsync runuser; do
    command -v "${command}" >/dev/null || { echo "Missing required command: ${command}" >&2; exit 1; }
done
getent group www-data >/dev/null || { echo "Missing required group: www-data" >&2; exit 1; }
id -u www-data >/dev/null 2>&1 || { echo "Missing required user: www-data" >&2; exit 1; }

install -d -m 0755 /var/www/html "${target_root}"
rsync -a --delete --no-owner --no-group --chmod=D0755,F0644 \
    --exclude='/.git' \
    --exclude='/.github/' \
    --exclude='/.env' \
    --exclude='/.env.*' \
    --exclude='/conf/character_map.json' \
    --exclude='/conf/conf.php' \
    --exclude='/conf/conf_*.php' \
    --exclude='/connector/vendor/' \
    --exclude='/data/.manager.state.json' \
    --exclude='/data/CurrentModel_*.json' \
    --exclude='/data/pipeline_status.json' \
    --exclude='/data/pictures/gallery/' \
    --exclude='/data/plugin_packages/' \
    --exclude='/data/tmp/' \
    --exclude='/data/voice_sync_status.json' \
    --exclude='/data/voices/' \
    --exclude='/log/' \
    --exclude='/soundcache/' \
    --exclude='/tmp/' \
    --exclude='/ui/data/databasebackups/' \
    --exclude='/ui/data/manualbackup/' \
    --exclude='/unittests/vendor/' \
    --exclude='/uploads/' \
    "${source_root}/" "${target_root}/"
find "${target_root}/tools" "${target_root}/service" -xdev -type f -name '*.sh' -exec chmod 0755 -- {} +

# Voice samples are runtime data. Keep the directory writable by Apache after
# every root-owned source sync and preserve group ownership on new uploads.
install -d -o root -g www-data -m 2775 "${target_root}/data"
install -d -o www-data -g www-data -m 2775 "${target_root}/data/voices"
if [[ ! -f ${target_root}/data/voices/TheNarrator.wav && -f ${source_root}/data/voices/TheNarrator.wav ]]; then
    install -o www-data -g www-data -m 0664 \
        "${source_root}/data/voices/TheNarrator.wav" \
        "${target_root}/data/voices/TheNarrator.wav"
fi
find "${target_root}/data/voices" -xdev -type d -exec chown www-data:www-data -- {} + -exec chmod 2775 -- {} +
find "${target_root}/data/voices" -xdev -type f -exec chown www-data:www-data -- {} + -exec chmod 0664 -- {} +

runuser -u www-data -- bash -c '
    probe="$1/.dialectic-write-test.$$"
    umask 0002
    : > "${probe}"
    rm -f -- "${probe}"
' _ "${target_root}/data/voices"

echo "Deployed ${target_root}"
echo "Preserved local configuration, logs, caches, and voice samples."
echo "Verified Apache write access to ${target_root}/data/voices."
