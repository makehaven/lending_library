#!/bin/bash
# scripts/jules-setup.sh

set -euo pipefail

echo "--- Starting Docker services ---"
sudo docker compose up -d
sleep 15

echo "--- Install Composer in container ---"
sudo docker compose exec -T drupal bash -lc 'apt-get update && apt-get install -y curl git unzip mariadb-client && curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer && composer --version'

echo "--- Create Drupal project ---"
sudo docker compose exec -T drupal bash -lc 'mkdir -p /opt/drupal && [ -f /opt/drupal/composer.json ] || composer create-project drupal/recommended-project:^10.3 /opt/drupal'

echo "--- Point Apache docroot at /opt/drupal/web ---"
sudo docker compose exec -T drupal bash -lc 'rm -rf /var/www/html && ln -s /opt/drupal/web /var/www/html'

echo "--- Symlink this repo as a custom module ---"
sudo docker compose exec -T drupal bash -lc 'mkdir -p /opt/drupal/web/modules/custom && ln -sfn /opt/project /opt/drupal/web/modules/custom/lending_library'

echo "--- Require Drush and deps ---"
sudo docker compose exec -T drupal bash -lc 'cd /opt/drupal && composer require drush/drush:^13 drupal/eck drupal/field_permissions drupal/field_group -W'

echo "--- Install Drupal site ---"
sudo docker compose exec -T drupal bash -lc "/opt/drupal/vendor/bin/drush sql-drop -y && /opt/drupal/vendor/bin/drush si standard \
  --db-url='mysql://drupal10:drupal10@db:3306/drupal10' \
  --site-name='Lending Library Test' \
  --account-name=admin --account-pass=admin -y"

echo "--- Enable dependencies ---"
sudo docker compose exec -T drupal bash -lc '/opt/drupal/vendor/bin/drush en -y eck field_permissions field_group comment'

echo "--- Enable custom module ---"
sudo docker compose exec -T drupal bash -lc '/opt/drupal/vendor/bin/drush en lending_library -y'

echo "--- Done ---"
