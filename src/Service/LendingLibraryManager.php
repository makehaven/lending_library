<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\user\Entity\User;

/**
 * Manager service for the lending library module.
 */
class LendingLibraryManager {

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

  public function getItemDetails(NodeInterface $library_item_node = NULL) {
    if (!$library_item_node || $library_item_node->bundle() !== 'library_item') {
      return NULL;
    }

    $status = 'available';
    if ($library_item_node->hasField('field_library_item_status') && !$library_item_node->get('field_library_item_status')->isEmpty()) {
      $status = $library_item_node->get('field_library_item_status')->value;
    }
    else {
      $this->logger->warning('Library item node @nid is missing status field value. Defaulting to available.', ['@nid' => $library_item_node->id()]);
    }

    $borrower_uid = NULL;
    if ($library_item_node->hasField('field_library_item_borrower') && !$library_item_node->get('field_library_item_borrower')->isEmpty()) {
      $borrower_uid = (int) $library_item_node->get('field_library_item_borrower')->target_id;
    }

    $replacement_value = NULL;
    if ($library_item_node->hasField('field_library_item_replacement_v') && !$library_item_node->get('field_library_item_replacement_v')->isEmpty()) {
      $raw_value = $library_item_node->get('field_library_item_replacement_v')->value;
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

  public function saveBatteryWithRevision(EntityInterface $battery, $message = '', $uid = NULL) {
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

  public function sendEmailByKey(EntityInterface $transaction, $key, $extra_params = []) {
    if ($key === 'waitlist_notification' && isset($extra_params['next_user'])) {
      $borrower_user = $extra_params['next_user'];
    }
    else {
      $borrower_ref = $transaction->get('field_library_borrower');
      if ($borrower_ref->isEmpty()) return;
      $borrower_user = User::load($borrower_ref->target_id);
    }

    if (!$borrower_user || !$borrower_user->getEmail()) return;

    $item_ref = $transaction->get('field_library_item');
    if ($item_ref->isEmpty()) return;
    $library_item_node = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$library_item_node) return;

    $params = [
        'tool_name' => $library_item_node->label(),
        'borrower_name' => $borrower_user->getDisplayName(),
    ] + $extra_params;

    $from = \Drupal::config('system.site')->get('mail');
    \Drupal::service('plugin.manager.mail')->mail(
        'lending_library',
        $key,
        $borrower_user->getEmail(),
        $borrower_user->getPreferredLangcode(),
        $params,
        $from,
        TRUE
    );
  }
}
