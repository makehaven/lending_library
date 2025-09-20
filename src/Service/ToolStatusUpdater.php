<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;

/**
 * Service to update tool status based on transactions.
 */
class ToolStatusUpdater {

  const LENDING_LIBRARY_ITEM_NODE_TYPE = 'library_item';
  const LENDING_LIBRARY_ITEM_STATUS_FIELD = 'field_library_item_status';
  const LENDING_LIBRARY_ITEM_BORROWER_FIELD = 'field_library_item_borrower';
  const LENDING_LIBRARY_ITEM_WAITLIST_FIELD = 'field_library_item_waitlist';
  const LENDING_LIBRARY_ITEM_REPLACEMENT_VALUE_FIELD = 'field_library_item_replacement_v';
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
  protected $currentUser;
  protected $time;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, LoggerChannelFactoryInterface $logger_factory, AccountInterface $current_user, TimeInterface $time) {
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('lending_library');
    $this->currentUser = $current_user;
    $this->time = $time;
  }

  public function updateFromTransaction(EntityInterface $entity) {
    if ($entity->getEntityTypeId() !== 'library_transaction' || $entity->bundle() !== 'library_transaction') {
      return;
    }

    if ($entity->get(self::LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD)->isEmpty()
      || $entity->get(self::LENDING_LIBRARY_TRANSACTION_ACTION_FIELD)->isEmpty()) {
      return;
    }

    $library_item_node = $entity->get(self::LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD)->entity;
    if (!$library_item_node instanceof NodeInterface || $library_item_node->bundle() !== self::LENDING_LIBRARY_ITEM_NODE_TYPE) {
      return;
    }

    $action = $entity->get(self::LENDING_LIBRARY_TRANSACTION_ACTION_FIELD)->value;
    $transaction_borrower_uid = $entity->getOwnerId();
    if ($entity->hasField(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)
      && !$entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)->isEmpty()) {
      $transaction_borrower_uid = $entity->get(self::LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD)->target_id;
    }

    $save_item_node = FALSE;

    switch ($action) {
      case self::LENDING_LIBRARY_ACTION_WITHDRAW:
        $item_details = $this->getItemDetails($library_item_node);

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
                $this->saveBatteryWithRevision(
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
        }
        break;
    }

    if ($save_item_node) {
      $library_item_node->save();
    }
  }

  private function getItemDetails(NodeInterface $library_item_node = NULL) {
    if (!$library_item_node || $library_item_node->bundle() !== self::LENDING_LIBRARY_ITEM_NODE_TYPE) {
      return NULL;
    }

    $status = self::LENDING_LIBRARY_ITEM_STATUS_AVAILABLE;
    if ($library_item_node->hasField(self::LENDING_LIBRARY_ITEM_STATUS_FIELD) && !$library_item_node->get(self::LENDING_LIBRARY_ITEM_STATUS_FIELD)->isEmpty()) {
      $status = $library_item_node->get(self::LENDING_LIBRARY_ITEM_STATUS_FIELD)->value;
    }
    else {
      $this->logger->warning('Library item node @nid is missing status field value. Defaulting to available.', ['@nid' => $library_item_node->id()]);
    }

    $borrower_uid = NULL;
    if ($library_item_node->hasField(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD) && !$library_item_node->get(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD)->isEmpty()) {
      $borrower_uid = (int) $library_item_node->get(self::LENDING_LIBRARY_ITEM_BORROWER_FIELD)->target_id;
    }

    $replacement_value = NULL;
    if ($library_item_node->hasField(self::LENDING_LIBRARY_ITEM_REPLACEMENT_VALUE_FIELD) && !$library_item_node->get(self::LENDING_LIBRARY_ITEM_REPLACEMENT_VALUE_FIELD)->isEmpty()) {
      $raw_value = $library_item_node->get(self::LENDING_LIBRARY_ITEM_REPLACEMENT_VALUE_FIELD)->value;
      if (is_numeric($raw_value)) {
        $replacement_value = $raw_value;
      }
    }

    return [
      'status' => $status,
      'borrower_uid' => $borrower_uid,
      'replacement_value' => $replacement_value,
    ];
  }

  private function saveBatteryWithRevision(EntityInterface $battery, $message = '', $uid = NULL) {
    try {
      if ($battery->getEntityType()->isRevisionable()) {
        $battery->setNewRevision(TRUE);

        if ($uid === NULL) {
          $uid = $this->currentUser->id();
        }
        if (method_exists($battery, 'setRevisionUserId')) {
          $battery->setRevisionUserId($uid);
        }
        if (method_exists($battery, 'setRevisionCreationTime')) {
          $battery->setRevisionCreationTime($this->time->getRequestTime());
        }
        if (method_exists($battery, 'setRevisionLogMessage') && $message !== '') {
          $battery->setRevisionLogMessage($message);
        }
      }

      $battery->save();
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to save battery @id: @msg', [
        '@id' => method_exists($battery, 'id') ? $battery->id() : 'unknown',
        '@msg' => $e->getMessage(),
      ]);
    }
  }
}
