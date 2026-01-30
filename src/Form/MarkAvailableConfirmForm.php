<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\ConfirmFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;
use Drupal\node\NodeInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Defines a confirmation form for marking a library item as available.
 */
class MarkAvailableConfirmForm extends ConfirmFormBase {

  /**
   * The library item node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $node;

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'lending_library_mark_available_confirm_form';
  }

  /**
   * {@inheritdoc}
   */
  public function getQuestion() {
    return $this->t('Are you sure you want to mark %title as Available (On Shelf)?', [
      '%title' => $this->node->label(),
    ]);
  }

  /**
   * {@inheritdoc}
   */
  public function getDescription() {
    return $this->t('This will clear the current borrower and set the item status to "Available". Use this action if an item was returned but not properly checked in. You can optionally add a note about this action.');
  }

  /**
   * {@inheritdoc}
   */
  public function getConfirmText() {
    return $this->t('Mark as Available');
  }

  /**
   * {@inheritdoc}
   */
  public function getCancelUrl() {
    return $this->node->toUrl();
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state, ?NodeInterface $node = NULL) {
    $this->node = $node;

    $form['note'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Note (Optional)'),
      '#description' => $this->t('Add a reason or observation for this manual status change.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $note = $form_state->getValue('note');

    try {
      // Logic moved from LibraryTransactionController::markAvailable.
      $this->node->set('field_library_item_status', 'available');
      $this->node->set('field_library_item_borrower', NULL);
      
      if ($this->node->hasField('field_item_available_since')) {
        $this->node->set('field_item_available_since', (new DrupalDateTime('now'))->format('Y-m-d\TH:i:s'));
      }

      // If a note was provided, we might want to log it. 
      // Since we don't have a direct "log" field on the item for this specific action easily accessible 
      // without creating a transaction, let's log it to the Drupal watchdog for now, 
      // and potentially create a 'system' transaction if we wanted to be very thorough.
      // For now, watchdog is sufficient as requested "ability to log note".
      if (!empty($note)) {
        \Drupal::logger('lending_library')->info('Item %title marked available manually. Note: @note', [
          '%title' => $this->node->label(),
          '@note' => $note,
        ]);
      }

      $this->node->save();
      $this->messenger()->addStatus($this->t('The item %title has been marked as available.', ['%title' => $this->node->label()]));
    }
    catch (\Exception $e) {
      $this->messenger()->addError($this->t('Failed to mark item as available: @message', ['@message' => $e->getMessage()]));
    }

    $form_state->setRedirectUrl($this->node->toUrl());
  }

}
