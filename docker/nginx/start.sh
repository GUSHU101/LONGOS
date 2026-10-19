#!/bin/sh
set -eu

cd /var/www/html

role="${1:-web}"

case "$role" in
  web|worker|scheduler) ;;
  *)
    echo "Unsupported LONGOS container role: $role" >&2
    exit 64
    ;;
esac

ensure_runtime_tree() {
  prepare_runtime_directories

  # Each directory shipped by the image is release-owned. Replace those
  # directories as complete units so deleted/renamed PHP and browser assets
  # cannot survive an upgrade in the persistent plugin volumes. Directories
  # installed later through the marketplace are not present in the release
  # manifest and remain untouched.
  sync_release_tree /opt/longos-seed/plugins plugins
  sync_release_tree /opt/longos-seed/public-plugin public/plugin

  if [ ! -f .env ]; then
    cp .env.example .env
  fi
}

prepare_runtime_directories() {
  mkdir -p \
    storage/app/private \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/logs \
    bootstrap/cache \
    public/cache \
    public/image/catalog \
    public/plugin \
    public/upload
}

sync_release_tree() {
  seed_root="$1"
  target_root="$2"
  manifest="$target_root/.longos-release-manifest"
  next_manifest="$target_root/.longos-release-manifest.new"

  : > "$next_manifest"
  for source in "$seed_root"/*; do
    [ -e "$source" ] || [ -L "$source" ] || continue
    name="$(basename "$source")"
    case "$name" in
      ''|.|..|*[!A-Za-z0-9._-]*)
        echo "Unsafe release-owned plugin entry: $name" >&2
        rm -f "$next_manifest"
        return 1
        ;;
    esac

    printf '%s\n' "$name" >> "$next_manifest"
    staged="$target_root/.${name}.longos-new"
    previous="$target_root/.${name}.longos-old"

    # Recover the only old copy if the container was killed after moving the
    # live directory aside but before publishing the prepared replacement.
    rm -rf "$staged"
    if { [ ! -e "$target_root/$name" ] && [ ! -L "$target_root/$name" ]; } \
      && { [ -e "$previous" ] || [ -L "$previous" ]; }; then
      mv "$previous" "$target_root/$name"
    fi
    rm -rf "$previous"
    cp -a "$source" "$staged"

    if [ -e "$target_root/$name" ] || [ -L "$target_root/$name" ]; then
      mv "$target_root/$name" "$previous"
    fi
    if ! mv "$staged" "$target_root/$name"; then
      [ ! -e "$previous" ] && [ ! -L "$previous" ] || mv "$previous" "$target_root/$name"
      return 1
    fi
    rm -rf "$previous"
  done

  # Remove only entries that an earlier LONGOS image explicitly declared as
  # release-owned. Validate every persisted name before using it as a path.
  if [ -f "$manifest" ]; then
    while IFS= read -r old_name; do
      case "$old_name" in
        ''|.|..|*[!A-Za-z0-9._-]*) continue ;;
      esac
      if ! grep -Fqx -- "$old_name" "$next_manifest"; then
        rm -rf "$target_root/$old_name"
      fi
    done < "$manifest"
  fi

  mv "$next_manifest" "$manifest"
}

wait_for_database() {
  attempt=0
  until php -r '
    $host = getenv("DB_HOST") ?: "mysql";
    $port = getenv("DB_PORT") ?: "3306";
    $name = getenv("DB_DATABASE") ?: "longos";
    $user = getenv("DB_USERNAME") ?: "longos";
    $pass = getenv("DB_PASSWORD") ?: "";
    try {
        new PDO("mysql:host={$host};port={$port};dbname={$name}", $user, $pass, [PDO::ATTR_TIMEOUT => 3]);
    } catch (Throwable) {
        exit(1);
    }
  ' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "${LONGOS_DATABASE_WAIT_ATTEMPTS:-60}" ]; then
      echo "LONGOS database did not become ready in time." >&2
      exit 1
    fi
    sleep 2
  done
}

refresh_package_manifest() {
  # bootstrap/cache is container-local. Never bake a developer machine's
  # package/config cache into the image, and generate the package manifest for
  # every process role before it boots Laravel as www-data.
  rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
  php artisan package:discover --ansi
}

initialize_or_upgrade() {
  : "${DOMAIN:?DOMAIN is required}"
  : "${SAAS_BASE_DOMAIN:?SAAS_BASE_DOMAIN is required}"
  : "${DB_PASSWORD:?DB_PASSWORD is required}"

  wait_for_database

  refresh_package_manifest

  if [ ! -f storage/installed ]; then
    : "${ADMIN_EMAIL:?ADMIN_EMAIL is required for the first deployment}"
    : "${ADMIN_PASSWORD:?ADMIN_PASSWORD is required for the first deployment}"

    printf '%s\0%s\0' "$DB_PASSWORD" "$ADMIN_PASSWORD" | \
      php artisan longos:install \
        --domain="https://${DOMAIN}" \
        --base-domain="$SAAS_BASE_DOMAIN" \
        --database-mode="${SAAS_DATABASE_MODE:-prefix}" \
        --db-host="${DB_HOST:-mysql}" \
        --db-port="${DB_PORT:-3306}" \
        --db-name="${DB_DATABASE:-longos}" \
        --db-user="${DB_USERNAME:-longos}" \
        --admin-name="${ADMIN_NAME:-admin}" \
        --admin-email="$ADMIN_EMAIL" \
        --secrets-stdin \
        --force
  else
    php artisan saas:upgrade --no-maintenance --if-needed
  fi

  php artisan optimize
}

wait_for_application() {
  attempt=0
  until [ -f storage/installed ] && \
    curl -fsS -o /dev/null -H "Host: ${DOMAIN}" http://app/api/health 2>/dev/null; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge "${LONGOS_APPLICATION_WAIT_ATTEMPTS:-120}" ]; then
      echo "LONGOS web service did not become healthy in time." >&2
      exit 1
    fi
    sleep 3
  done
}

run_web_processes() {
  php-fpm -F &
  php_pid=$!
  nginx -g "daemon off;" &
  nginx_pid=$!

  shutdown_web_processes() {
    trap - TERM INT
    kill -TERM "$nginx_pid" "$php_pid" 2>/dev/null || true
    wait "$nginx_pid" 2>/dev/null || true
    wait "$php_pid" 2>/dev/null || true
  }

  trap 'shutdown_web_processes; exit 0' TERM INT
  while kill -0 "$php_pid" 2>/dev/null && kill -0 "$nginx_pid" 2>/dev/null; do
    sleep 2
  done

  echo "LONGOS web process exited unexpectedly; terminating its peer." >&2
  shutdown_web_processes
  exit 1
}

case "$role" in
  web)
    # Only the web container owns release seeding and permission repair. The
    # same shared lock serializes file synchronization and schema upgrades
    # across Coolify rolling replacements. Worker and scheduler wait for web
    # health before touching the persistent volumes.
    prepare_runtime_directories
    exec 9>storage/framework/longos-deploy.lock
    flock -x 9
    ensure_runtime_tree
    initialize_or_upgrade
    # Artisan runs as root during the privileged bootstrap so it can repair a
    # fresh or older volume. Normalize ownership only after every installer,
    # migration, cache and log file has been created, immediately before the
    # unprivileged PHP-FPM workers begin serving requests.
    sh scripts/container-permissions.sh
    flock -u 9
    run_web_processes
    ;;
  worker)
    wait_for_application
    refresh_package_manifest
    exec su -s /bin/sh www-data -c \
      'php artisan queue:work --sleep=3 --tries=5 --backoff=30 --timeout=3300 --max-time=3600'
    ;;
  scheduler)
    wait_for_application
    refresh_package_manifest
    exec su -s /bin/sh www-data -c 'php artisan schedule:work'
    ;;
esac

