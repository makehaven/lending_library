<?php

// Run with: lando drush scr modules/custom/lending_library/drush/scripts/migrate_comments.php

use Drupal\node\Entity\Node;
use Drupal\comment\Entity\Comment;
use Drupal\lending_library\Entity\LibraryTransaction;
use Drupal\Core\Datetime\DrupalDateTime;

// Increase limits for large migrations
set_time_limit(0);
ini_set('memory_limit', '1024M');

/**
 * CONFIGURATION
 */
const COMMENT_FIELD_NAME = 'comment_node_library_item';
const LEGACY_COMMENT_TYPE = 'comment_node_library_item';
const DRY_RUN = FALSE; // Set to FALSE to actually create entities.
const RESET_PROGRESS = FALSE; // Set to TRUE to restart from the beginning.

// Field Mappings (Legacy -> New)
const ACTION_MAP = [
  'withdraw' => 'withdraw',
  'return' => 'return',
  'report' => 'issue',
];

const ISSUE_MAP = [
  'none' => 'no_issues',
  'damage' => 'damage',
  'missing' => 'missing',
  'other' => 'other',
];

// State Management
$state = \Drupal::state();
if (RESET_PROGRESS) {
  $state->set('lending_library_migration_last_nid', 0);
  echo "Progress reset. Starting from the beginning.\n";
}

$last_nid = $state->get('lending_library_migration_last_nid', 0);
echo "Starting Comment Migration (DRY RUN: " . (DRY_RUN ? 'YES' : 'NO') . ")\n";
if ($last_nid > 0) {
  echo "Resuming from Library Item Node ID > $last_nid\n";
}

$node_storage = \Drupal::entityTypeManager()->getStorage('node');
$comment_storage = \Drupal::entityTypeManager()->getStorage('comment');
$transaction_storage = \Drupal::entityTypeManager()->getStorage('library_transaction');

// 1. Find all Library Items (Incremental).
$query = $node_storage->getQuery()
  ->accessCheck(FALSE)
  ->condition('type', 'library_item')
  ->condition('nid', $last_nid, '>')
  ->sort('nid', 'ASC');
$nids = $query->execute();

echo "Found " . count($nids) . " remaining library items to process.\n";

$total_processed = 0;
$total_created = 0;

