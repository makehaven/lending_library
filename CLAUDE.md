# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Module Overview

Drupal 10/11 custom module for MakeHaven's tool lending system. Manages tool borrowing/returning, battery tracking, waitlists, fines, and analytics. Compatible with standalone development or as part of the main MakeHaven platform.

## Development Commands

```bash
# Standalone development (module's own Lando)
lando start                     # Starts isolated Drupal environment
lando drush cr                  # Clear cache
lando drush en lending_library  # Enable module

# Inside main MakeHaven platform (parent Lando)
lando drush cr
lando drush cex                 # Export config after field changes

# Run tests
lando phpunit web/modules/custom/lending_library/tests/src/Kernel/
lando phpunit web/modules/custom/lending_library/tests/src/Functional/

# Code standards
lando sh -c "vendor/bin/phpcs --standard=Drupal web/modules/custom/lending_library"
```

## Architecture

### Entity Model

**Library Item** (`node:library_item`) - Physical tool available for loan
- Status field: available, borrowed, missing, repair, retired
- `field_library_item_borrower` - Current holder
- `field_library_item_uses_battery` - Determines battery UI visibility

**Library Transaction** (`eck:library_transaction`) - Borrow/return/issue event record
- `field_library_action`: withdraw, return, issue, renew
- Links borrower, item, dates, and batteries
- Tracks charges and notification states

**Battery** (`eck:battery`) - Rechargeable battery inventory
- Status tracked on entity fields (Phase 1)
- Future: `battery_transaction` entity for full history (see `BATTERY_MANAGEMENT_PLAN.md`)

### Key Services

| Service | Purpose |
|---------|---------|
| `lending_library.manager` | Core checkout/return/renew logic, email dispatch |
| `lending_library.stats_collector` | Analytics data for dashboards and JSON API |
| `lending_library.tool_status_updater` | Syncs item status from transaction events |

### Route Structure

| Path Pattern | Purpose |
|--------------|---------|
| `/library-item/{node}/withdraw` | Checkout form |
| `/library-item/{node}/return` | Return form |
| `/library-item/{node}/renew` | Renewal action |
| `/library-item/{node}/report-issue` | Issue reporting |
| `/library/stats` | Analytics dashboard |
| `/library/stats/data` | JSON API for stats |
| `/library/manager` | Admin dashboard |
| `/admin/config/makehaven/lending-library` | Settings form |

### Stats Integration

External modules (e.g., `makerspace_snapshots`, `makerspace_dashboard`) can consume lending data:

```php
$stats = \Drupal::service('lending_library.stats_collector');
$data = $stats->collect();                     // Full nested array
$snapshot = $stats->buildSnapshotPayload($data); // Flattened KPIs
```

## Key Files

- `lending_library.module` - Form alters, entity hooks, cron (overdue notifications/fees)
- `src/Controller/LibraryTransactionController.php` - Borrow/return/renew page logic
- `src/Controller/BatteryReturnController.php` - Standalone battery returns
- `src/Service/LendingLibraryManager.php` - Business logic and email dispatch
- `src/Service/StatsCollector.php` - Analytics data builder

## Testing Environments

**Jules** - Automated CI testing using `scripts/jules-setup.sh`

**Gitpod** - One-click browser testing (login: admin/admin)

**Docker** - `docker compose up -d` then exec commands into `drupal` container

## Module Conventions

- Follow Drupal coding standards (PSR-4, 2-space YAML indentation)
- Field machine names prefixed with `field_library_` (transactions) or `field_library_item_` (nodes)
- Entity types use ECK; bundles defined in `config/install/eck.eck_type.*.yml`
- Permissions in `lending_library.permissions.yml`
- Email templates configurable at `/admin/config/makehaven/lending-library`

## Critical Notes

1. **Battery takeover logic** - Selecting an already-borrowed battery reassigns it and logs a takeover note
2. **Return closes all open transactions** - Returns search for both Withdraw and Renew transactions to prevent duplicate "Borrowed" entries
3. **Stats permissions** - JSON endpoint requires `view lending library stats` permission
4. **Charts integration** - Uses Drupal Charts with Chart.js; requires `chart_xaxis` child for slice labels (see parent `CLAUDE.md`)
