<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\Core\Entity\EntityInterface;
use Drupal\user\Entity\User;

/**
 * Defines a confirmation form for returning a single battery.
 */
class BatteryReturnConfirmForm extends ConfirmFormBase {

  /**
   * The battery entity to be returned.
   *
   * @var \Drupal\Core\Entity\EntityInterface
   */
  protected $battery;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lending_library_battery_return_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to return the battery %label?', ['%label' => $this->battery->label()]);
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    // Redirect to a safe page like the user's profile or the front page.
    return Url::fromRoute('<front>');
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, EntityInterface $battery = NULL) {
    $this->battery = $battery;
    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // 1. Update battery status
    $this->battery->set('field_battery_status', 'available');
    $this->battery->set('field_battery_borrower', NULL);
    $this->battery->set('field_battery_current_item', NULL);

    // Use the module helper to save with revision log.
    if (function_exists('_lending_library_battery_save_with_revision')) {
      _lending_library_battery_save_with_revision(
        $this->battery,
        $this->t('Returned independently (battery only) by user @uid via return form.', ['@uid' => \Drupal::currentUser()->id()])
      );
    }
    else {
      // Fallback if module function not found (unlikely).
      $this->battery->save();
    }

    // 2. Find the transaction and remove the battery from it.
    $query = \Drupal::entityQuery('library_transaction')
      ->condition('field_library_borrow_batteries', $this->battery->id())
      ->sort('created', 'DESC')
      ->range(0, 1)
      ->accessCheck(FALSE);
    $t_ids = $query->execute();

    if (!empty($t_ids)) {
      $transaction = \Drupal::entityTypeManager()->getStorage('library_transaction')->load(reset($t_ids));
      if ($transaction) {
        $borrowed_batteries_items = $transaction->get('field_library_borrow_batteries')->getValue();
        $new_items = [];
        foreach ($borrowed_batteries_items as $item) {
          if ($item['target_id'] != $this->battery->id()) {
            $new_items[] = $item;
          }
        }
        $transaction->set('field_library_borrow_batteries', $new_items);
        $transaction->save();
      }
    }

    $config = $this->config('lending_library.settings');
    $message = $config->get('battery_return_message') ?: $this->t('Battery %label has been returned.', ['%label' => $this->battery->label()]);
    $this->messenger()->addStatus($message);
    $form_state->setRedirectUrl($this->getCancelUrl());
  }

}
