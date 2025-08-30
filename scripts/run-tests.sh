#!/bin/sh
#
# Run tests for the lending_library module.
#
# This script is intended to be run from the root of the repository, inside the
# Docker container.

set -e

# The path to the Drupal root.
DRUPAL_ROOT="/var/www/html"

# The path to the phpunit executable.
PHPUNIT="$DRUPAL_ROOT/vendor/bin/phpunit"

# The path to the tests directory.
TESTS_DIR="/var/www/module_source/tests"

# Run the tests.
$PHPUNIT -c "$DRUPAL_ROOT/web/core/phpunit.xml.dist" "$TESTS_DIR"
