<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Drupal\Core\Session\AccountProxyInterface;

/**
 * Controller for library transaction actions.
 */
class LibraryTransactionController extends ControllerBase {

  protected $entityTypeManager;
  protected $entityFormBuilder;
  protected $currentUser;

  /**
   * Constructs a new LibraryTransactionController.
   */
  protected $lendingLibraryManager;

  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFormBuilderInterface $entity_form_builder, AccountProxyInterface $current_user, \Drupal\lending_library\Service\LendingLibraryManager $lending_library_manager) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFormBuilder = $entity_form_builder;
    $this->currentUser = $current_user;
    $this->lendingLibraryManager = $lending_library_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('current_user'),
      $container->get('lending_library.manager')
    );
  }

  /**
   * Displays the transaction form pre-filled for a specific action.
   */
  public function actionForm(NodeInterface $node, string $action_type) {
    // Corresponds to LENDING_LIBRARY_ITEM_NODE_TYPE in .module
    if ($node->bundle() !== 'library_item') { 
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $values = [
      'type' => 'library_transaction',
      // Corresponds to LENDING_LIBRARY_TRANSACTION_ITEM_REF_FIELD
      'field_library_item' => ['target_id' => $node->id()],
      // Corresponds to LENDING_LIBRARY_TRANSACTION_ACTION_FIELD
      'field_library_action' => [['value' => $action_type]],
      // Corresponds to LENDING_LIBRARY_TRANSACTION_BORROWER_FIELD
      'field_library_borrower' => ['target_id' => $this->currentUser()->id()],
      'uid' => $this->currentUser()->id(),
    ];

    // Corresponds to LENDING_LIBRARY_ACTION_WITHDRAW
    if ($action_type === 'withdraw') {
      $config = $this->config('lending_library.settings');
      $loan_days = $config->get('loan_period_days') ?: 7;

      $today_datetime = new DrupalDateTime('now');
      $due_datetime = new DrupalDateTime('now');
      $due_datetime->modify("+$loan_days days");
      $date_format = 'Y-m-d';

      // Corresponds to LENDING_LIBRARY_TRANSACTION_BORROW_DATE_FIELD
      $values['field_library_borrow_date'] = [['value' => $today_datetime->format($date_format)]];
      // Corresponds to LENDING_LIBRARY_TRANSACTION_DUE_DATE_FIELD
      $values['field_library_due_date'] = [['value' => $due_datetime->format($date_format)]];
      // Corresponds to LENDING_LIBRARY_TRANSACTION_RENEW_COUNT_FIELD
      $values['field_library_renew_count'] = [['value' => 0]];
    }
    // Corresponds to LENDING_LIBRARY_ACTION_RETURN
    elseif ($action_type === 'return') {
      $today_datetime = new DrupalDateTime('now');
      $date_format = 'Y-m-d';
      // Corresponds to LENDING_LIBRARY_TRANSACTION_RETURN_DATE_FIELD
      $values['field_library_return_date'] = [['value' => $today_datetime->format($date_format)]];
    }

    $transaction = $this->entityTypeManager
      ->getStorage('library_transaction')
      ->create($values);

    $form = $this->entityFormBuilder->getForm($transaction, 'default');

    return $form;
  }

  /**
   * Renews a borrowed item.
   */
  public function renew(NodeInterface $node) {
    if ($node->bundle() !== 'library_item') {
      throw new \Symfony\Component\HttpKernel\Exception\NotFoundHttpException();
    }

    $item_details = $this->lendingLibraryManager->getItemDetails($node);

    if (empty($item_details) || $item_details['status'] !== 'borrowed' || $item_details['borrower_uid'] != $this->currentUser()->id()) {
      $this->messenger()->addError($this->t('You cannot renew this item.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    if ($node->hasField('field_library_item_waitlist') && !$node->get('field_library_item_waitlist')->isEmpty()) {
      $this->messenger()->addError($this->t('You cannot renew this item because there is a waitlist.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $query = $this->entityTypeManager->getStorage('library_transaction')->getQuery()
      ->condition('field_library_item', $node->id())
      ->condition('field_library_action', 'withdraw')
      ->condition('field_library_borrower', $this->currentUser()->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);
    $transaction_ids = $query->execute();

    if (empty($transaction_ids)) {
      $this->messenger()->addError($this->t('Could not find the original loan transaction.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $transaction = $this->entityTypeManager->getStorage('library_transaction')->load(reset($transaction_ids));
    $due_date = $transaction->get('field_library_due_date')->date;
    $now = new DrupalDateTime();

    if ($due_date < $now) {
      $this->messenger()->addError($this->t('You cannot renew an overdue item.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $config = $this->config('lending_library.settings');
    $max_renewals = $config->get('max_renewal_count');
    $renew_count = 0;
    if ($transaction->hasField('field_library_renew_count') && !$transaction->get('field_library_renew_count')->isEmpty()) {
      $renew_count = $transaction->get('field_library_renew_count')->value;
    }

    if ($max_renewals > 0 && $renew_count >= $max_renewals) {
      $this->messenger()->addError($this->t('You have reached the maximum number of renewals for this item.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    // All checks passed, proceed with renewal.
    $new_due_date = new DrupalDateTime();
    $new_due_date->modify('+7 days');

    // Update the original withdraw transaction.
    $transaction->set('field_library_due_date', $new_due_date->format('Y-m-d\TH:i:s'));
    $transaction->set('field_library_renew_count', $renew_count + 1);
    $transaction->save();

    // Create a new 'renew' transaction for logging purposes.
    $renew_transaction_values = [
      'type' => 'library_transaction',
      'field_library_item' => ['target_id' => $node->id()],
      'field_library_action' => 'renew',
      'field_library_borrower' => ['target_id' => $this->currentUser()->id()],
      'uid' => $this->currentUser()->id(),
      'field_library_due_date' => $new_due_date->format('Y-m-d'),
    ];
    $renew_transaction = $this->entityTypeManager
      ->getStorage('library_transaction')
      ->create($renew_transaction_values);
    $renew_transaction->save();

    // Send email notification.
    $this->lendingLibraryManager->sendEmailByKey($renew_transaction, 'renewal_confirmation', [
      'due_date' => $new_due_date->format('F j, Y'),
    ]);

    $this->messenger()->addStatus($this->t('You have successfully renewed %title. It is now due on @date.', [
      '%title' => $node->label(),
      '@date' => $new_due_date->format('F j, Y'),
    ]));
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

}