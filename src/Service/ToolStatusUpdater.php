<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Service to update tool status based on transactions.
 */
class ToolStatusUpdater {

  const LENDING_LIBRARY_ITEM_NODE_TYPE = 'library_item';
  const LENDING_LIBRARY_ITEM_STATUS_FIELD = 'field_library_item_status';
  const LENDING_LIBRARY_ITEM_BORROWER_FIELD = 'field_library_item_borrower';
  const LENDING_LIBRARY_ITEM_WAITLIST_FIELD = 'field_library_item_waitlist';
  const LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD = 'field_library_item';
  const LENDING_LIBRARY_TRANSACTION_ACTION_FIELD = 'field_library_action';
  const LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD = 'field_library_borrower';
  const LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD = 'field_library_inspection_issues';
  const LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD = 'field_library_return_date';
  const LENDING_LIBRARY_TRANSACTION_BORROW_BATTERIES_FIELD = 'field_library_borrow_batteries';
  const LENDING_LIBRARY_ITEM_STATUS_AVAILABLE = 'available';
  const LENDING_LIBRARY_ITEM_STATUS_BORROWED = 'borrowed';
  const LENDING_LIBRARY_ITEM_STATUS_MISSING = 'missing';
  const LENDING_LIBRARY_ITEM_STATUS_REPAIR = 'repair';
  const LENDING_LIBRARY_ACTION_WITHDRAW = 'withdraw';
  const LENDING_LIBRARY_ACTION_RETURN = 'return';
  const LENDING_LIBRARY_ACTION_ISSUE = 'issue';
  const LENDING_LIBRARY_INSPECTION_MISSING = 'missing';
  const LENDING_LIBRARY_INSPECTION_DAMAGE = 'damage';
  const LENDING_LIBRARY_INSPECTION_OTHER = 'other';
  const LENDING_LIBRARY_INSPECTION_NO_ISSUES = 'no_issues';
  const LENDING_LIBRARY_BATTERY_STATUS_FIELD = 'field_battery_status';
  const LENDING_LIBRARY_BATTERY_BORROWER_FIELD = 'field_battery_borrower';
  const LENDING_LIBRARY_BATTERY_CURRENT_ITEM_FIELD = 'field_battery_current_item';
  const LENDING_LIBRARY_BATTERY_STATUS_BORROWED = 'borrowed';
  const LENDING_LIBRARY_BATTERY_STATUS_AVAILABLE = 'available';
  const LENDING_LIBRARY_BATTERY_STATUS_MISSING = 'missing';

