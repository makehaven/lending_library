#!/usr/bin/env bash
set -euo pipefail

echo "=== Lando post-start: begin ==="

# Quiet mail during install (prevents installer warnings)
echo 'sendmail_path = /bin/true' > /usr/local/etc/php/conf.d/mail.ini || true

# Create Composer-based Drupal (in /app/d10) if missing
if [ ! -f /app/d10/composer.json ]; then
  composer create-project drupal/recommended-project:^10.3 /app/d10
fi

# Require Drush + ECK (site-level; minimal for now)
composer -d /app/d10 require drush/drush:^13 drupal/eck:^2 -W

# Keep a clean copy of JUST the module (avoid recursion into /app/d10)
mkdir -p /app/_module_src
rsync -a --delete \
  --exclude 'd10/' \
  --exclude '.git/' \
  --exclude 'vendor/' \
  /app/ /app/_module_src/

# Link the clean module copy (module stays disabled by default)
mkdir -p /app/d10/web/modules/custom
ln -sfn /app/_module_src /app/d10/web/modules/custom/lending_library

# Install Drupal once (admin/admin) if not already installed
if ! /app/d10/vendor/bin/drush -r /app/d10/web status --fields=bootstrap 2>/dev/null | grep -q 'Successful'; then
  /app/d10/vendor/bin/drush -r /app/d10/web si standard \
    --db-url="mysql://drupal10:drupal10@database:3306/drupal10" \
    --site-name="Lending Library Dev" \
    --account-name=admin --account-pass=admin -y
fi

# Enable ECK only (your module remains uninstalled)
/app/d10/vendor/bin/drush -r /app/d10/web en eck -y || true
# Ensure lending_library is NOT enabled on persisted DBs
/app/d10/vendor/bin/drush -r /app/d10/web pmu lending_library -y || true


# Verbose errors + Twig debug (idempotent, handle read-only settings.php)
SETTINGS=/app/d10/web/sites/default/settings.php

if [ -f "$SETTINGS" ] && ! grep -q "system.logging" "$SETTINGS" 2>/dev/null; then
  chmod u+w "$SETTINGS" || true
  cat >> "$SETTINGS" <<'PHP'
$config['system.logging']['error_level'] = 'verbose';
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);
error_reporting(E_ALL);
$settings['rebuild_access'] = TRUE;
PHP
  chmod 444 "$SETTINGS" || true
fi

# Always (re)write dev services (safe)
cat > /app/d10/web/sites/development.services.yml <<'YML'
parameters:
  http.response.debug_cacheability_headers: true
  twig.config:
    debug: true
    auto_reload: true
    cache: false
YML


# Clear caches
/app/d10/vendor/bin/drush -r /app/d10/web cr

echo "=== Lando post-start: done ==="
