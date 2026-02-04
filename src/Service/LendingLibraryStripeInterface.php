<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\user\UserInterface;

/**
 * Interface for the Lending Library Stripe service.
 */
interface LendingLibraryStripeInterface {

  /**
   * Check if user has a Stripe customer ID.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to check.
   *
   * @return bool
   *   TRUE if user has a Stripe customer ID, FALSE otherwise.
   */
  public function hasStripeCustomer(UserInterface $user): bool;

  /**
   * Get the Stripe customer ID for a user.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to get the customer ID for.
   *
   * @return string|null
   *   The Stripe customer ID or NULL if not set.
   */
  public function getStripeCustomerId(UserInterface $user): ?string;

  /**
   * Create a charge for a library fee.
   *
   * Uses Stripe PaymentIntents with off_session for saved cards.
   *
   * @param \Drupal\user\UserInterface $user
   *   The user to charge.
   * @param float $amount
   *   The amount to charge in dollars.
   * @param string $description
   *   Description for the charge.
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The library transaction entity.
   * @param string $chargeType
   *   Type of charge (late_fee, per_use_fee, replacement, condition, etc.).
   *
   * @return array
   *   Array with keys:
   *   - success: bool
   *   - payment_intent_id: string|null
   *   - status: string (pending, succeeded, failed)
   *   - error: string|null
   */
  public function createCharge(
    UserInterface $user,
    float $amount,
    string $description,
    EntityInterface $transaction,
    string $chargeType = 'late_fee'
  ): array;

  /**
   * Get charge status from Stripe.
   *
   * @param string $paymentIntentId
   *   The PaymentIntent ID.
   *
   * @return string
   *   The status (pending, succeeded, failed, canceled).
   */
  public function getChargeStatus(string $paymentIntentId): string;

  /**
   * Check if Stripe integration is enabled.
   *
   * @return bool
   *   TRUE if Stripe is enabled.
   */
  public function isEnabled(): bool;

  /**
   * Check if Stripe customer is required for checkout.
   *
   * @return bool
   *   TRUE if Stripe customer is required.
   */
  public function isCustomerRequiredForCheckout(): bool;

  /**
   * Get the auto-charge threshold amount.
   *
   * @return float
   *   The minimum amount for auto-charging.
   */
  public function getAutoChargeThreshold(): float;

}
