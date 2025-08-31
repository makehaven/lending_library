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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Loan terms (HTML shown on checkout form)'),
      '#description' => $this->t('This HTML replaces the agreement block on the Withdraw form. Basic HTML allowed. See available replacement patterns below.'),
      '#default_value' => $config->get('loan_terms_html')['value'] ?: '',
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
      '#access' => \Drupal::currentUser()->hasPermission('administer lending library payment configuration'),
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_due_soon_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #0056a0;">Reminder: Your Tool is Due Soon</h2>
        <p>Hi [borrower_name],</p>
        <p>This is a friendly reminder that the following tool is due for return on <strong>[due_date]</strong>:</p>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Tool:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[tool_name]</td>
            </tr>
        </table>
        <p>Please return it on time to avoid any late fees. You can see your borrowed items on your library page.</p>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_overdue_late_fee_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #d9534f;">Notice: Overdue Item</h2>
        <p>Hi [borrower_name],</p>
        <p>The tool you borrowed, <strong>[tool_name]</strong>, is now overdue. A late fee of <strong>[amount_due]</strong> has been applied to your account.</p>
        <p>Please return the tool as soon as possible to avoid further fees. Any outstanding balance will be due upon return of the tool or when the loan period expires.</p>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_non_return_charge_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #d9534f;">Notice: Lost Item Charge</h2>
        <p>Hi [borrower_name],</p>
        <p>The tool, <strong>[tool_name]</strong>, is now considered lost since it has not been returned. You are being charged for its replacement.</p>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Tool Replacement:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[tool_replacement_charge]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Unreturned Batteries:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[unreturned_batteries_charge]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Total Due:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><strong>[amount_due]</strong></td>
            </tr>
        </table>
        <p>Please use the following link to pay:</p>
        <table style="width: 100%; text-align: center; margin: 20px 0;">
            <tr>
                <td>
                    <a href="[payment_link]" style="background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Pay Now</a>
                </td>
            </tr>
        </table>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_condition_charge_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #d9534f;">Notice: Damage/Missing Parts Charge</h2>
        <p>Hi [borrower_name],</p>
        <p>A charge of <strong>[amount_due]</strong> has been added to your account for the tool, <strong>[tool_name]</strong>, due to its condition upon return.</p>
        <p>Please use the following link to pay:</p>
        <table style="width: 100%; text-align: center; margin: 20px 0;">
            <tr>
                <td>
                    <a href="[payment_link]" style="background-color: #007bff; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px; display: inline-block;">Pay Now</a>
                </td>
            </tr>
        </table>
        <p>If you have any questions, please contact the staff.</p>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_waitlist_notification_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #5cb85c;">Great News! A Tool is Available!</h2>
        <p>Hi [borrower_name],</p>
        <p>The tool, <strong>[tool_name]</strong>, that you were on the waitlist for has been returned and is now available for checkout.</p>
        <p>Please be aware that this notification is sent to everyone on the waitlist. The tool is available on a first-come, first-served basis.</p>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_checkout_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #0056a0;">Checkout Confirmation</h2>
        <p>Hi [borrower_name],</p>
        <p>You have successfully checked out the following tool:</p>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Tool:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[tool_name]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Replacement Value:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[replacement_value]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Due Date:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[due_date]</td>
            </tr>
        </table>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_return_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #5cb85c;">Return Confirmed</h2>
        <p>Hi [borrower_name],</p>
        <p>Thanks! Your return for <strong>[tool_name]</strong> has been recorded.</p>
        <p>We appreciate you helping to keep our library running smoothly!</p>
        <p>Thanks,<br>The MakeHaven Team</p>
    </div>
</div>',
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
      '#type' => 'text_format',
      '#format' => 'full_html',
      '#title' => $this->t('Body'),
      '#default_value' => $config->get('email_issue_report_body')['value'] ?: '<div style="font-family: sans-serif; padding: 20px; background-color: #f4f4f4; color: #333;">
    <div style="max-width: 600px; margin: auto; background: white; padding: 20px; border-radius: 5px;">
        <h2 style="color: #d9534f;">New Issue Report</h2>
        <p>A member has submitted an issue report for a library item.</p>
        <table style="width: 100%; border-collapse: collapse; margin: 20px 0;">
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Tool:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[tool_name]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Issue Type:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[issue_type]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Details:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[notes]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Reported by:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;">[reporter]</td>
            </tr>
            <tr>
                <td style="padding: 10px; border: 1px solid #ddd; background-color: #f9f9f9;"><strong>Item Page:</strong></td>
                <td style="padding: 10px; border: 1px solid #ddd;"><a href="[item_url]">[item_url]</a></td>
            </tr>
        </table>
    </div>
</div>',
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