foreach ($nids as $nid) {
  $node = $node_storage->load($nid);
  if (!$node || !$node->hasField(COMMENT_FIELD_NAME)) {
    // Clear cache for this node to save memory
    $node_storage->resetCache([$nid]);
    $state->set('lending_library_migration_last_nid', $nid);
    continue;
  }

  // 2. Load comments for this node, sorted by date (oldest first).
  $cids = $comment_storage->getQuery()
    ->accessCheck(FALSE)
    ->condition('entity_id', $nid)
    ->condition('entity_type', 'node')
    ->condition('comment_type', LEGACY_COMMENT_TYPE)
    ->condition('field_name', COMMENT_FIELD_NAME)
    ->sort('created', 'ASC')
    ->execute();

  if (empty($cids)) {
    $node_storage->resetCache([$nid]);
    $state->set('lending_library_migration_last_nid', $nid);
    continue;
  }

  echo "Processing Item: " . $node->label() . " (NID: $nid, Comments: " . count($cids) . ")\n";

  foreach ($cids as $cid) {
    $comment = $comment_storage->load($cid);
    if (!$comment) continue;

    $author_uid = $comment->getOwnerId();
    $created = $comment->getCreatedTime();
    $date_str = date('Y-m-d H:i:s', $created);
    $body_text = $comment->hasField('comment_body') && !$comment->get('comment_body')->isEmpty() ? $comment->get('comment_body')->value : '';

    // 3. Determine Action
    $action = 'withdraw'; // Default
    if ($comment->hasField('field_library_action') && !$comment->get('field_library_action')->isEmpty()) {
      $legacy_action = $comment->get('field_library_action')->value;
      if (isset(ACTION_MAP[$legacy_action])) {
        $action = ACTION_MAP[$legacy_action];
      }
    } else {
      // Fallback parsing if field is empty
      if (stripos($body_text, 'return') !== FALSE) {
        $action = 'return';
      } elseif (stripos($body_text, 'broken') !== FALSE || stripos($body_text, 'damage') !== FALSE) {
        $action = 'issue';
      }
    }

    echo "  - Comment [$date_str] CID:$cid Action: $action\n";

    if (!DRY_RUN) {
      $transaction_values = [
        'type' => 'library_transaction',
        'field_library_item' => $nid,
        'field_library_borrower' => $author_uid,
        'field_library_action' => $action,
        'created' => $created, // Set creation time to match comment
        'uid' => $author_uid,
      ];

      // Map dates
      if ($action === 'withdraw') {
        $transaction_values['field_library_borrow_date'] = date('Y-m-d', $created);
        // Default due date +7 days
        $transaction_values['field_library_due_date'] = date('Y-m-d', strtotime('+7 days', $created));
        
        // Mark as closed if it's old (simple assumption for backfill, logic below will try to pair returns)
        $transaction_values['field_library_closed'] = 1; 
      } elseif ($action === 'return') {
        $transaction_values['field_library_return_date'] = date('Y-m-d', $created);
        $transaction_values['field_library_closed'] = 1;
      }

      // Map Inspection Issues
      if ($comment->hasField('field_library_inspection_issues') && !$comment->get('field_library_inspection_issues')->isEmpty()) {
        $legacy_issue = $comment->get('field_library_inspection_issues')->value;
        if (isset(ISSUE_MAP[$legacy_issue])) {
          $transaction_values['field_library_inspection_issues'] = ISSUE_MAP[$legacy_issue];
        }
      }

      // Map Notes
      $notes = [];
      if (!empty($body_text)) {
        $notes[] = "Comment: " . strip_tags($body_text);
      }
      if ($comment->hasField('field_library_inspection_note') && !$comment->get('field_library_inspection_note')->isEmpty()) {
        $notes[] = "Inspection Note: " . $comment->get('field_library_inspection_note')->value;
      }
      if ($comment->hasField('field_library_battery_number') && !$comment->get('field_library_battery_number')->isEmpty()) {
        $notes[] = "Battery: " . $comment->get('field_library_battery_number')->value;
      }
      if (!empty($notes)) {
        $transaction_values['field_library_inspection_notes'] = implode("\n", $notes);
      }

      // Map Images (File references)
      // Borrow Image
      if ($comment->hasField('field_borrow_inspection_image') && !$comment->get('field_borrow_inspection_image')->isEmpty()) {
        $transaction_values['field_library_borrow_inspect_img'] = $comment->get('field_borrow_inspection_image')->getValue();
      }
      // Return Image
      if ($comment->hasField('field_return_inspection_image') && !$comment->get('field_return_inspection_image')->isEmpty()) {
        $transaction_values['field_library_return_inspect_img'] = $comment->get('field_return_inspection_image')->getValue();
      }

      try {
        $transaction = $transaction_storage->create($transaction_values);
        $transaction->save();
        
        // Force the 'created' timestamp (Drupal might overwrite it on save)
        $transaction->set('created', $created);
        $transaction->save();

        echo "    [OK] Created Transaction ID: " . $transaction->id() . "\n";
        $total_created++;
      } catch (\Exception $e) {
        echo "    [ERROR] " . $e->getMessage() . "\n";
      }
    }
    $total_processed++;
  }
  // Clean up memory
  $comment_storage->resetCache($cids);
  $node_storage->resetCache([$nid]);
  $transaction_storage->resetCache();
  
  // Update state after each node
  $state->set('lending_library_migration_last_nid', $nid);
}

echo "Migration complete. Processed $total_processed comments. Created $total_created transactions.\n";
