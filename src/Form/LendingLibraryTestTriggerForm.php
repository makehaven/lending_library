<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Url;

/**
 * Provides a form to test sending any of the module's emails with dummy data.
 */
class LendingLibraryTestTriggerForm extends FormBase {

  /**
   * The mail manager.
   *
   * @var \Drupal\Core\Mail\MailManagerInterface
   */
  protected $mailManager;

  /**
   * The language manager.
   *
   * @var \Drupal\Core\Language\LanguageManagerInterface
   */
  protected $languageManager;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected $configFactory;

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a new LendingLibraryTestTriggerForm object.
   */
  public function __construct(MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, ConfigFactoryInterface $config_factory, EntityTypeManagerInterface $entity_type_manager) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->configFactory = $config_factory;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('config.factory'),
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
    $settings_url = Url::fromRoute('lending_library.settings')->toString();
    $form['warning'] = [
      '#markup' => $this->t('<div class="messages messages--warning">For payment links to be generated in test emails, a valid email address must be saved in the <a href=":settings_url">Lending Library Settings</a> page.</div>', [
        ':settings_url' => $settings_url,
      ]),
    ];

    $form['explanation'] = [
      '#markup' => $this->t('<p>This form allows you to send a test version of any email template to an address you specify. The email will be generated with dummy data.</p>'),
    ];

    $form['email_key'] = [
      '#type' => 'select',
      '#title' => $this->t('Email Template to Test'),
      '#options' => [
        'due_soon' => $this->t('Due Soon'),
        'overdue_late_fee' => $this->t('Overdue (Late Fee)'),
        'overdue_30_day' => $this->t('Non-Return Charge'),
        'condition_charge' => $this->t('Condition (Damage) Charge'),
        'checkout_confirmation' => $this->t('Checkout Confirmation'),
        'return_confirmation' => $this->t('Return Confirmation'),
        'waitlist_notification' => $this->t('Waitlist Notification'),
        'issue_report_notice' => $this->t('Issue Report (to Staff)'),
        'late_return_fee' => $this->t('Late Return Fee'),
      ],
      '#required' => TRUE,
    ];

    $form['recipient_email'] = [
      '#type' => 'email',
      '#title' => $this->t('Recipient Email Address'),
      '#description' => $this->t('The email address to send the test email to.'),
      '#required' => TRUE,
    ];

    $form['actions'] = ['#type' => 'actions'];
    $form['actions']['submit'] = [
      '#type' => 'submit',
      '#value' => $this->t('Send Test Email'),
    ];

    return $form;
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $email_key = $form_state->getValue('email_key');
    $recipient_email = $form_state->getValue('recipient_email');

    // Include the module file to ensure helper functions are available.
    module_load_include('module', 'lending_library');

    // Get a random tool to make the test data more realistic.
    $query = $this->entityTypeManager->getStorage('node')->getQuery()
      ->condition('type', 'library_item')
      ->condition('status', 1)
      ->accessCheck(FALSE);
    $nids = $query->execute();
    $tool_name = 'Sample Tool';
    if (!empty($nids)) {
      $random_nid = $nids[array_rand($nids)];
      $tool = $this->entityTypeManager->getStorage('node')->load($random_nid);
      if ($tool) {
        $tool_name = $tool->label();
      }
    }

    // Use the current user as the borrower.
    $borrower_name = $this->currentUser()->getDisplayName();

    // Create params with a mix of real and dummy data.
    $params = [
        'tool_name' => $tool_name,
        'borrower_name' => $borrower_name,
        'amount_due' => 25.50,
        'tool_replacement_charge' => 150.00,
        'unreturned_batteries_charge' => 25.00,
        'replacement_value' => 100.00,
        'due_date' => date('F j, Y', time() + 86400),
        'issue_type' => 'damage',
        'notes' => 'This is a test issue description.',
        'reporter' => 'Test Reporter',
        'item_url' => 'https://example.com/node/1',
        'transaction_id' => 999,
        'days_late' => 5,
        'daily_fee' => 2.50,
        'late_fee_total' => 12.50,
    ];

    // We call the mail manager directly because we are not working with a real
    // transaction entity, which the helper function `_lending_library_send_email_by_key` expects.
    $site_from = $this->configFactory->get('system.site')->get('mail');
    $result = $this->mailManager->mail(
        'lending_library',
        $email_key,
        $recipient_email,
        $this->languageManager->getDefaultLanguage()->getId(),
        $params,
        $site_from,
        TRUE
    );

    if ($result['result']) {
      $this->messenger()->addStatus($this->t('Test email sent to %email.', ['%email' => $recipient_email]));
    }
    else {
      $this->messenger()->addError($this->t('Failed to send test email.'));
    }
  }

}
