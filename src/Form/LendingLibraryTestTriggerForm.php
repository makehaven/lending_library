<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Provides a form to test overdue notifications.
 */
class LendingLibraryTestTriggerForm extends FormBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new LendingLibraryTestTriggerForm object.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lending_library_test_trigger_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $transaction_list_url = \Drupal\Core\Url::fromUri('internal:/admin/content/library_transaction')->toString();
    $link = '<a href="' . $transaction_list_url . '" target="_blank">Find Transactions</a>';

    $form['transaction_id'] = [
      '#type' => 'number',
      '#title' => $this->t('Library Transaction ID'),
      '#description' => $this->t('Enter the ID of the "withdraw" transaction you want to test. (@link)', ['@link' => $link]),
      '#required' => TRUE,
      '#min' => 1,
    ];

    $form['actions'] = [
      '#type' => 'actions',
    ];

    $form['actions']['send_overdue'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test: Send Late Fee Email'),
      '#submit' => ['::sendOverdueEmail'],
    ];

    $form['actions']['process_30_day'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test: Process Non-Return Charge & Send Email'),
      '#submit' => ['::process30DayOverdue'],
    ];

    $form['actions']['send_due_soon'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test: Send Due Soon Email'),
      '#submit' => ['::sendDueSoonEmail'],
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // This is not used, as each button has its own submit handler.
  }

  /**
   * Submit handler for the "Send Late Fee Email" button.
   */
  public function sendOverdueEmail(array &$form, FormStateInterface $form_state) {
    $transaction_id = $form_state->getValue('transaction_id');
    $transaction = $this->entityTypeManager->getStorage('library_transaction')->load($transaction_id);

    if (!$transaction) {
      $this->messenger()->addError($this->t('Transaction not found.'));
      return;
    }

    // Include the module file to ensure helper functions are available.
    module_load_include('module', 'lending_library');

    // The old generic overdue email was removed. We now test the new late fee email.
    // We pass a dummy amount for testing purposes.
    _lending_library_send_email_by_key($transaction, 'overdue_late_fee', ['amount_due' => 10.00]);
    $this->messenger()->addStatus($this->t('Late fee email sent for transaction %id.', ['%id' => $transaction_id]));
  }

  /**
   * Submit handler for the "Process Non-Return Charge" button.
   */
  public function process30DayOverdue(array &$form, FormStateInterface $form_state) {
    $transaction_id = $form_state->getValue('transaction_id');
    $transaction = $this->entityTypeManager->getStorage('library_transaction')->load($transaction_id);

    if (!$transaction) {
      $this->messenger()->addError($this->t('Transaction not found.'));
      return;
    }

    // Include the module file to ensure helper functions are available.
    module_load_include('module', 'lending_library');

    $config = $this->config('lending_library.settings');
    $non_return_charge_percentage = $config->get('non_return_charge_percentage') ?: 150;

    _lending_library_process_non_return_charge($transaction, $non_return_charge_percentage);
    $this->messenger()->addStatus($this->t('Non-return charge processing complete for transaction %id.', ['%id' => $transaction_id]));
  }

  /**
   * Submit handler for the "Send Due Soon Email" button.
   */
  public function sendDueSoonEmail(array &$form, FormStateInterface $form_state) {
    $transaction_id = $form_state->getValue('transaction_id');
    $transaction = $this->entityTypeManager->getStorage('library_transaction')->load($transaction_id);

    if (!$transaction) {
      $this->messenger()->addError($this->t('Transaction not found.'));
      return;
    }

    // Include the module file to ensure helper functions are available.
    module_load_include('module', 'lending_library');

    _lending_library_send_due_soon_email($transaction);
    $this->messenger()->addStatus($this->t('Due soon email sent for transaction %id.', ['%id' => $transaction_id]));
  }

}
