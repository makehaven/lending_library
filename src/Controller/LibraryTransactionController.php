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

}