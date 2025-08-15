<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

class LendingLibrarySettingsForm extends ConfigFormBase {

  public function getFormId() {
    return 'lending_library_settings_form';
  }

  protected function getEditableConfigNames() {
    return ['lending_library.settings'];
  }

  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('lending_library.settings');

    $form['loan_terms_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Loan terms (HTML shown on checkout form)'),
      '#description' => $this->t('This HTML replaces the agreement block on the Withdraw form. Basic HTML allowed.'),
      '#default_value' => $config->get('loan_terms_html') ?: '',
      '#rows' => 10,
    ];

    $form['email_checkout_footer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Checkout email footer'),
      '#description' => $this->t('Appended to the bottom of the checkout confirmation email. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_checkout_footer') ?: '',
      '#rows' => 3,
    ];

    $form['email_return_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Return email body'),
      '#description' => $this->t('Inserted into the return confirmation email. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_return_body') ?: '',
      '#rows' => 3,
    ];

    $form['email_issue_notice_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Issue notice intro (staff email)'),
      '#description' => $this->t('First line(s) of the issue notification email sent to staff. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_issue_notice_intro') ?: '',
      '#rows' => 3,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->configFactory()->getEditable('lending_library.settings')
      ->set('loan_terms_html', $form_state->getValue('loan_terms_html'))
      ->set('email_checkout_footer', $form_state->getValue('email_checkout_footer'))
      ->set('email_return_body', $form_state->getValue('email_return_body'))
      ->set('email_issue_notice_intro', $form_state->getValue('email_issue_notice_intro'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
