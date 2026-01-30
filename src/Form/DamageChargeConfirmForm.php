<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\eck\EckEntityInterface;
use Drupal\user\Entity\User;
use Drupal\lending_library\Service\LendingLibraryManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Defines a confirmation form for sending a damage charge email.
 */
class DamageChargeConfirmForm extends ConfirmFormBase {

  /**
   * The library transaction entity.
   *
   * @var \Drupal\eck\EckEntityInterface
   */
  protected $libraryTransaction;

  /**
   * The lending library manager service.
   *
   * @var \Drupal\lending_library\Service\LendingLibraryManagerInterface
   */
  protected $libraryManager;

  /**
   * Constructs the confirmation form.
   */
  public function __construct(LendingLibraryManagerInterface $library_manager) {
    $this->libraryManager = $library_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    $instance = parent::create($container);
    $instance->libraryManager = $container->get('lending_library.manager');
    return $instance;
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lending_library_damage_charge_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to send a damage charge notification to the borrower?');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->libraryTransaction->toUrl('canonical');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Send Notification Email');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?EckEntityInterface $library_transaction = NULL) {
    $this->libraryTransaction = $library_transaction;

    $form = parent::buildForm($form, $form_state);

    $amount_due = 0;
    if ($this->libraryTransaction->hasField('field_library_amount_due') && !$this->libraryTransaction->get('field_library_amount_due')->isEmpty()) {
        $amount_due = $this->libraryTransaction->get('field_library_amount_due')->value;
    }

    $borrower_user = NULL;
    if ($this->libraryTransaction->hasField('field_library_borrower') && !$this->libraryTransaction->get('field_library_borrower')->isEmpty()) {
      $borrower_user = User::load($this->libraryTransaction->get('field_library_borrower')->target_id);
    }

    if (!$borrower_user) {
        $this->messenger()->addError($this->t('Cannot send notification because the borrower could not be found.'));
        return $form;
    }

    // Build email preview.
    $form['preview'] = [
        '#type' => 'details',
        '#title' => $this->t('Email Preview'),
        '#open' => TRUE,
    ];

    $form['preview']['to'] = [
        '#type' => 'item',
        '#title' => $this->t('To'),
        '#markup' => $borrower_user->getEmail(),
    ];

    // This is a simplified preview. The real email uses the 'condition_charge' template.
    $form['preview']['subject'] = [
        '#type' => 'item',
        '#title' => $this->t('Subject'),
        '#markup' => $this->t('Charge for tool damage or missing parts'),
    ];

    $form['preview']['body'] = [
        '#type' => 'item',
        '#title' => $this->t('Body'),
        '#markup' => $this->t("A charge of @amount has been added to your account for the tool '@tool_name' due to its condition upon return. Please use the payment link to pay.", [
            '@amount' => number_format($amount_due, 2),
            '@tool_name' => $this->libraryTransaction->get('field_library_item')->entity->label(),
        ]),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $amount_due = 0;
    if ($this->libraryTransaction->hasField('field_library_amount_due') && !$this->libraryTransaction->get('field_library_amount_due')->isEmpty()) {
        $amount_due = $this->libraryTransaction->get('field_library_amount_due')->value;
    }

    if ($amount_due > 0) {
      $this->libraryManager->sendEmailByKey($this->libraryTransaction, 'condition_charge', [
        'amount_due' => $amount_due,
        'transaction_id' => $this->libraryTransaction->id(),
      ]);
      $this->messenger()->addStatus($this->t('Damage charge notification has been sent.'));
    }

    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
