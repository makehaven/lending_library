<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\mh_stripe\Service\StripeHelper;
use Drupal\user\UserInterface;

/**
 * Service for handling Stripe payments in the Lending Library.
 */
class LendingLibraryStripe implements LendingLibraryStripeInterface {

  /**
   * The Stripe helper service.
   *
   * @var \Drupal\mh_stripe\Service\StripeHelper
   */
  protected StripeHelper $stripeHelper;

  /**
   * The config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a new LendingLibraryStripe service.
   *
   * @param \Drupal\mh_stripe\Service\StripeHelper $stripe_helper
   *   The Stripe helper service.
   * @param \Drupal\Core\Config\ConfigFactoryInterface $config_factory
   *   The config factory.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    StripeHelper $stripe_helper,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->stripeHelper = $stripe_helper;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('lending_library');
  }

  /**
   * {@inheritdoc}
   */
  public function hasStripeCustomer(UserInterface $user): bool {
    return !empty($this->getStripeCustomerId($user));
  }

  /**
   * {@inheritdoc}
   */
  public function getStripeCustomerId(UserInterface $user): ?string {
    $field_name = $this->stripeHelper->customerFieldName();
    if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
      return $user->get($field_name)->value;
    }
    return NULL;
  }

  /**
   * {@inheritdoc}
   */
  public function createCharge(
    UserInterface $user,
    float $amount,
    string $description,
    EntityInterface $transaction,
    string $chargeType = 'late_fee'
  ): array {
    $result = [
      'success' => FALSE,
      'payment_intent_id' => NULL,
      'status' => 'failed',
      'error' => NULL,
    ];

    if (!$this->isEnabled()) {
      $result['error'] = 'Stripe integration is not enabled.';
      return $result;
    }

    $customerId = $this->getStripeCustomerId($user);
    if (empty($customerId)) {
      $result['error'] = 'User does not have a linked Stripe customer.';
      return $result;
    }

    // Convert amount to cents for Stripe.
    $amountCents = (int) round($amount * 100);

    if ($amountCents < 50) {
      // Stripe minimum is $0.50 USD.
      $result['error'] = 'Amount is below Stripe minimum ($0.50).';
      return $result;
    }

    try {
      $stripe = $this->stripeHelper->client();

      // Check if customer has a default payment method.
      $customer = $stripe->customers->retrieve($customerId);
      $defaultPaymentMethod = $customer->invoice_settings->default_payment_method ?? NULL;

      if (empty($defaultPaymentMethod)) {
        // Try to get any payment method attached to the customer.
        $paymentMethods = $stripe->paymentMethods->all([
          'customer' => $customerId,
          'type' => 'card',
          'limit' => 1,
        ]);

        if (!empty($paymentMethods->data)) {
          $defaultPaymentMethod = $paymentMethods->data[0]->id;
        }
      }

      if (empty($defaultPaymentMethod)) {
        $result['error'] = 'No payment method on file for this customer.';
        $result['status'] = 'requires_payment_method';
        return $result;
      }

      // Create the PaymentIntent.
      $paymentIntent = $stripe->paymentIntents->create([
        'amount' => $amountCents,
        'currency' => 'usd',
        'customer' => $customerId,
        'payment_method' => $defaultPaymentMethod,
        'off_session' => TRUE,
        'confirm' => TRUE,
        'description' => $description,
        'metadata' => [
          'transaction_id' => $transaction->id(),
          'charge_type' => $chargeType,
          'module' => 'lending_library',
        ],
      ]);

      $result['payment_intent_id'] = $paymentIntent->id;
      $result['status'] = $this->mapStripeStatus($paymentIntent->status);
      $result['success'] = in_array($paymentIntent->status, ['succeeded', 'processing']);

      $this->logger->notice('Stripe charge created: @pi_id for transaction @tid, amount: $@amount, status: @status', [
        '@pi_id' => $paymentIntent->id,
        '@tid' => $transaction->id(),
        '@amount' => $amount,
        '@status' => $paymentIntent->status,
      ]);
    }
    catch (\Stripe\Exception\CardException $e) {
      $result['error'] = 'Card declined: ' . $e->getMessage();
      $result['status'] = 'failed';
      $this->logger->warning('Stripe card error for transaction @tid: @error', [
        '@tid' => $transaction->id(),
        '@error' => $e->getMessage(),
      ]);
    }
    catch (\Stripe\Exception\ApiErrorException $e) {
      $result['error'] = 'Stripe API error: ' . $e->getMessage();
      $result['status'] = 'failed';
      $this->logger->error('Stripe API error for transaction @tid: @error', [
        '@tid' => $transaction->id(),
        '@error' => $e->getMessage(),
      ]);
    }
    catch (\Exception $e) {
      $result['error'] = 'Unexpected error: ' . $e->getMessage();
      $result['status'] = 'failed';
      $this->logger->error('Unexpected Stripe error for transaction @tid: @error', [
        '@tid' => $transaction->id(),
        '@error' => $e->getMessage(),
      ]);
    }

    return $result;
  }

  /**
   * {@inheritdoc}
   */
  public function getChargeStatus(string $paymentIntentId): string {
    if (!$this->isEnabled()) {
      return 'unknown';
    }

    try {
      $stripe = $this->stripeHelper->client();
      $paymentIntent = $stripe->paymentIntents->retrieve($paymentIntentId);
      return $this->mapStripeStatus($paymentIntent->status);
    }
    catch (\Exception $e) {
      $this->logger->error('Error retrieving PaymentIntent @pi_id: @error', [
        '@pi_id' => $paymentIntentId,
        '@error' => $e->getMessage(),
      ]);
      return 'unknown';
    }
  }

  /**
   * {@inheritdoc}
   */
  public function isEnabled(): bool {
    $config = $this->configFactory->get('lending_library.settings');
    return (bool) $config->get('stripe_enabled');
  }

  /**
   * {@inheritdoc}
   */
  public function isCustomerRequiredForCheckout(): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }
    $config = $this->configFactory->get('lending_library.settings');
    return (bool) $config->get('stripe_require_customer_for_checkout');
  }

  /**
   * {@inheritdoc}
   */
  public function getAutoChargeThreshold(): float {
    $config = $this->configFactory->get('lending_library.settings');
    return (float) ($config->get('stripe_auto_charge_threshold') ?: 10.00);
  }

  /**
   * Get the webhook secret for signature verification.
   *
   * @return string
   *   The webhook secret.
   */
  public function getWebhookSecret(): string {
    $config = $this->configFactory->get('lending_library.settings');
    return (string) $config->get('stripe_webhook_secret');
  }

  /**
   * Check if weekly Stripe charging is enabled.
   *
   * @return bool
   *   TRUE if weekly charging is enabled.
   */
  public function isWeeklyChargeEnabled(): bool {
    if (!$this->isEnabled()) {
      return FALSE;
    }
    $config = $this->configFactory->get('lending_library.settings');
    return (bool) $config->get('stripe_weekly_charge_enabled');
  }

  /**
   * Update transaction fields with charge result.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   * @param array $chargeResult
   *   The result from createCharge().
   * @param string $chargeType
   *   The type of charge: 'late_fee', 'premium_fee', 'damage', 'replacement'.
   *
   * @return bool
   *   TRUE if transaction was updated successfully.
   */
  public function updateTransactionWithChargeResult(EntityInterface $transaction, array $chargeResult, string $chargeType = 'late_fee'): bool {
    $updated = FALSE;

    if ($transaction->hasField('field_stripe_payment_intent_id') && !empty($chargeResult['payment_intent_id'])) {
      $transaction->set('field_stripe_payment_intent_id', $chargeResult['payment_intent_id']);
      $updated = TRUE;
    }

    if ($transaction->hasField('field_stripe_charge_status')) {
      $transaction->set('field_stripe_charge_status', $chargeResult['status']);
      $updated = TRUE;
    }

    if ($transaction->hasField('field_stripe_charge_error') && !empty($chargeResult['error'])) {
      $transaction->set('field_stripe_charge_error', $chargeResult['error']);
      $updated = TRUE;
    }

    if ($transaction->hasField('field_stripe_manual_review') && !$chargeResult['success']) {
      $transaction->set('field_stripe_manual_review', TRUE);
      $updated = TRUE;
    }

    // Update the general charges_status field based on charge type.
    if ($transaction->hasField('field_library_charges_status')) {
      if ($chargeResult['success'] || $chargeResult['status'] === 'succeeded') {
        // Map charge type to the appropriate paid status.
        $paid_status_map = [
          'late_fee' => 'late_paid',
          'premium_fee' => 'premium_paid',
          'damage' => 'damage_paid',
          'replacement' => 'replacement_paid',
        ];
        $paid_status = $paid_status_map[$chargeType] ?? 'late_paid';
        $transaction->set('field_library_charges_status', $paid_status);
      }
      elseif (!$chargeResult['success']) {
        $transaction->set('field_library_charges_status', 'payment_error');
      }
    }

    if ($updated) {
      try {
        $transaction->save();
        return TRUE;
      }
      catch (\Exception $e) {
        $this->logger->error('Error saving transaction @tid after charge update: @error', [
          '@tid' => $transaction->id(),
          '@error' => $e->getMessage(),
        ]);
        return FALSE;
      }
    }

    return TRUE;
  }

  /**
   * Map Stripe PaymentIntent status to our simplified status.
   *
   * @param string $stripeStatus
   *   The Stripe status.
   *
   * @return string
   *   Our simplified status (pending, succeeded, failed).
   */
  protected function mapStripeStatus(string $stripeStatus): string {
    $mapping = [
      'succeeded' => 'succeeded',
      'processing' => 'pending',
      'requires_payment_method' => 'failed',
      'requires_confirmation' => 'pending',
      'requires_action' => 'pending',
      'canceled' => 'failed',
      'requires_capture' => 'pending',
    ];

    return $mapping[$stripeStatus] ?? 'pending';
  }

}
