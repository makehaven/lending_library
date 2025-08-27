#!/bin/bash
#
# Jules Setup Script for the Lending Library Module
#
# This script creates an isolated Drupal environment to test the module.
# To use, copy the entire contents of this file and paste it into the
# "Initial Setup" window in the Jules UI for this repository.
#

# Start the Docker environment
echo "--- Starting Docker services ---"
docker compose up -d
sleep 15

# Install Drupal core's PHP dependencies
echo "--- Installing base dependencies with Composer ---"
docker compose exec -T drupal composer install

# Install a fresh, minimal Drupal site
echo "--- Installing Drupal ---"
docker compose exec -T drupal /opt/drupal/vendor/bin/drush site:install minimal \
  --db-url="mysql://user:pass@db:3306/drupal" \
  --site-name="Lending Library Test" \
  --account-name=admin --account-pass=admin -y

# Download all module dependencies listed in lending_library.info.yml
echo "--- Downloading module dependencies ---"
docker compose exec -T drupal composer require drupal/eck

# Finally, enable the lending_library module.
# This will automatically install all the fields, entities, and settings
# from its /config/install directory.
echo "--- Enabling the Lending Library module ---"
docker compose exec -T drupal /opt/drupal/vendor/bin/drush en lending_library -y

echo "--- Setup complete! ---"