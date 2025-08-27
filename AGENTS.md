# Lending Library Module Agents

This file describes the commands used for setting up and testing the Lending Library module in an isolated Drupal environment.

## Docker Agent
- **Purpose**: Manages the containerized testing environment.
- **Command**: `docker compose <sub-command>`
- **Usage**:
  - `docker compose up -d`: Starts the Drupal and database containers.
  - `docker compose exec -T drupal <command>`: Executes a command inside the running Drupal container.

## Composer Agent
- **Purpose**: Manages PHP dependencies for Drupal core and contributed modules.
- **Command**: `docker compose exec -T drupal composer <sub-command>`
- **Usage**:
  - `composer require drupal/eck`: Downloads a Drupal module like ECK.

## Drush Agent
- **Purpose**: A command-line tool for managing the Drupal site.
- **Command**: `docker compose exec -T drupal /opt/drupal/vendor/bin/drush <sub-command>`
- **Usage**:
  - `drush site:install`: Installs a fresh Drupal site.
  - `drush en lending_library -y`: Enables the Lending Library module and installs its configuration.