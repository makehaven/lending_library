# Lending Library Module

This Drupal custom module powers the MakeHaven Lending Library. It manages tool borrowing, returning, battery tracking, and issue reporting while keeping item statuses accurate and notifying members/staff via email.

## Features

- **Borrow / Withdraw Items**  
  - Sets the Library Item status to `Borrowed`  
  - Assigns the borrower and due date  
  - Associates any required batteries with the borrower  
  - Sends a confirmation email to the borrower

- **Return Items**  
  - Sets the Library Item status to `Available`  
  - Clears the borrower field  
  - Offers a checkbox to also return any associated batteries (listed as a reminder)  
  - Sends a return confirmation email  
  - Logs battery returns where supported

- **Return Batteries Independently**  
  - Admin/staff can return batteries from a view link or direct action without returning a tool  
  - Returns are logged with a message if the battery entity supports revisions

- **Report Issues**  
  - Allows members to report `Damage`, `Missing`, or `Other` issues  
  - Automatically updates the Library Item status:  
    - `Missing` → status set to `Missing` and associated batteries marked missing  
    - `Damage` / `Other` → status set to `Repair`  
    - `No issues` → status set to `Available`  
  - Sends an issue notification email to staff

- **Smart Form Behavior**  
  - Hides irrelevant fields based on the action (e.g., borrow/return/issue)  
  - Shows battery fields only if the item requires batteries  
  - Auto-sets the “Library Action” when visiting dedicated action pages (withdraw, return, issue)

- **Redirects**  
  - After borrow: redirects to `/library/borrowed`  
  - After return: redirects to `/library/borrowed`  
  - After issue report: redirects to the item’s page or `/library`

- **Configuration Defaults**  
  - `loan_terms_html` – default loan terms displayed during checkout  
  - `email_checkout_footer` – appended to checkout confirmation email  
  - `email_return_body` – default body for return confirmation email  
  - `email_issue_notice_intro` – intro text for issue report emails  
  - Defaults load on first install and can be edited at `/admin/config/services/lending-library`

## Analytics & Dashboards

- **Lending Library Insights page** – Visit `/lending-library/stats` (alias `/library/stats`; permission: `view lending library stats`) to see:
  - Live snapshot: active loans, borrowers, inventory value on loan, overdue count.
  - Last-month overview: number of loans, unique borrowers, total value borrowed, average and median loan length, repeat-borrower rate.
  - Rolling 90-day health metrics: borrowing velocity, completion counts, on-time return rate, and loan duration trends.
  - Supporting data sets: top categories (last 90 days) and a 12-month loan volume table.
- **JSON + drupalSettings feeds** – The same data is machine-readable for dashboards:
  - JSON endpoint: `/library/stats/data` (same permission as the page). Returns `current`, `periods`, `chart_data`, `retention_insights`, `batteries`, `inventory_totals`, and a flattened `snapshot` payload that makerspace_snapshots can store.
  - `drupalSettings.lendingLibraryStats` contains `snapshot` + `chartData` for Chart.js consumption inside `makerspace_dashboard`.
- **Stats Collector service** – Inject `lending_library.stats_collector` anywhere (for example, in `makerspace_snapshots`) to capture data without HTTP. This is ideal when scripting AI agents so they can reuse the same contract:

  ```php
  /** @var \Drupal\lending_library\Service\StatsCollectorInterface $stats */
  $stats = \Drupal::service('lending_library.stats_collector');
  $full = $stats->collect();              // Nested array for pages or APIs.
  $snapshot = $stats->buildSnapshotPayload($full); // Flattened values ideal for storage.
  ```

  The snapshot includes the primary KPIs (`active_loans`, `total_value_borrowed_last_month`, `on_time_return_rate_90_days`, etc.) so makerspace_snapshots can archive them on a schedule and makerspace_dashboard can plot long-term trends with Chart.js.

- **Data feed quick reference (for AI + dashboards)**:
  - Routes: `/library/stats` (primary), `/library/stats-preview` (loosely gated QA).
  - API: `/library/stats/data` (JSON, same permission as the main page).
  - Service: `lending_library.stats_collector` providing `collect()` and `buildSnapshotPayload()`.
  - Key arrays: `current`, `inventory_totals`, `periods`, `chart_data`, `batteries`, `retention_insights`, `retention_cohorts`, and `snapshot`. Always check for empty arrays when rendering.

## Requirements

- Drupal 9 or 10  
- ECK (Entity Construction Kit) module  
- SMTP module (or another mail backend)  

**Fields on Library Item content type:**
- `field_library_item_status` (List: available, borrowed, missing, repair, retired)  
- `field_library_item_borrower` (Entity reference: User)  
- `field_library_item_replacement_v` (Integer)  
- `field_library_item_uses_battery` (Boolean)  

