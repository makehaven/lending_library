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

    $form['loan_settings']['prevent_checkout_with_debt'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prevent checkout with outstanding debt'),
      '#description' => $this->t('If checked, users will not be able to check out new items if they have any unpaid fees.'),
      '#default_value' => $config->get('prevent_checkout_with_debt'),
    ];

    $form['loan_settings']['prevent_checkout_with_overdue'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Prevent checkout with overdue items'),
      '#description' => $this->t('If checked, users will not be able to check out new items if they have any items that are currently overdue.'),
      '#default_value' => $config->get('prevent_checkout_with_overdue'),
    ];

    $form['loan_settings']['max_tool_count'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum tool count'),
      '#description' => $this->t('The maximum number of tools a user can have checked out at one time. Set to 0 for no limit.'),
      '#default_value' => $config->get('max_tool_count') ?: 0,
      '#min' => 0,
    ];

    $form['loan_settings']['max_tool_value'] = [
      '#type' => 'number',
      '#title' => $this->t('Maximum tool value'),
      '#description' => $this->t('The maximum total value of tools a user can have checked out at one time. Set to 0 for no limit.'),
      '#default_value' => $config->get('max_tool_value') ?: 0,
      '#min' => 0,
      '#step' => '0.01',
      '#field_prefix' => '$',
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

    $items_general = [
      $this->t('<code>[tool_name]</code>: The name of the library item.'),
      $this->t('<code>[borrower_name]</code>: The name of the borrower.'),
    ];

    $items_dates = [
      $this->t('<code>[due_date]</code>: The calculated due date for the loan.'),
    ];

    $items_charges = [
      $this->t('<code>[replacement_value]</code>: The base replacement value of the tool.'),
      $this->t('<code>[tool_replacement_charge]</code>: The calculated replacement charge for the tool, including any markup.'),
      $this->t('<code>[unreturned_batteries_charge]</code>: The calculated replacement charge for any unreturned batteries.'),
      $this->t('<code>[amount_due]</code>: The total amount due (tool charge + battery charge + other fees).'),
      $this->t('<code>[payment_link]</code>: A pre-filled link to the payment system.'),
    ];

    $items_issue = [
      $this->t('<code>[issue_type]</code>: The type of issue reported.'),
      $this->t('<code>[notes]</code>: The details of the issue reported.'),
      $this->t('<code>[reporter]</code>: The name of the user who reported the issue.'),
      $this->t('<code>[item_url]</code>: The URL of the library item page.'),
    ];

    $form['replacement_patterns']['general_list'] = [
      '#prefix' => '<h4>' . $this->t('General') . '</h4>',
      '#theme' => 'item_list',
      '#items' => $items_general,
    ];

    $form['replacement_patterns']['dates_list'] = [
      '#prefix' => '<h4>' . $this->t('Dates (available in loan-related emails)') . '</h4>',
      '#theme' => 'item_list',
      '#items' => $items_dates,
    ];

    $form['replacement_patterns']['charges_list'] = [
      '#prefix' => '<h4>' . $this->t('Charges (available in charge-related emails)') . '</h4>',
      '#theme' => 'item_list',
      '#items' => $items_charges,
    ];

    $form['replacement_patterns']['issue_list'] = [
      '#prefix' => '<h4>' . $this->t('Issue Reports (available in issue-related emails)') . '</h4>',
      '#theme' => 'item_list',
      '#items' => $items_issue,
    ];

    $form['email_settings'] = [
      '#type' => 'details',
      '#title' => $this->t('Email Settings'),
      '#open' => TRUE,
    ];

    $form['email_settings']['intro'] = [
      '#type' => 'markup',
      '#markup' => '<p>' . $this->t("This section allows you to customize the emails sent by the Lending Library module. You can use the replacement patterns listed below to include dynamic information in your emails.") . '</p>',
    ];

    $form['email_settings']['user_facing'] = [
      '#type' => 'details',
      '#title' => $this->t('User-Facing Email Workflow'),
      '#open' => TRUE,
    ];

    $form['email_settings']['user_facing']['waitlist'] = $form['email_settings']['other_templates']['waitlist'];
    $form['email_settings']['user_facing']['waitlist']['#title'] = $this->t('1. Waitlist Notification');

    $form['email_settings']['user_facing']['checkout'] = $form['email_settings']['other_templates']['checkout'];
    $form['email_settings']['user_facing']['checkout']['#title'] = $this->t('2. Checkout Confirmation Email');

    $form['email_settings']['user_facing']['due_soon'] = $form['email_settings']['due_soon'];
    $form['email_settings']['user_facing']['due_soon']['#title'] = $this->t('3. Due Soon Notification');

    $form['email_settings']['user_facing']['return'] = $form['email_settings']['other_templates']['return'];
    $form['email_settings']['user_facing']['return']['#title'] = $this->t('4. Standard Return Confirmation');

    $form['email_settings']['user_facing']['late_return_fee'] = $form['email_settings']['other_templates']['late_return_fee'];
    $form['email_settings']['user_facing']['late_return_fee']['#title'] = $this->t('5. Late Return (with fee)');

    $form['email_settings']['user_facing']['non_return_email'] = $form['email_settings']['overdue']['non_return_email'];
    $form['email_settings']['user_facing']['non_return_email']['#title'] = $this->t('6. Non-Return (Lost Tool)');

    $form['email_settings']['user_facing']['condition_charge'] = $form['email_settings']['other_templates']['condition_charge'];
    $form['email_settings']['user_facing']['condition_charge']['#title'] = $this->t('7. Tool Condition Charge');

    // Administrative emails.
    $form['email_settings']['admin_facing'] = [
      '#type' => 'details',
      '#title' => $this->t('Administrative Notifications'),
      '#open' => TRUE,
    ];

    $form['email_settings']['admin_facing']['general'] = $form['email_settings']['general'];
    $form['email_settings']['admin_facing']['general']['#title'] = $this->t('General');

    $form['email_settings']['admin_facing']['damaged'] = $form['email_settings']['other_templates']['issue_report']['damaged'];
    $form['email_settings']['admin_facing']['damaged']['#title'] = $this->t('Damaged Item');

    $form['email_settings']['admin_facing']['issue_report'] = $form['email_settings']['other_templates']['issue_report'];
    $form['email_settings']['admin_facing']['issue_report']['#title'] = $this->t('Issue Report (Other)');

    // Unset the old structure
    unset($form['email_settings']['due_soon']);
    unset($form['email_settings']['overdue']);
    unset($form['email_settings']['other_templates']);
    unset($form['email_settings']['general']);

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
      'prevent_checkout_with_debt',
      'prevent_checkout_with_overdue',
      'max_tool_count',
      'max_tool_value',
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
      'email_damaged_subject',
      'email_damaged_body',
      'email_damaged_address',
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
