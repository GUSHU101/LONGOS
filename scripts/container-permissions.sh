#!/bin/sh
set -eu

# Keep immutable Coolify image content root-owned. Only explicit runtime
# volumes are writable by PHP; application code and deployment controls cannot
# be replaced by a web request.
cd /var/www/html

web_user="${LONGOS_WEB_USER:-www-data}"
web_group="${LONGOS_WEB_GROUP:-www-data}"
updater_enabled="${LONGOS_UPDATER_ENABLED:-}"

if [ -z "$updater_enabled" ] && [ -r .env ]; then
    updater_enabled="$(sed -n 's/^LONGOS_UPDATER_ENABLED=//p' .env | tail -n 1 | tr -d '\r"' | tr '[:upper:]' '[:lower:]')"
fi
updater_enabled="$(printf '%s' "${updater_enabled:-true}" | tr -d "\r'\"" | tr '[:upper:]' '[:lower:]')"
case "$updater_enabled" in
    1|true|yes|on) updater_enabled=true ;;
    *) updater_enabled=false ;;
esac

chown root:root .
chmod 0755 .

mkdir -p storage/framework/cache storage/framework/sessions storage/framework/views \
    storage/logs storage/app/private bootstrap/cache plugins public/cache public/plugin \
    public/image/catalog public/upload

application_roots="app beike bootstrap config database docs lang public resources routes themes vendor"
for path in $application_roots; do
    [ -e "$path" ] || continue
    if [ "$updater_enabled" = "true" ]; then
        chown -R root:"$web_group" "$path"
        find "$path" -type d -exec chmod 2775 {} +
        find "$path" -type f -exec chmod 0644 {} +
    else
        chown -R root:root "$path"
        find "$path" -type d -exec chmod 0755 {} +
        find "$path" -type f -exec chmod 0644 {} +
    fi
done

for file in composer.json composer.lock package.json package-lock.json; do
    [ -f "$file" ] || continue
    if [ "$updater_enabled" = "true" ]; then
        chown root:"$web_group" "$file"
        chmod 0664 "$file"
    else
        chown root:root "$file"
        chmod 0644 "$file"
    fi
done

# Runtime paths are the only paths owned by the PHP user. Public runtime
# folders remain non-executable at the web-server layer.
for path in storage bootstrap/cache plugins public/cache public/plugin public/image/catalog public/upload; do
    [ -e "$path" ] || continue
    chown -R "$web_user":"$web_group" "$path"
done
find storage bootstrap/cache -type d -exec chmod 0770 {} +
find storage bootstrap/cache -type f -exec chmod 0660 {} +
for path in plugins public/cache public/plugin public/image/catalog public/upload; do
    find "$path" -type d -exec chmod 0775 {} +
    find "$path" -type f -exec chmod 0664 {} +
done

if [ -f .env ]; then
    chown root:"$web_group" .env
    chmod 0640 .env
fi

for path in deploy docker scripts; do
    [ -e "$path" ] || continue
    chown -R root:root "$path"
    find "$path" -type d -exec chmod 0755 {} +
    find "$path" -type f -exec chmod 0644 {} +
done
for file in install.sh docker-compose.yml; do
    [ -f "$file" ] || continue
    chown root:root "$file"
    chmod 0644 "$file"
done
[ ! -f install.sh ] || chmod 0750 install.sh

