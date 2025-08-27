#!/usr/bin/env bash
set -euo pipefail

echo "=== Lando post-start: begin ==="

# Quiet mail during install
echo 'sendmail_path = /bin/true' > /usr/local/etc/php/conf.d/mail.ini || true

# Create Composer-based Drupal (in /app/d10) if missing
if [ ! -f /app/d10/composer.json ]; then
  composer create-project drupal/recommended-project:^10.3 /app/d10
fi

# Require Drush + ECK (only)
composer -d /app/d10 require drush/drush:^13 drupal/eck:^2 -W

# Keep a clean copy of JUST the module (avoid recursion)
mkdir -p /app/_module_src
rsync -a --delete \
  --exclude 'd10/' \
  --exclude '.git/' \
  --exclude 'vendor/' \
  /app/ /app/_module_src/

# Make sure .info.yml declares Drupal 10/11 compatibility
if [ -f /app/_module_src/lending_library.info.yml ] && ! grep -q '^core_version_requirement:' /app/_module_src/lending_library.info.yml; then
  echo 'core_version_requirement: ^10 || ^11' >> /app/_module_src/lending_library.info.yml
fi

# Link the clean module copy (NOT enabling it)
mkdir -p /app/d10/web/modules/custom
ln -sfn /app/_module_src /app/d10/web/modules/custom/lending_library

# Install Drupal once (admin/admin)
if ! /app/d10/vendor/bin/drush -r /app/d10/web status --fields=bootstrap 2>/dev/null | grep -q 'Successful'; then
  /app/d10/vendor/bin/drush -r /app/d10/web si standard \
    --db-url="mysql://drupal10:drupal10@database:3306/drupal10" \
    --site-name="Lending Library Dev" \
    --account-name=admin --account-pass=admin -y
fi

# Enable ECK only
/app/d10/vendor/bin/drush -r /app/d10/web en eck -y

# Verbose errors + Twig debug (idempotent)
if ! grep -q "system.logging" /app/d10/web/sites/default/settings.php 2>/dev/null; then
  cat >> /app/d10/web/sites/default/settings.php <<'PHP'
$config['system.logging']['error_level'] = 'verbose';
$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);
error_reporting(E_ALL);
$settings['rebuild_access'] = TRUE;
PHP
  cat > /app/d10/web/sites/development.services.yml <<'YML'
parameters:
  http.response.debug_cacheability_headers: true
  twig.config:
    debug: true
    auto_reload: true
    cache: false
YML
fi

# Clear caches
/app/d10/vendor/bin/drush -r /app/d10/web cr

echo "=== Lando post-start: done ==="
