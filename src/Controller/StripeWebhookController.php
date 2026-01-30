<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\lending_library\Service\LendingLibraryStripe;
use Drupal\mh_stripe\Service\StripeHelper;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Controller for handling Stripe webhook events.
 */
class StripeWebhookController extends ControllerBase {

  /**
   * The Stripe helper service.
   *
   * @var \Drupal\mh_stripe\Service\StripeHelper
   */
  protected StripeHelper $stripeHelper;

  /**
   * The lending library Stripe service.
   *
   * @var \Drupal\lending_library\Service\LendingLibraryStripe
   */
  protected LendingLibraryStripe $lendingStripe;

  /**
   * The logger.
   *
   * @var \Psr\Log\LoggerInterface
   */
  protected $logger;

  /**
   * Constructs a StripeWebhookController.
   *
   * @param \Drupal\mh_stripe\Service\StripeHelper $stripe_helper
   *   The Stripe helper service.
   * @param \Drupal\lending_library\Service\LendingLibraryStripe $lending_stripe
   *   The lending library Stripe service.
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   * @param \Drupal\Core\Logger\LoggerChannelFactoryInterface $logger_factory
   *   The logger factory.
   */
  public function __construct(
    StripeHelper $stripe_helper,
    LendingLibraryStripe $lending_stripe,
    EntityTypeManagerInterface $entity_type_manager,
    LoggerChannelFactoryInterface $logger_factory
  ) {
    $this->stripeHelper = $stripe_helper;
    $this->lendingStripe = $lending_stripe;
    $this->entityTypeManager = $entity_type_manager;
    $this->logger = $logger_factory->get('lending_library');
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('mh_stripe.helper'),
      $container->get('lending_library.stripe'),
      $container->get('entity_type.manager'),
      $container->get('logger.factory')
    );
  }

  /**
   * Handle incoming Stripe webhook events.
   *
   * @param \Symfony\Component\HttpFoundation\Request $request
   *   The incoming request.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  public function webhook(Request $request): Response {
    $payload = $request->getContent();
    $sig_header = $request->headers->get('Stripe-Signature');
    $webhook_secret = $this->lendingStripe->getWebhookSecret();

    // If no webhook secret configured, log and accept (for development).
    if (empty($webhook_secret)) {
      $this->logger->warning('Stripe webhook received but no webhook secret configured. Accepting without verification.');
      return $this->processPayload($payload);
    }

    // Verify the webhook signature.
    try {
      $event = \Stripe\Webhook::constructEvent(
        $payload,
        $sig_header,
        $webhook_secret
      );
    }
    catch (\Stripe\Exception\SignatureVerificationException $e) {
      $this->logger->error('Stripe webhook signature verification failed: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Invalid signature', 400);
    }
    catch (\Exception $e) {
      $this->logger->error('Stripe webhook parsing error: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Invalid payload', 400);
    }

    return $this->handleEvent($event);
  }

  /**
   * Process a webhook payload without signature verification.
   *
   * @param string $payload
   *   The raw JSON payload.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function processPayload(string $payload): Response {
    try {
      $data = json_decode($payload, TRUE);
      if (!$data || !isset($data['type'])) {
        return new Response('Invalid payload', 400);
      }

      // Manually create event-like structure.
      $event = (object) [
        'type' => $data['type'],
        'data' => (object) ['object' => (object) $data['data']['object']],
      ];

      return $this->handleEvent($event);
    }
    catch (\Exception $e) {
      $this->logger->error('Error processing webhook payload: @error', [
        '@error' => $e->getMessage(),
      ]);
      return new Response('Error processing payload', 500);
    }
  }

  /**
   * Handle a Stripe event.
   *
   * @param object $event
   *   The Stripe event object.
   *
   * @return \Symfony\Component\HttpFoundation\Response
   *   The response.
   */
  protected function handleEvent(object $event): Response {
    $this->logger->notice('Stripe webhook received: @type', [
      '@type' => $event->type,
    ]);

    switch ($event->type) {
      case 'payment_intent.succeeded':
        $this->handlePaymentIntentSucceeded($event->data->object);
        break;

      case 'payment_intent.payment_failed':
        $this->handlePaymentIntentFailed($event->data->object);
        break;

      default:
        // Log unhandled event types for debugging.
        $this->logger->info('Unhandled Stripe webhook event type: @type', [
          '@type' => $event->type,
        ]);
    }

    return new Response('OK', 200);
  }

  /**
   * Handle payment_intent.succeeded event.
   *
   * @param object $paymentIntent
   *   The PaymentIntent object.
   */
  protected function handlePaymentIntentSucceeded(object $paymentIntent): void {
    $metadata = (array) ($paymentIntent->metadata ?? []);

    // Only process lending library charges.
    if (($metadata['module'] ?? '') !== 'lending_library') {
      return;
    }

    $transactionId = $metadata['transaction_id'] ?? NULL;
    if (!$transactionId) {
      $this->logger->warning('Payment intent @pi_id succeeded but no transaction_id in metadata.', [
        '@pi_id' => $paymentIntent->id,
      ]);
      return;
    }

    $transaction = $this->loadTransaction($transactionId);
    if (!$transaction) {
      $this->logger->error('Payment intent @pi_id succeeded but transaction @tid not found.', [
        '@pi_id' => $paymentIntent->id,
        '@tid' => $transactionId,
      ]);
      return;
    }

    // Update transaction fields.
    if ($transaction->hasField('field_stripe_charge_status')) {
      $transaction->set('field_stripe_charge_status', 'succeeded');
    }
    if ($transaction->hasField('field_stripe_manual_review')) {
      $transaction->set('field_stripe_manual_review', FALSE);
    }
    if ($transaction->hasField('field_library_charges_status')) {
      $transaction->set('field_library_charges_status', 'late_paid');
    }
    if ($transaction->hasField('field_library_amount_paid')) {
      $amountPaid = ($paymentIntent->amount_received ?? 0) / 100;
      $transaction->set('field_library_amount_paid', $amountPaid);
    }

    try {
      $transaction->save();
      $this->logger->notice('Transaction @tid updated after successful payment @pi_id.', [
        '@tid' => $transactionId,
        '@pi_id' => $paymentIntent->id,
      ]);
    }
    catch (\Exception $e) {
      $this->logger->error('Error saving transaction @tid after payment success: @error', [
        '@tid' => $transactionId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Handle payment_intent.payment_failed event.
   *
   * @param object $paymentIntent
   *   The PaymentIntent object.
   */
  protected function handlePaymentIntentFailed(object $paymentIntent): void {
    $metadata = (array) ($paymentIntent->metadata ?? []);

    // Only process lending library charges.
    if (($metadata['module'] ?? '') !== 'lending_library') {
      return;
    }

    $transactionId = $metadata['transaction_id'] ?? NULL;
    if (!$transactionId) {
      $this->logger->warning('Payment intent @pi_id failed but no transaction_id in metadata.', [
        '@pi_id' => $paymentIntent->id,
      ]);
      return;
    }

    $transaction = $this->loadTransaction($transactionId);
    if (!$transaction) {
      $this->logger->error('Payment intent @pi_id failed but transaction @tid not found.', [
        '@pi_id' => $paymentIntent->id,
        '@tid' => $transactionId,
      ]);
      return;
    }

    // Get error message from last payment error.
    $errorMessage = 'Payment failed';
    if (isset($paymentIntent->last_payment_error)) {
      $errorMessage = $paymentIntent->last_payment_error->message ?? 'Unknown error';
    }

    // Update transaction fields.
    if ($transaction->hasField('field_stripe_charge_status')) {
      $transaction->set('field_stripe_charge_status', 'failed');
    }
    if ($transaction->hasField('field_stripe_manual_review')) {
      $transaction->set('field_stripe_manual_review', TRUE);
    }
    if ($transaction->hasField('field_stripe_charge_error')) {
      $transaction->set('field_stripe_charge_error', $errorMessage);
    }
    if ($transaction->hasField('field_library_charges_status')) {
      $transaction->set('field_library_charges_status', 'payment_error');
    }

    try {
      $transaction->save();
      $this->logger->warning('Transaction @tid marked for manual review after payment failure @pi_id: @error', [
        '@tid' => $transactionId,
        '@pi_id' => $paymentIntent->id,
        '@error' => $errorMessage,
      ]);

      // Send notification to staff about failed payment.
      $this->notifyStaffOfFailedPayment($transaction, $errorMessage);
    }
    catch (\Exception $e) {
      $this->logger->error('Error saving transaction @tid after payment failure: @error', [
        '@tid' => $transactionId,
        '@error' => $e->getMessage(),
      ]);
    }
  }

  /**
   * Load a library transaction by ID.
   *
   * @param int|string $transactionId
   *   The transaction ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface|null
   *   The transaction entity or NULL.
   */
  protected function loadTransaction($transactionId) {
    try {
      return $this->entityTypeManager
        ->getStorage('library_transaction')
        ->load($transactionId);
    }
    catch (\Exception $e) {
      $this->logger->error('Error loading transaction @tid: @error', [
        '@tid' => $transactionId,
        '@error' => $e->getMessage(),
      ]);
      return NULL;
    }
  }

  /**
   * Notify staff about a failed payment.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   * @param string $errorMessage
   *   The error message.
   */
  protected function notifyStaffOfFailedPayment($transaction, string $errorMessage): void {
    $config = $this->config('lending_library.settings');
    $staff_email = $config->get('email_staff_address');

    if (empty($staff_email)) {
      return;
    }

    $borrower_name = 'Unknown';
    if ($transaction->hasField('field_library_borrower') && !$transaction->get('field_library_borrower')->isEmpty()) {
      $borrower = $transaction->get('field_library_borrower')->entity;
      if ($borrower) {
        $borrower_name = $borrower->getDisplayName();
      }
    }

    $tool_name = 'Unknown';
    if ($transaction->hasField('field_library_item') && !$transaction->get('field_library_item')->isEmpty()) {
      $item = $transaction->get('field_library_item')->entity;
      if ($item) {
        $tool_name = $item->label();
      }
    }

    $amount_due = 0;
    if ($transaction->hasField('field_library_amount_due') && !$transaction->get('field_library_amount_due')->isEmpty()) {
      $amount_due = $transaction->get('field_library_amount_due')->value;
    }

    $mail_manager = \Drupal::service('plugin.manager.mail');
    $params = [
      'subject' => 'Lending Library Payment Failed - Manual Review Required',
      'body' => "A payment for a lending library fee has failed and requires manual review.\n\n"
        . "Borrower: $borrower_name\n"
        . "Tool: $tool_name\n"
        . "Amount Due: $" . number_format($amount_due, 2) . "\n"
        . "Error: $errorMessage\n"
        . "Transaction ID: " . $transaction->id() . "\n",
    ];

    $mail_manager->mail('lending_library', 'staff_payment_failed', $staff_email, 'en', $params, NULL, TRUE);
  }

}
