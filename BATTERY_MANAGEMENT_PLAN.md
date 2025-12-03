# Battery Management Architecture Plan

## Current State
- **Entity:** `battery` (ECK Entity)
- **Tracking:** Status is a field (`field_battery_status`) on the battery entity itself.
- **History:** Handled via Entity Revisions.
- **Association:** `library_transaction` entities (which track Tools) have a reference field to batteries.
- **Limitation:** There is no independent transaction log for batteries. If a battery is swapped, returned early, or lost, it is difficult to reconstruct the history without parsing revision logs.

## Addressed Issues (Immediate Fixes)
1.  **Availability:** The checkout form previously filtered batteries to show only "Available" ones.
    -   *Fix:* The filter has been removed. Users can now select *any* battery.
    -   *Logic:* If a user selects a battery that is currently marked "Borrowed" (by someone else), the system now detects this, logs a "Takeover" note, and re-assigns it to the new user/tool. This prevents the "phantom unavailable" issue.

## Proposed Future Architecture (Phase 2)

To robustly handle battery inventory, we recommend moving to a **Transaction-Based** model for batteries, similar to Tools.

### 1. Battery Transaction Entity
Create a new ECK Entity Type or Bundle: `battery_transaction`.
- **Fields:**
    -   `field_battery_ref` (Entity Reference: Battery)
    -   `field_borrower` (Entity Reference: User)
    -   `field_action` (List: Withdraw, Return, Report Issue, Charge Cycle)
    -   `field_related_tool_transaction` (Entity Reference: Library Transaction, optional)
    -   `field_timestamp` (Date)

### 2. Decoupled Workflow
- **Checkout:** When a tool is checked out, the system programmatically creates `battery_transaction` entries for each selected battery, linking them to the tool transaction.
- **Return:** Batteries can be returned independently. Scanning a battery creates a `return` transaction for it, even if the tool is not returned yet.
- **Charging:** A "Charging Station" interface could create `charge` transactions to track usage cycles.

### 3. Dashboard
- Create a View based on `battery_transaction` to show usage frequency, loss rates, and current distribution.

## Implementation Steps for Phase 2
1.  Define `battery_transaction` entity in ECK.
2.  Create `BatteryTransactionController`.
3.  Update `LibraryTransactionController` to generate battery transactions instead of just updating battery fields.
4.  Migrate existing revision history to transactions (optional).
