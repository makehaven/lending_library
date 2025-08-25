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
      '#value' => $this->t('Test: Send Overdue Email'),
      '#submit' => ['::sendOverdueEmail'],
    ];

    $form['actions']['process_30_day'] = [
      '#type' => 'submit',
      '#value' => $this->t('Test: Process 30-Day Overdue & Send Email'),
      '#submit' => ['::process30DayOverdue'],
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
   * Submit handler for the "Send Overdue Email" button.
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

    _lending_library_send_overdue_email($transaction);
    $this->messenger()->addStatus($this->t('Overdue email sent for transaction %id.', ['%id' => $transaction_id]));
  }

  /**
   * Submit handler for the "Process 30-Day Overdue" button.
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

    _lending_library_process_30_day_overdue($transaction);
    $this->messenger()->addStatus($this->t('30-day overdue processing complete for transaction %id.', ['%id' => $transaction_id]));
  }

}