  protected $entityTypeManager;
  protected $logger;
  protected $lendingLibraryManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, LendingLibraryManager $lending_library_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('lending_library');
    $this->lendingLibraryManager = $lending_library_manager;
  }

  public function updateFromTransaction(EntityInterface $entity) {
    if ($entity->getEntityTypeId() !== 'library_transaction' || $entity->bundle() !== 'library_transaction') {
      return;
    }

    if ($entity->get(self::LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD)->isEmpty()
      || $entity->get(self::LENDING_LIBRARY_TRANSACTION_ACTION_FIELD)->isEmpty()) {
      return;
    }

    $library_item_node_id = $entity->get(self::LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD)->target_id;
    $action = $entity->get(self::LENDING_LIBRARY_TRANSACTION_ACTION_FIELD)->value;
    $library_item_node = $this->entityTypeManager->getStorage('node')->load($library_item_node_id);
    if (!$library_item_node instanceof NodeInterface || $library_item_node->bundle() !== self::LENDING_LIBRARY_ITEM_NODE_TYPE) {
      return;
    }

    $transaction_borrower_uid = $entity->getOwnerId();
    if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)
      && !$entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)->isEmpty()) {
      $transaction_borrower_uid = $entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)->target_id;
    }

    $save_item_node = FALSE;

    switch ($action) {
      case self::LENDING_LIBRARY_ACTION_WITHDRAW:
        $item_details = $this->lendingLibraryManager->getItemDetails($library_item_node);

        if ($item_details['status'] === self::LENDING_LIBRARY_ITEM_STATUS_BORROWED && $item_details['borrower_uid']) {
          $storage = $this->entityTypeManager->getStorage('library_transaction');
          $return_values = [
            'type' => 'library_transaction',
            'field_library_item' => $library_item_node->id(),
            'field_library_action' => self::LENDING_LIBRARY_ACTION_RETURN,
            'field_library_borrower' => $item_details['borrower_uid'],
            'uid' => $item_details['borrower_uid'],
            'field_library_return_date' => date('Y-m-d\TH:i:s'),
            'field_library_inspection_notes' => 'Automatic return processed due to new withdrawal.',
          ];
          $programmatic_return = $storage->create($return_values);
          $programmatic_return->save();

          $this->entityTypeManager->getStorage('node')->resetCache([$library_item_node->id()]);
          $library_item_node = $this->entityTypeManager->getStorage('node')->load($library_item_node->id());
        }

        if ($transaction_borrower_uid) {
          if ($library_item_node->hasField(self::LENDING_LIBRARY_ITEM_WAITLIST_FIELD) && !$library_item_node->get(self::LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->isEmpty()) {
            $waitlist_items = $library_item_node->get(self::LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->getValue();
            foreach ($waitlist_items as $delta => $item) {
              if (isset($item['target_id']) && $item['target_id'] == $transaction_borrower_uid) {
                $library_item_node->get(self::LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->removeItem($delta);
                break;
              }
            }
          }

          $library_item_node->set(self::LENDING_LIBRARY_ITEM_STATUS_FIELD, self::LENDING_LIBRARY_ITEM_STATUS_BORROWED);
          $library_item_node->set(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD, ['target_id' => $transaction_borrower_uid]);
          if ($library_item_node->hasField('field_item_available_since')) {
            $library_item_node->set('field_item_available_since', NULL);
          }
          $save_item_node = TRUE;

          if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_BORROW_BATTERIES_FIELD)
            && !$entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROW_BATTERIES_FIELD)->isEmpty()) {

            $battery_target_ids = array_column($entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROW_BATTERIES_FIELD)->getValue(), 'target_id');
            if (!empty($battery_target_ids)) {
              $batteries = $this->entityTypeManager->getStorage('battery')->loadMultiple($battery_target_ids);
              foreach ($batteries as $battery) {
                if ($battery->hasField(self::LENDING_LIBRARY_BATTERY_STATUS_FIELD)) {
                  $battery->set(self::LENDING_LIBRARY_BATTERY_STATUS_FIELD, self::LENDING_LIBRARY_BATTERY_STATUS_BORROWED);
                }
                if ($battery->hasField(self::LENDING_LIBRARY_BATTERY_BORROWER_FIELD) && $transaction_borrower_uid) {
                  $battery->set(self::LENDING_LIBRARY_BATTERY_BORROWER_FIELD, ['target_id' => $transaction_borrower_uid]);
                }
                if ($battery->hasField(self::LENDING_LIBRARY_BATTERY_CURRENT_ITEM_FIELD)) {
                  $battery->set(self::LENDING_LIBRARY_BATTERY_CURRENT_ITEM_FIELD, ['target_id' => $library_item_node->id()]);
                }
                $this->lendingLibraryManager->saveBatteryWithRevision(
                  $battery,
                  t('Borrowed with tool @tool (nid @nid) by user @uid.', [
                    '@tool' => $library_item_node->label(),
                    '@nid'  => $library_item_node->id(),
                    '@uid'  => $transaction_borrower_uid,
                  ])
                );
              }
            }
          }
        }
        break;

      case self::LENDING_LIBRARY_ACTION_RETURN:
        $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_AVAILABLE;
        if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)
          && !$entity->get(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)->isEmpty()) {
          $issue_type = $entity->get(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)->value;
          switch ($issue_type) {
            case self::LENDING_LIBRARY_INSPECTION_MISSING:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_MISSING;
              break;

            case self::LENDING_LIBRARY_INSPECTION_DAMAGE:
            case self::LENDING_LIBRARY_INSPECTION_OTHER:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_REPAIR;
              break;

            case self::LENDING_LIBRARY_INSPECTION_NO_ISSUES:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_AVAILABLE;
              break;
          }
        }
        $library_item_node->set(self::LENDING_LIBRARY_ITEM_STATUS_FIELD, $new_status_based_on_issue);
        $library_item_node->set(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD, NULL);
        if ($library_item_node->hasField('field_item_available_since')) {
          $library_item_node->set('field_item_available_since', date('Y-m-d\TH:i:s'));
        }
        $save_item_node = TRUE;

        if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD) && $entity->get(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD)->isEmpty()) {
          $entity->set(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD, ['value' => (new DrupalDateTime('now'))->format('Y-m-d')]);
        }

        if ($entity->hasField('field_library_closed')) {
          try { $entity->set('field_library_closed', 1); } catch (\Exception $ignore) {}
        }

        $query = $this->entityTypeManager->getStorage('library_transaction')->getQuery()
          ->condition('field_library_item', $library_item_node->id())
          ->condition('field_library_borrower', $transaction_borrower_uid)
          ->condition('field_library_action', 'withdraw')
          ->condition('field_library_closed', 1, '<>')
          ->sort('created', 'DESC')
          ->range(0, 1)
          ->accessCheck(FALSE);
        $open_withdraw_ids = $query->execute();

        if (!empty($open_withdraw_ids)) {
          $open_withdraw_transaction = $this->entityTypeManager->getStorage('library_transaction')->load(reset($open_withdraw_ids));
          if ($open_withdraw_transaction) {
            if ($open_withdraw_transaction->hasField('field_library_closed')) {
              $open_withdraw_transaction->set('field_library_closed', 1);
            }
            if ($open_withdraw_transaction->hasField(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD) && $entity->hasField(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD)) {
              $open_withdraw_transaction->set(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD, $entity->get(self::LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD)->value);
            }
            $open_withdraw_transaction->save();
          }
        }
        break;

      case self::LENDING_LIBRARY_ACTION_ISSUE:
        $new_status_based_on_issue = NULL;
        if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)
          && !$entity->get(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)->isEmpty()) {
          $issue_type = $entity->get(self::LENDING_LIBRARY_TRANSACTION_INSPECTION_ISSUES_FIELD)->value;
          switch ($issue_type) {
            case self::LENDING_LIBRARY_INSPECTION_MISSING:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_MISSING;
              break;

            case self::LENDING_LIBRARY_INSPECTION_DAMAGE:
            case self::LENDING_LIBRARY_INSPECTION_OTHER:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_REPAIR;
              break;

            case self::LENDING_LIBRARY_INSPECTION_NO_ISSUES:
              $new_status_based_on_issue = self::LENDING_LIBRARY_ITEM_STATUS_AVAILABLE;
              break;
          }
        }

        if ($new_status_based_on_issue) {
          $library_item_node->set(self::LENDING_LIBRARY_ITEM_STATUS_FIELD, $new_status_based_on_issue);
          if (in_array($new_status_based_on_issue, [self::LENDING_LIBRARY_ITEM_STATUS_AVAILABLE, self::LENDING_LIBRARY_ITEM_STATUS_REPAIR])) {
            $library_item_node->set(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD, NULL);
          }
          $save_item_node = TRUE;

          if ($new_status_based_on_issue === self::LENDING_LIBRARY_ITEM_STATUS_MISSING) {
            try {
              $query = $this->entityTypeManager->getStorage('battery')->getQuery()->accessCheck(FALSE);
              $defs = \Drupal::service('entity_field.manager')->getFieldDefinitions('battery', 'battery');
              if (isset($defs[self::LENDING_LIBRARY_BATTERY_STATUS_FIELD])) {
                $query->condition(self::LENDING_LIBRARY_BATTERY_STATUS_FIELD . '.value', self::LENDING_LIBRARY_BATTERY_STATUS_BORROWED);
              }
              if (isset($defs[self::LENDING_LIBRARY_BATTERY_CURRENT_ITEM_FIELD])) {
                $query->condition(self::LENDING_LIBRARY_BATTERY_CURRENT_ITEM_FIELD, $library_item_node->id());
              }
              $ids = $query->execute();
              if ($ids) {
                $bats = $this->entityTypeManager->getStorage('battery')->loadMultiple($ids);
                foreach ($bats as $battery) {
                  if ($battery->hasField(self::LENDING_LIBRARY_BATTERY_STATUS_FIELD)) {
                    $battery->set(self::LENDING_LIBRARY_BATTERY_STATUS_FIELD, self::LENDING_LIBRARY_BATTERY_STATUS_MISSING);
                  }
                  $this->lendingLibraryManager->saveBatteryWithRevision(
                    $battery,
                    t('Marked MISSING because tool @tool (nid @nid) was reported missing.', [
                      '@tool' => $library_item_node->label(),
                      '@nid'  => $library_item_node->id(),
                    ])
                  );
                }
              }
            } catch (\Exception $e) {
              $this->logger->error('Error updating batteries on missing issue: @msg', ['@msg' => $e->getMessage()]);
            }
          }
        }
        break;
    }

    if ($save_item_node) {
      try {
        $library_item_node->save();
      }
      catch (\Exception $e) {
        $this->logger->error(
          'Failed to save library item @nid after transaction: @msg',
          ['@nid' => $library_item_node->id(), '@msg' => $e->getMessage()]
        );
      }
    }
  }
}