**Fields on Library Transaction entity:**
- `field_library_action` (List: withdraw, return, issue)  
- `field_library_borrow_date` (Date)  
- `field_library_due_date` (Date)  
- `field_library_borrow_batteries` (Entity reference: Battery)  
- `field_library_borrower` (Entity reference: User)  
- `field_library_item` (Entity reference: Library Item)  
- `field_library_inspection_issues` (List: no_issues, damage, missing, other)  
- `field_library_inspection_notes` (Text)  

**Fields on Battery entity (ECK Battery Bundle):**
- `battery_status` (List: available, borrowed, missing, retired)  
- `battery_borrower` (Entity reference: User)  
- `battery_current_item` (Entity reference: Library Item)

## Installation

1. Place this module in your Drupal installation under:  
   `/modules/custom/lending_library`  
2. Clear Drupal’s cache:  
   `drush cr`  
3. Enable the module:  
   `drush en lending_library`  
4. Verify all required fields exist on the correct bundles.  
5. Configure SMTP at `/admin/config/system/smtp` and ensure **Configuration → Basic site settings → Email address** is set.

## Configuration

- **Staff Email**: The module uses the site-wide email address as the default for issue notifications. To change it, edit the constant `LENDING_LIBRARY_STAFF_EMAIL` in `lending_library.module`.  
- **Loan Terms & Email Templates**: Edit values at `/admin/config/services/lending-library`. Default content is preloaded on install as an example.  
- **Battery Return Logging**: The helper `_lending_library_battery_save_with_revision()` will log a revision message if the Battery entity type supports revisions, otherwise it performs a normal save.
- **Per-Use Fees**: Configure the per-use fee system at `/admin/config/services/lending-library`. It is disabled by default and only shows a “Borrow fee: $X” badge on the full node view.  

**Showing per-use fees in listings (Views)**
- View configuration is not exported by this repo. If you want fee badges on listing/teaser views, update `lending_library.module` to include those view modes in `lending_library_node_view()` or add a small custom field formatter that calls `lending_library.manager->getPerUseFee()` and place it in the View.

## Troubleshooting

- **Item status not updating** – Ensure the `#submit[]` array includes both ECK’s default handler and the module’s `_lending_library_transaction_form_submit()`.  
- **Emails not sending** – Check SMTP configuration and that the sending address is allowed by your mail provider.  
- **Battery return not logging** – Enable revisions for the Battery entity type in ECK if you want per-return history; otherwise the module still processes the return without a revision.  
- **Access denied after submitting a form** – Usually caused when the entity is not saved (submit handler override issue). Ensure ECK’s submit handler is preserved.


## Automated Testing with Jules

This module can be tested in an isolated environment using Jules.

### First-Time Setup

1.  In Jules, add the Git repository for this module.
2.  Navigate to the repository's **Configuration** tab.
3.  Open the `scripts/jules-setup.sh` file from this repository.
4.  Copy the **entire content** of the script.
5.  Paste it into the **“Initial Setup”** window in the Jules UI.
6.  Click **Run and Snapshot** to build the testing environment.

Once the snapshot is created, Jules will be ready to help with development and testing tasks for this module.

## Manual User Testing (One-Click Setup)

For manual, in-browser testing where you can click around and test features, you can use Gitpod. This will create a temporary, fully functional Drupal site with the Lending Library module installed. No local software is required.

[![Open in Gitpod](https://gitpod.io/button/open-in-gitpod.svg)](https://gitpod.io/#https://github.com/makehaven/lending_library)

**Instructions:**
1.  Click the "Open in Gitpod" button above.
2.  Log in with your GitHub account.
3.  Wait for the environment to build automatically (this may take a few minutes on the first launch).
4.  A new browser tab will open with the Drupal site, ready for you to test. The login is `admin` / `admin`.



## Local Development with Lando

# 1) Clone the sandbox and module side-by-side
git clone git@github.com:makehaven/drupal-sandbox.git d10
git clone git@github.com:makehaven/lending_library.git

# 2) Start Lando in the sandbox folder
cd d10
lando start

# 3) Tell Composer where the module lives and install it
lando ssh -s appserver -c 'composer -d /app/d10 config repositories.lending_library path ../lending_library'
lando ssh -s appserver -c 'composer -d /app/d10 require makehaven/lending_library:*@dev --no-update && composer -d /app/d10 install'

# 4) Enable deps you want (ECK already enabled by post-start)
lando drush -r d10/web pml --status=not-installed | grep lending_library


## License

This module is custom-developed for MakeHaven and may be adapted for other organizations with similar needs.
