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

    $form['loan_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Loan Settings'),
      '#open' => TRUE,
    ];

    $form['loan_settings']['loan_period_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Default loan period'),
      '#description' => $this->t('The default number of days a tool can be borrowed for.'),
      '#default_value' => $config->get('loan_period_days') ?: 7,
      '#min' => 1,
    ];

    $form['loan_settings']['loan_terms_html'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Loan terms (HTML shown on checkout form)'),
      '#description' => $this->t('This HTML replaces the agreement block on the Withdraw form. Basic HTML allowed. See available replacement patterns below.'),
      '#default_value' => $config->get('loan_terms_html') ?: '',
      '#rows' => 10,
    ];

    $form['replacement_patterns'] = [
      '#type' => 'details',
      '#title' => $this->t('Available Replacement Patterns'),
    ];
    $items = [
      $this->t('<code>[due_date]</code>: The calculated due date for the loan.'),
      $this->t('<code>[replacement_value]</code>: The replacement value of the tool.'),
      $this->t('<code>[replacement_value_150]</code>: 150% of the replacement value.'),
      $this->t('<code>[amount_due]</code>: The total amount due for an overdue item.'),
      $this->t('<code>[tool_name]</code>: The name of the library item.'),
      $this->t('<code>[borrower_name]</code>: The name of the borrower.'),
      $this->t('<code>[payment_link]</code>: A pre-filled link to the payment system.'),
    ];
    $form['replacement_patterns']['list'] = [
      '#theme' => 'item_list',
      '#items' => $items,
    ];

    $form['email_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Templates'),
      '#open' => TRUE,
    ];

    $form['email_settings']['enable_due_soon_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable "Due Soon" notifications'),
      '#default_value' => $config->get('enable_due_soon_notifications'),
    ];

    $form['email_settings']['enable_overdue_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable "Overdue" notifications'),
      '#default_value' => $config->get('enable_overdue_notifications'),
    ];

    $form['email_settings']['email_checkout_footer'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Checkout email footer'),
      '#description' => $this->t('Appended to the bottom of the checkout confirmation email. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_checkout_footer') ?: '',
      '#rows' => 3,
    ];

    $form['email_settings']['email_return_body'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Return email body'),
      '#description' => $this->t('Inserted into the return confirmation email. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_return_body') ?: '',
      '#rows' => 3,
    ];

    $form['email_settings']['email_issue_notice_intro'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Issue notice intro (staff email)'),
      '#description' => $this->t('First line(s) of the issue notification email sent to staff. Plain text or basic HTML.'),
      '#default_value' => $config->get('email_issue_notice_intro') ?: '',
      '#rows' => 3,
    ];

    $form['email_settings']['email_staff_address'] = [
      '#type' => 'email',
      '#title' => $this->t('Staff notification email address'),
      '#description' => $this->t('The email address to which staff notifications are sent.'),
      '#default_value' => $config->get('email_staff_address') ?: '',
    ];

    $form['email_settings']['overdue'] = [
      '#type' => 'details',
      '#title' => $this->t('Overdue Notification'),
      '#group' => 'email_settings',
    ];
    $form['email_settings']['overdue']['email_overdue_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_overdue_subject') ?: $this->t('Your borrowed tool is overdue'),
    ];
    $form['email_settings']['overdue']['email_overdue_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_overdue_body') ?: $this->t("Hello [borrower_name],\n\nThis is a reminder that the tool '[tool_name]' you borrowed is now overdue. Please return it as soon as possible."),
        '#rows' => 5,
    ];

    $form['email_settings']['overdue_30_day'] = [
        '#type' => 'details',
        '#title' => $this->t('30-Day Overdue Notification (with charge)'),
        '#group' => 'email_settings',
    ];
    $form['email_settings']['overdue_30_day']['email_overdue_30_day_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_overdue_30_day_subject') ?: $this->t('Charge for unreturned library tool'),
    ];
    $form['email_settings']['overdue_30_day']['email_overdue_30_day_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_overdue_30_day_body') ?: $this->t("Hello [borrower_name],\n\nThe tool '[tool_name]' is now 30 days overdue. You are being charged [amount_due] for its replacement. Please use the following link to pay: [payment_link]"),
        '#rows' => 5,
    ];

    $form['email_settings']['due_soon'] = [
      '#type' => 'details',
      '#title' => $this->t('Due Soon Notification (24 hours)'),
      '#group' => 'email_settings',
    ];
    $form['email_settings']['due_soon']['email_due_soon_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_due_soon_subject') ?: $this->t('Your borrowed tool is due soon'),
    ];
    $form['email_settings']['due_soon']['email_due_soon_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_due_soon_body') ?: $this->t("Hello [borrower_name],\n\nThis is a reminder that the tool '[tool_name]' you borrowed is due tomorrow. Please return it on time to avoid late fees."),
        '#rows' => 5,
    ];

    $form['email_settings']['condition_charge'] = [
        '#type' => 'details',
        '#title' => $this->t('Condition Charge Notification'),
        '#group' => 'email_settings',
    ];
    $form['email_settings']['condition_charge']['email_condition_charge_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_condition_charge_subject') ?: $this->t('Charge for tool damage or missing parts'),
    ];
    $form['email_settings']['condition_charge']['email_condition_charge_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_condition_charge_body') ?: $this->t("Hello [borrower_name],\n\nA charge of [amount_due] has been added to your account for the tool '[tool_name]' due to its condition upon return. Please use the following link to pay: [payment_link]"),
        '#rows' => 5,
    ];

    $form['email_settings']['waitlist_notification'] = [
      '#type' => 'details',
      '#title' => $this->t('Waitlist Notification'),
      '#group' => 'email_settings',
    ];
    $form['email_settings']['waitlist_notification']['email_waitlist_notification_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_waitlist_notification_subject') ?: $this->t('A tool you are waiting for is now available'),
    ];
    $form['email_settings']['waitlist_notification']['email_waitlist_notification_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_waitlist_notification_body') ?: $this->t("Hello [borrower_name],\n\nThe tool '[tool_name]' you were waiting for has been returned and is now available for checkout."),
        '#rows' => 5,
    ];

    $form['battery_return_message'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Battery return confirmation message'),
      '#description' => $this->t('This message is shown to the user after they return a battery individually.'),
      '#default_value' => $config->get('battery_return_message') ?: $this->t('Battery has been marked as returned.'),
      '#rows' => 3,
    ];

    return parent::buildForm($form, $form_state);
  }

  public function submitForm(array &$form, FormStateInterface $form_state) {
    $config = $this->config('lending_library.settings');

    // Get values from the form state. Since we are not using #tree => TRUE,
    // the values are at the top level of the form state values array.
    $config
      ->set('loan_period_days', $form_state->getValue('loan_period_days'))
      ->set('loan_terms_html', $form_state->getValue('loan_terms_html'))
      ->set('enable_due_soon_notifications', $form_state->getValue('enable_due_soon_notifications'))
      ->set('enable_overdue_notifications', $form_state->getValue('enable_overdue_notifications'))
      ->set('email_checkout_footer', $form_state->getValue('email_checkout_footer'))
      ->set('email_return_body', $form_state->getValue('email_return_body'))
      ->set('email_issue_notice_intro', $form_state->getValue('email_issue_notice_intro'))
      ->set('email_staff_address', $form_state->getValue('email_staff_address'))
      ->set('email_overdue_subject', $form_state->getValue('email_overdue_subject'))
      ->set('email_overdue_body', $form_state->getValue('email_overdue_body'))
      ->set('email_overdue_30_day_subject', $form_state->getValue('email_overdue_30_day_subject'))
      ->set('email_overdue_30_day_body', $form_state->getValue('email_overdue_30_day_body'))
      ->set('email_due_soon_subject', $form_state->getValue('email_due_soon_subject'))
      ->set('email_due_soon_body', $form_state->getValue('email_due_soon_body'))
      ->set('email_condition_charge_subject', $form_state->getValue('email_condition_charge_subject'))
      ->set('email_condition_charge_body', $form_state->getValue('email_condition_charge_body'))
      ->set('email_waitlist_notification_subject', $form_state->getValue('email_waitlist_notification_subject'))
      ->set('email_waitlist_notification_body', $form_state->getValue('email_waitlist_notification_body'))
      ->set('battery_return_message', $form_state->getValue('battery_return_message'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
