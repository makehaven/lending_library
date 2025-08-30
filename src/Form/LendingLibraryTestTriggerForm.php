<?php

namespace Drupal\lending_library\Form;

use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\Core\Config\ConfigFactoryInterface;

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
   * Constructs a new LendingLibraryTestTriggerForm object.
   */
  public function __construct(MailManagerInterface $mail_manager, LanguageManagerInterface $language_manager, ConfigFactoryInterface $config_factory) {
    $this->mailManager = $mail_manager;
    $this->languageManager = $language_manager;
    $this->configFactory = $config_factory;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('plugin.manager.mail'),
      $container->get('language_manager'),
      $container->get('config.factory')
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

    // Create a dummy transaction-like object.
    $transaction = new \stdClass();
    $transaction->id = 999;
    $transaction->get = function($field_name) {
        $fields = [
            'field_library_item' => (object) [
                'entity' => (object) [
                    'label' => 'Test Tool 123',
                ],
            ],
            'field_library_borrower' => (object) [
                'target_id' => 1,
            ],
            'field_library_amount_due' => (object) ['value' => 25.50],
        ];
        return $fields[$field_name] ?? NULL;
    };

    // Create dummy params.
    $params = [
        'amount_due' => 25.50,
        'tool_replacement_charge' => 150.00,
        'unreturned_batteries_charge' => 25.00,
        'replacement_value' => 100.00,
        'due_date' => date('F j, Y', time() + 86400),
        'issue_type' => 'damage',
        'notes' => 'This is a test issue description.',
        'reporter' => 'Test Reporter',
        'item_url' => 'https://example.com/node/1',
    ];

    // The send email function needs a real user object for name and email.
    // We'll create a dummy one and override the email with the one from the form.
    $dummy_user = new \stdClass();
    $dummy_user->getDisplayName = function() { return 'Test Borrower'; };
    $dummy_user->getEmail = function() use ($recipient_email) { return $recipient_email; };
    $dummy_user->getPreferredLangcode = function() { return $this->languageManager->getDefaultLanguage()->getId(); };

    $params['borrower_user'] = $dummy_user;

    // The function expects an entity, so we need to mock that part.
    // We'll create a mock that can return the dummy user.
    $mock_transaction = new \stdClass();
    $mock_transaction->get = function ($field) use ($dummy_user) {
      if ($field === 'field_library_borrower') {
        return (object)['entity' => $dummy_user];
      }
      if ($field === 'field_library_item') {
        return (object)['entity' => (object)['label' => 'Test Tool 123']];
      }
      return (object)['value' => 0];
    };

    // We can't easily mock the User::load call inside the helper.
    // So, we'll call the mail manager directly, which is what the helper does.
    $params['transaction_id'] = 999;
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
