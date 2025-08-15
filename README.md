# Lending Library Module

This Drupal custom module powers the MakeHaven Lending Library. It manages tool borrowing, returning, and issue reporting while keeping item statuses accurate and notifying members/staff via email.

## Features

- **Borrow / Withdraw Items**  
  - Sets the Library Item status to `Borrowed`  
  - Assigns the borrower and due date  
  - Sends a confirmation email to the borrower

- **Return Items**  
  - Sets the Library Item status to `Available`  
  - Clears the borrower field  
  - Sends a return confirmation email

- **Report Issues**  
  - Allows members to report `Damage`, `Missing`, or `Other` issues  
  - Automatically updates the Library Item status:  
    - `Missing` → status set to `Missing`  
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
- `field_library_borrower` (Entity reference: User)  
- `field_library_item` (Entity reference: Library Item)  
- `field_library_inspection_issues` (List: no_issues, damage, missing, other)  
- `field_library_inspection_notes` (Text)  

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
- **Loan Terms**: Edit the text in `_lending_library_send_checkout_email()` to change loan period and fees in email templates.

## Troubleshooting

- **Item status not updating** – Ensure the `#submit[]` array includes both ECK’s default handler and the module’s `_lending_library_transaction_form_submit()`.  
- **Emails not sending** – Check SMTP configuration and that the sending address is allowed by your mail provider.  
- **Access denied after submitting a form** – Usually caused when the entity is not saved (submit handler override issue). Ensure ECK’s submit handler is preserved.

## License

This module is custom-developed for MakeHaven and may be adapted for other organizations with similar needs.
