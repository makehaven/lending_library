# Lending Library Module Context

## Project Overview
The **Lending Library** is a custom Drupal 10/11 module designed for MakeHaven. It facilitates the borrowing and returning of tools and batteries, tracks inventory status (Available, Borrowed, Missing, Repair), and manages user notifications and fines.

**Core Functionality:**
*   **Tool Borrowing:** Users withdraw items (`library_item` nodes), creating a `library_transaction` entity.
*   **Battery Management:** Batteries are tracked as separate ECK entities (`battery`), often associated with tool transactions.
*   **Returns:** Handles item returns, calculates late fees, and allows independent battery returns.
*   **Issues:** Users can report damage or missing items, triggering staff notifications.
*   **Stats:** Provides dashboards for loan activity and inventory health.

## Architecture & Key Components

### Entities
*   **Library Item (`node` type `library_item`):** The physical tool available for rent.
*   **Library Transaction (`eck` entity `library_transaction`):** Records the event of borrowing/returning.
    *   Tracks: Borrower, Item, Dates (Borrow/Due/Return), Action (Withdraw/Return/Issue/Renew).
*   **Battery (`eck` entity `battery`):** Represents a rechargeable battery.
    *   Current Status: Tracked via fields on the entity itself (migrating to transaction-based in Phase 2).

### Key Files
*   `lending_library.module`: Contains the core business logic, including:
    *   Form alterations for checkout/return flows.
    *   Entity hooks (`lending_library_entity_insert`) to update item status upon transaction creation.
    *   Cron hook (`lending_library_cron`) for overdue notifications and fee calculation.
*   `src/Controller/LibraryTransactionController.php`: Handles the logic for the Borrow, Return, and Renew pages.
*   `src/Controller/BatteryReturnController.php`: Handles standalone battery returns.
*   `BATTERY_MANAGEMENT_PLAN.md`: Outlines the roadmap for refactoring battery tracking to a transaction-based model.

### Configuration
*   **Permissions:** Defined in `lending_library.permissions.yml`.
*   **Routes:** Defined in `lending_library.routing.yml`.
*   **Settings:** Configurable via `/admin/config/makehaven/lending-library`.

## Development Environment

This project is configured to run with **Lando**.

### Common Commands
*   `lando start`: Start the development environment.
*   `lando ssh`: SSH into the app server.
*   `lando drush cr`: Clear Drupal cache (essential after code changes).
*   `lando drush en lending_library`: Enable the module.

### Scripts (`/scripts`)
*   `lando-post-start.sh`: Automates environment setup after Lando starts.
*   `jules-setup.sh`: Setup script for the Jules testing environment.

## Recent Changes & Known Issues
*   **Battery Selection:** The checkout form now allows selecting *any* battery (even if marked borrowed). Logic exists to "take over" a battery if it was previously checked out to another user.
*   **Return Logic:** The return process now searches for and closes *all* open transactions (Withdraw + Renew) for an item, preventing duplicate "Borrowed" entries.
*   **Battery Plan:** A plan exists (`BATTERY_MANAGEMENT_PLAN.md`) to move batteries to a full transaction history model.

## Conventions
*   **Coding Style:** Follow Drupal coding standards (PSR-4, 2-space indentation).
*   **Testing:** Use the provided Jules or Gitpod environments for functional testing.
