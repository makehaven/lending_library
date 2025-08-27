#!/usr/bin/env bash
set -euo pipefail

echo "--- Start containers ---"
docker compose up -d
sleep 15

echo "--- Install tools + Composer ---"
docker compose exec -T drupal bash -lc 'apt-get update && apt-get install -y curl git unzip default-mysql-client'
docker compose exec -T drupal bash -lc 'printf "sendmail_path = /bin/true\n" > /usr/local/etc/php/conf.d/mail.ini'
docker compose exec -T drupal bash -lc 'curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer'

echo "--- Create Composer-based Drupal project ---"
docker compose exec -T drupal bash -lc 'mkdir -p /opt/drupal && [ -f /opt/drupal/composer.json ] || composer create-project drupal/recommended-project:^10.3 /opt/drupal'
docker compose exec -T drupal bash -lc 'rm -rf /var/www/html && ln -sfn /opt/drupal/web /var/www/html'

echo "--- Require Drush + ECK (only) ---"
docker compose exec -T drupal bash -lc 'cd /opt/drupal && composer require drush/drush:^13 drupal/eck:^2 -W'

echo "--- Install Drupal ---"
docker compose exec -T drupal bash -lc "/opt/drupal/vendor/bin/drush si standard \
  --db-url='mysql://user:pass@db:3306/drupal' \
  --site-name='Gitpod Drupal' \
  --account-name=admin --account-pass=admin -y"

echo "--- Enable ECK only (no lending_library) ---"
docker compose exec -T drupal bash -lc '/opt/drupal/vendor/bin/drush en eck -y'

# Make the module visible in Drupal (do not enable it)
docker compose exec -T drupal bash -lc '
mkdir -p /opt/drupal/web/modules/custom
ln -sfn /opt/project /opt/drupal/web/modules/custom/lending_library
test -f /opt/project/lending_library.info.yml || echo "WARNING: lending_library.info.yml not found (module may not appear)."
/opt/drupal/vendor/bin/drush cr
/opt/drupal/vendor/bin/drush pml --status=not-installed | grep lending_library || true
'


echo "--- Verbose error output ---"
docker compose exec -T drupal bash -lc "cat <<'PHP' >> /opt/drupal/web/sites/default/settings.php
\$config['system.logging']['error_level'] = 'verbose';
\$settings['container_yamls'][] = DRUPAL_ROOT . '/sites/development.services.yml';
ini_set('display_errors', TRUE);
ini_set('display_startup_errors', TRUE);
error_reporting(E_ALL);
\$settings['rebuild_access'] = TRUE;
PHP"
docker compose exec -T drupal bash -lc "cat <<'YML' > /opt/drupal/web/sites/development.services.yml
parameters:
  http.response.debug_cacheability_headers: true
  twig.config:
    debug: true
    auto_reload: true
    cache: false
YML"

echo "--- Clear caches ---"
docker compose exec -T drupal bash -lc '/opt/drupal/vendor/bin/drush cr'

echo "--- Done ---"
