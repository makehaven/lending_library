#!/bin/bash
# Provision Jules (Docker + Drupal 10) and install the Lending Library module from the repo.
set -e

echo "--- 1) docker-compose.yml (AWS ECR mirrors to avoid Hub limits) ---"
cat <<'EOF' > docker-compose.yml
services:
  db:
    image: public.ecr.aws/docker/library/postgres:15-alpine
    environment:
      POSTGRES_DB: drupal
      POSTGRES_USER: drupal
      POSTGRES_PASSWORD: password
    volumes:
      - db_data:/var/lib/postgresql/data

  drupal:
    image: public.ecr.aws/docker/library/drupal:10-fpm-alpine
    depends_on:
      - db
    volumes:
      - ./d10:/var/www/html              # project docroot (host)
      - .:/var/www/module_source         # module source (host)
volumes:
  db_data:
EOF

echo "--- 2) Start services ---"
mkdir -p d10
sudo docker compose up -d

echo "--- 3) Install Composer + pdo_pgsql + create Drupal 10 project (as root) ---"
sudo docker compose exec -T --user root drupal sh -lc '
  set -e
  apk add --no-cache curl git unzip postgresql-dev $PHPIZE_DEPS
  docker-php-ext-install pdo_pgsql
  docker-php-ext-enable pdo_pgsql
  curl -sS https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer
  mkdir -p /var/www/.composer && chown -R www-data:www-data /var/www/.composer
  # PIN to Drupal 10
  COMPOSER_HOME=/var/www/.composer composer create-project "drupal/recommended-project:^10" /var/www/html --no-interaction --ignore-platform-reqs
  chown -R www-data:www-data /var/www/html
'

echo "--- 4) Wait for DB ---"
sleep 30

echo "--- 5) Locate module + configure Composer path repo ---"
INFO_FILE_PATH=$(sudo docker compose exec -T drupal sh -lc \
  'find /var/www/module_source -type f -name "lending_library.info.yml" -print -quit')

if [ -z "$INFO_FILE_PATH" ]; then
  echo "Error: lending_library.info.yml not found." >&2
  exit 1
fi

MODULE_PATH=$(sudo docker compose exec -T drupal sh -lc 'dirname "$1"' _ "$INFO_FILE_PATH")
echo "Module path: $MODULE_PATH"

# Allow git to use the mounted directory
sudo docker compose exec -T drupal sh -lc 'git config --global --add safe.directory /var/www/module_source'

# Register path repo
sudo docker compose exec -T drupal sh -lc \
  'composer config --file=/var/www/html/composer.json repositories.lending_library \
   "{\"type\":\"path\",\"url\":\"'"$MODULE_PATH"'\"}"'

echo "--- 6) Install Drush ---"
sudo docker compose exec -T drupal sh -lc \
  'COMPOSER_HOME=/var/www/.composer composer require drush/drush:^13 --working-dir=/var/www/html --no-interaction'

echo "--- 7) Require module (and deps) ---"
sudo docker compose exec -T drupal sh -lc \
  'COMPOSER_HOME=/var/www/.composer composer require -W makehaven/lending_library:*@dev --working-dir=/var/www/html --no-interaction'

echo "--- 8) Install Drupal via Drush ---"
sudo docker compose exec -T drupal sh -lc \
  '/var/www/html/vendor/bin/drush site:install standard \
    --db-url=pgsql://drupal:password@db:5432/drupal \
    --site-name="Lending Library Test" \
    --account-name=admin \
    --account-pass=admin -y'

echo "--- 9) Enable module ---"
sudo docker compose exec -T drupal sh -lc \
  '/var/www/html/vendor/bin/drush en lending_library -y'

echo "--- 10) Fix permissions (as root) ---"
sudo docker compose exec -T --user root drupal sh -lc \
  'chown -R www-data:www-data /var/www/html/web/sites/default'
