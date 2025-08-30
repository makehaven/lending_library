<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

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

    $form['fee_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Fee and Fine Settings'),
      '#open' => TRUE,
    ];

    $form['fee_settings']['explanation'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t("The system checks for overdue items daily. If an item is overdue, a <strong>Daily Late Fee</strong> is applied for each day it is late. The total late fees for an item will not exceed the <strong>Late Fee Cap Percentage</strong> of the tool's replacement value. If an item is not returned after the configured <strong>Days until Final Non-Return Charge</strong>, the system will instead apply the full <strong>Non-Return Charge Percentage</strong> of the tool's value.") . '</p>',
    ];

    $form['fee_settings']['daily_late_fee'] = [
      '#type' => 'number',
      '#title' => $this->t('Daily Late Fee'),
      '#description' => $this->t('The amount to charge per day for late items. Set to 0 to disable daily late fees.'),
      '#default_value' => $config->get('daily_late_fee') ?: 10,
      '#min' => 0,
      '#step' => '0.01',
      '#field_prefix' => '$',
    ];

    $form['fee_settings']['late_fee_cap_percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Late Fee Cap Percentage'),
      '#description' => $this->t('The maximum late fee that can be charged, as a percentage of the tool\'s replacement value. For example, enter 50 for 50%.'),
      '#default_value' => $config->get('late_fee_cap_percentage') ?: 50,
      '#min' => 0,
      '#max' => 100,
      '#field_suffix' => '%',
    ];

    $form['fee_settings']['overdue_charge_days'] = [
      '#type' => 'number',
      '#title' => $this->t('Days until Final Non-Return Charge'),
      '#description' => $this->t('The number of days after the due date to charge for a non-returned tool.'),
      '#default_value' => $config->get('overdue_charge_days') ?: 30,
      '#min' => 1,
    ];

    $form['fee_settings']['non_return_charge_percentage'] = [
      '#type' => 'number',
      '#title' => $this->t('Non-Return Charge Percentage'),
      '#description' => $this->t('The percentage of the tool\'s replacement value to charge when it is not returned. For example, enter 150 for 150%.'),
      '#default_value' => $config->get('non_return_charge_percentage') ?: 150,
      '#min' => 0,
      '#field_suffix' => '%',
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
      $this->t('<code>[replacement_value]</code>: The base replacement value of the tool.'),
      $this->t('<code>[tool_replacement_charge]</code>: The calculated replacement charge for the tool, including any markup.'),
      $this->t('<code>[unreturned_batteries_charge]</code>: The calculated replacement charge for any unreturned batteries.'),
      $this->t('<code>[amount_due]</code>: The total amount due (tool charge + battery charge + other fees).'),
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
      '#title' => $this->t('Email Settings'),
      '#open' => TRUE,
    ];

    $form['email_settings']['general'] = [
      '#type' => 'details',
      '#title' => $this->t('General Email Settings'),
      '#open' => TRUE,
    ];

    $form['email_settings']['general']['email_staff_address'] = [
      '#type' => 'email',
      '#title' => $this->t('Staff notification email address'),
      '#description' => $this->t('The email address to which staff notifications are sent.'),
      '#default_value' => $config->get('email_staff_address') ?: '',
    ];

    $form['email_settings']['general']['paypal_email'] = [
      '#type' => 'email',
      '#title' => $this->t('PayPal Email Address'),
      '#description' => $this->t('The email address for your PayPal account to receive payments. You must also configure your PayPal account to send Instant Payment Notifications (IPN) to the following URL: @ipn_url', [
        '@ipn_url' => Url::fromRoute('lending_library.paypal_ipn_listener', [], ['absolute' => TRUE])->toString(),
      ]),
      '#default_value' => $config->get('paypal_email') ?: '',
    ];

    // Due Soon Notifications
    $form['email_settings']['due_soon'] = [
      '#type' => 'details',
      '#title' => $this->t('Due Soon Notification'),
      '#description' => $this->t('Sent to the borrower 24 hours before their loan is due.'),
      '#open' => TRUE,
    ];
    $form['email_settings']['due_soon']['enable_due_soon_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable "Due Soon" notifications'),
      '#default_value' => $config->get('enable_due_soon_notifications'),
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

    // Overdue Notifications
    $form['email_settings']['overdue'] = [
      '#type' => 'details',
      '#title' => $this->t('Overdue Notifications'),
      '#description' => $this->t('Sent to the borrower when an item becomes overdue.'),
      '#open' => TRUE,
    ];
    $form['email_settings']['overdue']['enable_overdue_notifications'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Enable "Overdue" notifications'),
      '#default_value' => $config->get('enable_overdue_notifications'),
    ];

    // Daily Late Fee Email
    $form['email_settings']['overdue']['late_fee_email'] = [
      '#type' => 'details',
      '#title' => $this->t('Daily Late Fee Email'),
      '#description' => $this->t('Sent once when the first daily late fee is applied.'),
    ];
    $form['email_settings']['overdue']['late_fee_email']['email_overdue_late_fee_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_overdue_late_fee_subject') ?: $this->t('Late fee added for overdue tool'),
    ];
    $form['email_settings']['overdue']['late_fee_email']['email_overdue_late_fee_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_overdue_late_fee_body') ?: $this->t("Hello [borrower_name],\n\nThe tool '[tool_name]' you borrowed is overdue. A late fee of [amount_due] has been applied to your account. Please return the tool as soon as possible to avoid further fees. You can pay the current balance here: [payment_link]"),
        '#rows' => 5,
    ];

    // Non-Return Charge Email
    $form['email_settings']['overdue']['non_return_email'] = [
        '#type' => 'details',
        '#title' => $this->t('Lost Tool Replacement Charge Email'),
        '#description' => $this->t('Sent when an item is overdue by the "Days until Final Non-Return Charge".'),
    ];
    $form['email_settings']['overdue']['non_return_email']['email_non_return_charge_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_non_return_charge_subject') ?: $this->t('Charge for unreturned library tool'),
    ];
    $form['email_settings']['overdue']['non_return_email']['email_non_return_charge_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_non_return_charge_body') ?: $this->t("Hello [borrower_name],\n\nThe tool '[tool_name]' is now considered lost. You are being charged [amount_due] for its replacement. Please use the following link to pay: [payment_link]"),
        '#rows' => 5,
    ];

    // Other Email Templates
    $form['email_settings']['other_templates'] = [
      '#type' => 'details',
      '#title' => $this->t('Other Email Templates'),
      '#open' => FALSE,
    ];
    $form['email_settings']['other_templates']['condition_charge'] = [
        '#type' => 'details',
        '#title' => $this->t('Condition Charge Notification'),
        '#description' => $this->t('Sent via the confirmation form when an admin applies a manual charge for damage or missing parts.'),
    ];
    $form['email_settings']['other_templates']['condition_charge']['email_condition_charge_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_condition_charge_subject') ?: $this->t('Charge for tool damage or missing parts'),
    ];
    $form['email_settings']['other_templates']['condition_charge']['email_condition_charge_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_condition_charge_body') ?: $this->t("Hello [borrower_name],\n\nA charge of [amount_due] has been added to your account for the tool '[tool_name]' due to its condition upon return. Please use the following link to pay: [payment_link]"),
        '#rows' => 5,
    ];

    $form['email_settings']['other_templates']['waitlist'] = [
      '#type' => 'details',
      '#title' => $this->t('Waitlist Notification'),
      '#description' => $this->t('Sent to the next person on the waitlist when a borrowed item is returned.'),
    ];
    $form['email_settings']['other_templates']['waitlist']['email_waitlist_notification_subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_waitlist_notification_subject') ?: $this->t('A tool you are waiting for is now available'),
    ];
    $form['email_settings']['other_templates']['waitlist']['email_waitlist_notification_body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_waitlist_notification_body') ?: $this->t("Hello [borrower_name],\n\nThe tool '[tool_name]' you were waiting for has been returned and is now available for checkout."),
        '#rows' => 5,
    ];

    $form['email_settings']['other_templates']['checkout'] = [
      '#type' => 'details',
      '#title' => $this->t('Checkout Confirmation Email'),
    ];
    $form['email_settings']['other_templates']['checkout']['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_checkout_subject') ?: $this->t('Tool Checkout Confirmation: [tool_name]'),
    ];
    $form['email_settings']['other_templates']['checkout']['body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_checkout_body') ?: "You have successfully checked out the following tool:\n\nTool: [tool_name]\nReplacement Value: $[replacement_value]\nDue on or before: [due_date].",
        '#rows' => 5,
    ];

    $form['email_settings']['other_templates']['return'] = [
      '#type' => 'details',
      '#title' => $this->t('Return Confirmation Email'),
    ];
    $form['email_settings']['other_templates']['return']['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_return_subject') ?: $this->t('Tool Return Confirmation'),
    ];
    $form['email_settings']['other_templates']['return']['body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_return_body') ?: "Thanks! Your return has been recorded.\nTool: [tool_name]",
        '#rows' => 5,
    ];

    $form['email_settings']['other_templates']['issue_report'] = [
      '#type' => 'details',
      '#title' => $this->t('Issue Report Email (to staff)'),
    ];
    $form['email_settings']['other_templates']['issue_report']['subject'] = [
        '#type' => 'textfield',
        '#title' => $this->t('Subject'),
        '#default_value' => $config->get('email_issue_report_subject') ?: $this->t('Lending Library Issue Report: [tool_name]'),
    ];
    $form['email_settings']['other_templates']['issue_report']['body'] = [
        '#type' => 'textarea',
        '#title' => $this->t('Body'),
        '#default_value' => $config->get('email_issue_report_body') ?: "A member submitted an issue report.\n\nTool: [tool_name]\nIssue type: [issue_type]\nDetails: [notes]\nReported by: [reporter]\nItem page: [item_url]",
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

    // Even though the form is nested visually, #tree is not used, so the
    // values are at the top level of the form state.
    $values = $form_state->getValues();
    $keys_to_save = [
      'loan_period_days',
      'daily_late_fee',
      'late_fee_cap_percentage',
      'overdue_charge_days',
      'non_return_charge_percentage',
      'loan_terms_html',
      'battery_return_message',
      'email_staff_address',
      'paypal_email',
      'enable_due_soon_notifications',
      'email_due_soon_subject',
      'email_due_soon_body',
      'enable_overdue_notifications',
      'email_overdue_late_fee_subject',
      'email_overdue_late_fee_body',
      'email_non_return_charge_subject',
      'email_non_return_charge_body',
      'email_condition_charge_subject',
      'email_condition_charge_body',
      'email_waitlist_notification_subject',
      'email_waitlist_notification_body',
      'email_checkout_subject',
      'email_checkout_body',
      'email_return_subject',
      'email_return_body',
      'email_issue_report_subject',
      'email_issue_report_body',
    ];

    foreach ($keys_to_save as $key) {
        if (isset($values[$key])) {
            $config->set($key, $values[$key]);
        }
    }

    $config->save();

    parent::submitForm($form, $form_state);
  }
}
