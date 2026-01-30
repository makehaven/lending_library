<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\user\Entity\User;

/**
 * Service to manage lending library business logic.
 */
class LendingLibraryManager implements LendingLibraryManagerInterface {

  // Constants from module file (redefined here for self-containment)
  const ITEM_NODE_TYPE = 'library_item';
  const ITEM_STATUS_FIELD = 'field_library_item_status';
  const ITEM_BORROWER_FIELD = 'field_library_item_borrower';
  const ITEM_WAITLIST_FIELD = 'field_library_item_waitlist';
  const ITEM_REPLACEMENT_VALUE_FIELD = 'field_library_item_replacement_v';

  const TRANSACTION_ACTION_FIELD = 'field_library_action';
  const TRANSACTION_ITEM_REF_FIELD = 'field_library_item';
  const TRANSACTION_BORROWER_FIELD = 'field_library_borrower';
  const TRANSACTION_INSPECTION_ISSUES_FIELD = 'field_library_inspection_issues';
  const TRANSACTION_RETURN_DATE_FIELD = 'field_library_return_date';
  const TRANSACTION_BORROW_DATE_FIELD = 'field_library_borrow_date';
  const TRANSACTION_DUE_DATE_FIELD = 'field_library_due_date';
  const TRANSACTION_BORROW_BATTERIES_FIELD = 'field_library_borrow_batteries';

  const BATTERY_STATUS_FIELD = 'field_battery_status';
  const BATTERY_BORROWER_FIELD = 'field_battery_borrower';
  const BATTERY_CURRENT_ITEM_FIELD = 'field_battery_current_item';

  const STATUS_AVAILABLE = 'available';
  const STATUS_BORROWED = 'borrowed';

  protected $entityTypeManager;
  protected $configFactory;
  protected $logger;
  protected $currentUser;
  protected $time;
  protected $dateFormatter;
  protected $mailManager;
  protected $languageManager;
  protected $entityFieldManager;

