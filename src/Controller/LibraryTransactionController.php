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
  public function __construct(EntityTypeManagerInterface $entity_type_manager, EntityFormBuilderInterface $entity_form_builder, AccountProxyInterface $current_user) {
    $this->entityTypeManager = $entity_type_manager;
    $this->entityFormBuilder = $entity_form_builder;
    $this->currentUser = $current_user;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('current_user')
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

    $item_details = _lending_library_get_item_details($node);
    if ($item_details['status'] !== LENDING_LIBRARY_ITEM_STATUS_BORROWED || $item_details['borrower_uid'] !== $this->currentUser()->id()) {
      $this->messenger()->addError($this->t('You cannot renew this item.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    if ($node->hasField(LENDING_LIBRARY_ITEM_WAITLIST_FIELD) && !$node->get(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->isEmpty()) {
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
    $due_date = $transaction->get(LENDING_LIBRARY_TRANSACTION_DUE_DATE_FIELD)->date;
    $now = new DrupalDateTime();

    if ($due_date < $now) {
      $this->messenger()->addError($this->t('You cannot renew an overdue item.'));
      return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
    }

    $new_due_date = new DrupalDateTime();
    $new_due_date->modify('+7 days');
    $transaction->set(LENDING_LIBRARY_TRANSACTION_DUE_DATE_FIELD, $new_due_date->format('Y-m-d H:i:s'));

    $renew_count = $transaction->get(LENDING_LIBRARY_TRANSACTION_RENEW_COUNT_FIELD)->value;
    $transaction->set(LENDING_LIBRARY_TRANSACTION_RENEW_COUNT_FIELD, $renew_count + 1);

    $transaction->save();

    _lending_library_send_email_by_key($transaction, 'renewal_confirmation', [
      'due_date' => $new_due_date->format('F j, Y'),
    ]);

    $this->messenger()->addStatus($this->t('You have successfully renewed %title.', ['%title' => $node->label()]));
    return $this->redirect('entity.node.canonical', ['node' => $node->id()]);
  }

}