  /**
   * Constructs a new LendingLibraryManager.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
    AccountInterface $current_user,
    TimeInterface $time,
    DateFormatterInterface $date_formatter,
    MailManagerInterface $mail_manager,
    LanguageManagerInterface $language_manager,
    $entity_field_manager
  ) {
    $this->entityTypeManager = $entity_type_manager;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('lending_library');
    $this->currentUser = $current_user;
    $this->time = $time;
    $this->dateFormatter = $date_formatter;
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->entityFieldManager = $entity_field_manager;
  }

  /**
   * {@inheritdoc}
   */
  public function getItemDetails(?NodeInterface $library_item_node) {
    if (!$library_item_node || $library_item_node->bundle() !== self::ITEM_NODE_TYPE) {
      return NULL;
    }

    $status = self::STATUS_AVAILABLE;
    if ($library_item_node->hasField(self::ITEM_STATUS_FIELD) && !$library_item_node->get(self::ITEM_STATUS_FIELD)->isEmpty()) {
      $status = $library_item_node->get(self::ITEM_STATUS_FIELD)->value;
    }
    else {
      $this->logger->warning('Library item node @nid is missing status field value. Defaulting to available.', ['@nid' => $library_item_node->id()]);
    }

    $borrower_uid = NULL;
    if ($library_item_node->hasField(self::ITEM_BORROWER_FIELD) && !$library_item_node->get(self::ITEM_BORROWER_FIELD)->isEmpty()) {
      $borrower_uid = (int) $library_item_node->get(self::ITEM_BORROWER_FIELD)->target_id;
    }

    $replacement_value = NULL;
    if ($library_item_node->hasField(self::ITEM_REPLACEMENT_VALUE_FIELD) && !$library_item_node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->isEmpty()) {
      $raw_value = $library_item_node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->value;
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

  /**
   * {@inheritdoc}
   */
  public function getCurrentLoanDetails(NodeInterface $library_item_node) {
    if (!$library_item_node) {
      return NULL;
    }

    $query = $this->entityTypeManager->getStorage('library_transaction')->getQuery()
      ->condition(self::TRANSACTION_ITEM_REF_FIELD, $library_item_node->id())
      ->condition(self::TRANSACTION_ACTION_FIELD, 'withdraw')
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);

    $transaction_ids = $query->execute();
    if (empty($transaction_ids)) {
      return NULL;
    }

    $transaction = $this->entityTypeManager->getStorage('library_transaction')->load(reset($transaction_ids));
    if (!$transaction) {
      return NULL;
    }

    $borrow_date = NULL;
    if ($transaction->hasField(self::TRANSACTION_BORROW_DATE_FIELD) && !$transaction->get(self::TRANSACTION_BORROW_DATE_FIELD)->isEmpty()) {
      $borrow_date = $transaction->get(self::TRANSACTION_BORROW_DATE_FIELD)->date;
    }

    $due_date = NULL;
    if ($transaction->hasField(self::TRANSACTION_DUE_DATE_FIELD) && !$transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->isEmpty()) {
      $due_date = $transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->date;
    }

    return ['borrow_date' => $borrow_date, 'due_date' => $due_date];
  }

  /**
   * {@inheritdoc}
   */
  public function loadBorrowedBatteries($item_nid, $borrower_uid) {
    $storage = $this->entityTypeManager->getStorage('battery');

    // Guard: make sure fields exist on the bundle.
    $defs = $this->entityFieldManager->getFieldDefinitions('battery', 'battery');
    foreach ([self::BATTERY_STATUS_FIELD, self::BATTERY_BORROWER_FIELD, self::BATTERY_CURRENT_ITEM_FIELD] as $f) {
      if (!isset($defs[$f])) {
        return [];
      }
    }

    $ids = $this->entityTypeManager->getStorage('battery')->getQuery()
      ->accessCheck(FALSE)
      ->condition(self::BATTERY_STATUS_FIELD . '.value', self::STATUS_BORROWED)
      ->condition(self::BATTERY_BORROWER_FIELD, $borrower_uid)
      ->condition(self::BATTERY_CURRENT_ITEM_FIELD, $item_nid)
      ->execute();

    return empty($ids) ? [] : $storage->loadMultiple($ids);
  }

  /**
   * {@inheritdoc}
   */
  public function returnBatteries(array $batteries) {
    foreach ($batteries as $battery) {
      if ($battery->hasField(self::BATTERY_STATUS_FIELD)) {
        $battery->set(self::BATTERY_STATUS_FIELD, self::STATUS_AVAILABLE);
      }
      if ($battery->hasField(self::BATTERY_BORROWER_FIELD)) {
        $battery->set(self::BATTERY_BORROWER_FIELD, NULL);
      }
      if ($battery->hasField(self::BATTERY_CURRENT_ITEM_FIELD)) {
        $battery->set(self::BATTERY_CURRENT_ITEM_FIELD, NULL);
      }
      $this->saveBatteryWithRevision(
        $battery,
        t('Returned independently (battery only) by user @uid.', ['@uid' => $this->currentUser->id()])
      );

      $this->createBatteryTransaction(
        $battery,
        'return',
        $this->currentUser->id()
      );
    }
  }

  /**
   * {@inheritdoc}
   */
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

  /**
   * {@inheritdoc}
   */
  public function calculateLateFee(EntityInterface $transaction, ?DrupalDateTime $return_date = NULL) {
    if ($transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->isEmpty()) {
      return NULL;
    }

    $config = $this->configFactory->get('lending_library.settings');
    $daily_late_fee = $config->get('daily_late_fee');
    if (!$daily_late_fee || $daily_late_fee <= 0) {
      return NULL;
    }

    $due_date = $transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->date;
    $now = $return_date ?: new DrupalDateTime('now');

    if (!$due_date instanceof DrupalDateTime || $due_date >= $now) {
      return NULL;
    }

    $days_late = $due_date->diff($now)->days;
    $late_fee = $days_late * $daily_late_fee;

    return [
      'days_late' => $days_late,
      'late_fee' => $late_fee,
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function calculateUnreturnedBatteryValue(EntityInterface $transaction) {
    $borrowed_batteries_ref = $transaction->get(self::TRANSACTION_BORROW_BATTERIES_FIELD);
    if ($borrowed_batteries_ref->isEmpty()) {
      return 0;
    }

    $total_value = 0;
    $borrowed_batteries = $borrowed_batteries_ref->referencedEntities();
    foreach ($borrowed_batteries as $battery) {
      if ($battery->hasField('field_battery_value') && !$battery->get('field_battery_value')->isEmpty()) {
        $total_value += $battery->get('field_battery_value')->value;
      }
    }
    return $total_value;
  }

  /**
   * {@inheritdoc}
   */
  public function processNonReturnCharge(EntityInterface $transaction, $non_return_charge_percentage) {
    $item_ref = $transaction->get(self::TRANSACTION_ITEM_REF_FIELD);
    if ($item_ref->isEmpty()) {
      return;
    }
    $library_item_node = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$library_item_node) {
      return;
    }

    $item_details = $this->getItemDetails($library_item_node);
    $replacement_value = $item_details['replacement_value'] ?? 0;

    $late_fee_details = $this->calculateLateFee($transaction);
    $final_late_fee = 0;
    if ($late_fee_details) {
      $fee_cap = ($replacement_value * $non_return_charge_percentage) / 100;
      $final_late_fee = min($late_fee_details['late_fee'], $fee_cap);
    }

    $battery_charge = $this->calculateUnreturnedBatteryValue($transaction);

    $total_due = $replacement_value + $final_late_fee + $battery_charge;

    if ($transaction->hasField('field_library_charge_replacement')) {
      $transaction->set('field_library_charge_replacement', $replacement_value);
    }
    if ($transaction->hasField('field_library_charge_overdue')) {
      $transaction->set('field_library_charge_overdue', $final_late_fee);
    }
    if ($transaction->hasField('field_library_charge_battery')) {
      $transaction->set('field_library_charge_battery', $battery_charge);
    }
    if ($transaction->hasField('field_library_amount_due')) {
      $transaction->set('field_library_amount_due', $total_due);
    }

    if ($transaction->hasField('field_library_30day_processed')) {
      $transaction->set('field_library_30day_processed', 1);
    }

    $transaction->save();

    $this->sendEmailByKey($transaction, 'overdue_30_day', [
      'amount_due' => $total_due,
      'unreturned_batteries_charge' => $battery_charge,
      'tool_replacement_charge' => $replacement_value,
      'late_fee_total' => $final_late_fee,
      'transaction_id' => $transaction->id(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function sendEmailByKey(EntityInterface $transaction, $key, array $extra_params = []) {
    if ($key === 'waitlist_notification' && isset($extra_params['next_user'])) {
      $borrower_user = $extra_params['next_user'];
    }
    else {
      $borrower_ref = $transaction->get(self::TRANSACTION_BORROWER_FIELD);
      if ($borrower_ref->isEmpty()) return;
      $borrower_user = User::load($borrower_ref->target_id);
    }

    if (!$borrower_user || !$borrower_user->getEmail()) return;

    $item_ref = $transaction->get(self::TRANSACTION_ITEM_REF_FIELD);
    if ($item_ref->isEmpty()) return;
    $library_item_node = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$library_item_node) return;

    $params = [
      'tool_name' => $library_item_node->label(),
      'borrower_name' => $borrower_user->getDisplayName(),
    ] + $extra_params;

    $from = $this->configFactory->get('system.site')->get('mail');
    $result = $this->mailManager->mail(
      'lending_library',
      $key,
      $borrower_user->getEmail(),
      $borrower_user->getPreferredLangcode(),
      $params,
      $from,
      TRUE
    );

    if ($result['result']) {
      $this->logger->info("Sent '@key' email to @email for transaction @tid.", [
        '@key' => $key,
        '@email' => $borrower_user->getEmail(),
        '@tid' => $transaction->id(),
      ]);
    }
    else {
      $this->logger->error("Failed to send '@key' email to @email for transaction @tid.", [
        '@key' => $key,
        '@email' => $borrower_user->getEmail(),
        '@tid' => $transaction->id(),
      ]);
    }
  }

  /**
   * {@inheritdoc}
   */
  public function sendDueSoonEmail(EntityInterface $transaction) {
    if ($transaction->hasField('field_library_due_soon_notified')) {
      $transaction->set('field_library_due_soon_notified', 1);
      $transaction->save();
    }
    $this->sendEmailByKey($transaction, 'due_soon');
  }

  /**
   * {@inheritdoc}
   */
  public function sendCheckoutEmail(EntityInterface $transaction) {
    // ... Logic from _lending_library_send_checkout_email ...
    // Re-implement using helper methods where possible.
    $borrower_ref = $transaction->get(self::TRANSACTION_BORROWER_FIELD);
    if ($borrower_ref->isEmpty()) return;
    $borrower_user = User::load($borrower_ref->target_id);
    if (!$borrower_user || !$borrower_user->getEmail()) return;

    $item_ref = $transaction->get(self::TRANSACTION_ITEM_REF_FIELD);
    if ($item_ref->isEmpty()) return;
    $library_item_node = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$library_item_node) return;

    $due_date = '';
    if ($transaction->hasField(self::TRANSACTION_DUE_DATE_FIELD)
      && !$transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->isEmpty()
      && $transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->date instanceof DrupalDateTime) {
      $due_date = $transaction->get(self::TRANSACTION_DUE_DATE_FIELD)->date->format('F j, Y');
    }

    $replacement_value = '';
    if ($library_item_node->hasField(self::ITEM_REPLACEMENT_VALUE_FIELD)
      && !$library_item_node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->isEmpty()
      && is_numeric($library_item_node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->value)) {
      $replacement_value = $library_item_node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->value;
    }

    $params = [
      'tool_name' => $library_item_node->label(),
      'replacement_value' => $replacement_value,
      'due_date' => $due_date,
    ];

    $from = $this->configFactory->get('system.site')->get('mail');
    $this->mailManager->mail(
      'lending_library',
      'checkout_confirmation',
      $borrower_user->getEmail(),
      $borrower_user->getPreferredLangcode(),
      $params,
      $from,
      TRUE
    );
  }

  /**
   * {@inheritdoc}
   */
  public function sendReturnEmail(EntityInterface $transaction) {
    $borrower_ref = $transaction->get(self::TRANSACTION_BORROWER_FIELD);
    if ($borrower_ref->isEmpty()) return;
    $borrower = User::load($borrower_ref->target_id);
    if (!$borrower || !$borrower->getEmail()) return;

    $item_ref = $transaction->get(self::TRANSACTION_ITEM_REF_FIELD);
    if ($item_ref->isEmpty()) return;
    $item = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$item) return;

    $params = ['tool_name' => $item->label()];

    if ($transaction->hasField('field_library_inspection_notes') && !$transaction->get('field_library_inspection_notes')->isEmpty()) {
      $params['notes'] = $transaction->get('field_library_inspection_notes')->value;
    }

    if ($transaction->hasField('field_library_return_inspect_img') && !$transaction->get('field_library_return_inspect_img')->isEmpty()) {
      $file = $transaction->get('field_library_return_inspect_img')->entity;
      if ($file) {
        $params['return_image_url'] = $file->createFileUrl(FALSE);
      }
    }

    $from = $this->configFactory->get('system.site')->get('mail');
    $this->mailManager->mail(
      'lending_library',
      'return_confirmation',
      $borrower->getEmail(),
      $borrower->getPreferredLangcode(),
      $params,
      $from,
      TRUE
    );
  }

  /**
   * {@inheritdoc}
   */
  public function sendIssueEmail(EntityInterface $transaction) {
    $this->sendStaffEmail('issue_report_notice', $transaction, 'email_staff_address');
  }

  /**
   * {@inheritdoc}
   */
  public function sendDamagedEmail(EntityInterface $transaction) {
    $this->sendStaffEmail('damaged_item', $transaction, 'email_damaged_address');
  }

  protected function sendStaffEmail($email_key, EntityInterface $transaction, $address_config_key) {
    $item_ref = $transaction->get(self::TRANSACTION_ITEM_REF_FIELD);
    if ($item_ref->isEmpty()) return;
    $item = $this->entityTypeManager->getStorage('node')->load($item_ref->target_id);
    if (!$item) return;

    $reporter = User::load($transaction->getOwnerId());
    $reporter_name = $reporter ? $reporter->getDisplayName() : t('Unknown user');

    $issue = '';
    if ($transaction->hasField(self::TRANSACTION_INSPECTION_ISSUES_FIELD)
      && !$transaction->get(self::TRANSACTION_INSPECTION_ISSUES_FIELD)->isEmpty()) {
      $issue = $transaction->get(self::TRANSACTION_INSPECTION_ISSUES_FIELD)->value;
    }

    $notes = '';
    if ($transaction->hasField('field_library_inspection_notes')
      && !$transaction->get('field_library_inspection_notes')->isEmpty()) {
      $notes = $transaction->get('field_library_inspection_notes')->value;
    }

    $params = [
      'tool_name' => $item->label(),
      'issue_type' => $issue,
      'notes' => $notes,
      'reporter' => $reporter_name,
      'item_url' => $item->toUrl('canonical', ['absolute' => TRUE])->toString(),
    ];

    $config = $this->configFactory->get('lending_library.settings');
    $to_string = $config->get($address_config_key) ?: $this->configFactory->get('system.site')->get('mail');
    if (empty($to_string)) {
      $to_string = $config->get('email_staff_address') ?: $this->configFactory->get('system.site')->get('mail');
    }

    $to_addresses = array_map('trim', explode(',', $to_string));
    $from = $this->configFactory->get('system.site')->get('mail');

    $validator = \Drupal::service('email.validator'); // Inject this? Yes, let's use container in module or inject. 
    // For now, just use service call inside as we didn't inject email.validator but we have mailManager. 
    // Actually, best to inject email.validator.

    foreach ($to_addresses as $to) {
      // Simplify: assume valid if passed form validation or just try send.
      $this->mailManager->mail(
        'lending_library',
        $email_key,
        $to,
        $this->languageManager->getDefaultLanguage()->getId(),
        $params,
        $from,
        TRUE
      );
    }
  }

  /**
   * {@inheritdoc}
   */
  public function createBatteryTransaction(EntityInterface $battery, string $action, ?int $borrower_uid, ?EntityInterface $tool_transaction = NULL) {
    try {
      $values = [
        'type' => 'battery_transaction',
        'field_bt_battery' => $battery->id(),
        'field_bt_action' => $action,
        'uid' => $this->currentUser->id(), // Creator is current user
        'created' => $this->time->getRequestTime(),
      ];

      if ($borrower_uid) {
        $values['field_bt_borrower'] = $borrower_uid;
      }

      if ($tool_transaction) {
        $values['field_bt_tool_trans'] = $tool_transaction->id();
      }

      $transaction = $this->entityTypeManager->getStorage('battery_transaction')->create($values);
      $transaction->save();

      $this->logger->info('Created battery transaction @id for battery @bid (action: @action).', [
        '@id' => $transaction->id(),
        '@bid' => $battery->id(),
        '@action' => $action,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Failed to create battery transaction: @message', ['@message' => $e->getMessage()]);
    }
  }
}
