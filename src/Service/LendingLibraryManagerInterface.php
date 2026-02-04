<?php

namespace Drupal\lending_library\Service;

use Drupal\Core\Entity\EntityInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Interface for the Lending Library Manager service.
 */
interface LendingLibraryManagerInterface {

  /**
   * Helper: get status, borrower, and replacement value from the library_item node.
   *
   * @param \Drupal\node\NodeInterface|null $library_item_node
   *   The library item node.
   *
   * @return array|null
   *   An associative array with 'status', 'borrower_uid', and 'replacement_value'.
   */
  public function getItemDetails(?NodeInterface $library_item_node);

  /**
   * Helper: get current loan borrow/due dates from latest withdraw transaction.
   *
   * @param \Drupal\node\NodeInterface $library_item_node
   *   The library item node.
   *
   * @return array|null
   *   An associative array with 'borrow_date' and 'due_date'.
   */
  public function getCurrentLoanDetails(NodeInterface $library_item_node);

  /**
   * Load batteries currently borrowed for a given item + borrower.
   *
   * @param int $item_nid
   *   The library item node ID.
   * @param int $borrower_uid
   *   The borrower user ID.
   *
   * @return \Drupal\Core\Entity\EntityInterface[]
   *   An array of battery entities.
   */
  public function loadBorrowedBatteries($item_nid, $borrower_uid);

  /**
   * Mark the provided batteries as returned.
   *
   * @param \Drupal\Core\Entity\EntityInterface[] $batteries
   *   An array of battery entities to return.
   */
  public function returnBatteries(array $batteries);

  /**
   * Save a Battery entity with a revision log message.
   *
   * @param \Drupal\Core\Entity\EntityInterface $battery
   *   The battery entity.
   * @param string $message
   *   The revision log message.
   * @param int|null $uid
   *   The user ID responsible for the change. Defaults to current user.
   */
  public function saveBatteryWithRevision(EntityInterface $battery, $message = '', $uid = NULL);

  /**
   * Calculates late fee for a transaction.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   * @param \Drupal\Core\Datetime\DrupalDateTime|null $return_date
   *   (Optional) The date the item was returned. Defaults to now.
   *
   * @return array|null
   *   An array with 'days_late' and 'late_fee' or NULL.
   */
  public function calculateLateFee(EntityInterface $transaction, ?DrupalDateTime $return_date = NULL);

  /**
   * Calculates the value of unreturned batteries for a transaction.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   *
   * @return float
   *   The total value of unreturned batteries.
   */
  public function calculateUnreturnedBatteryValue(EntityInterface $transaction);

  /**
   * Processes a transaction for a non-returned item (lost/stolen).
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   * @param float $non_return_charge_percentage
   *   The percentage to charge.
   */
  public function processNonReturnCharge(EntityInterface $transaction, $non_return_charge_percentage);

  /**
   * Sends an email based on a specific key.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction context.
   * @param string $key
   *   The email key.
   * @param array $extra_params
   *   Additional parameters.
   */
  public function sendEmailByKey(EntityInterface $transaction, $key, array $extra_params = []);

  /**
   * Sends a "due soon" email.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   */
  public function sendDueSoonEmail(EntityInterface $transaction);

  /**
   * Sends a checkout confirmation email.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   */
  public function sendCheckoutEmail(EntityInterface $transaction);

  /**
   * Sends a return confirmation email.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   */
  public function sendReturnEmail(EntityInterface $transaction);

  /**
   * Sends an issue report notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   */
  public function sendIssueEmail(EntityInterface $transaction);

  /**
   * Sends a damaged item report notification.
   *
   * @param \Drupal\Core\Entity\EntityInterface $transaction
   *   The transaction entity.
   */
  public function sendDamagedEmail(EntityInterface $transaction);

  /**
   * Creates a battery transaction.
   *
   * @param \Drupal\Core\Entity\EntityInterface $battery
   *   The battery entity.
   * @param string $action
   *   The action (withdraw, return, charge, etc.).
   * @param int|null $borrower_uid
   *   The user ID of the borrower.
   * @param \Drupal\Core\Entity\EntityInterface|null $tool_transaction
   *   The related tool transaction, if any.
   */
  public function createBatteryTransaction(EntityInterface $battery, string $action, ?int $borrower_uid, ?EntityInterface $tool_transaction = NULL);

  /**
   * Calculates the per-use fee for a library item.
   *
   * @param \Drupal\node\NodeInterface $library_item_node
   *   The library item node.
   *
   * @return float
   *   The per-use fee amount.
   */
  public function getPerUseFee(NodeInterface $library_item_node): float;

  /**
   * Checks if a library item should have a per-use fee.
   *
   * @param \Drupal\node\NodeInterface $library_item_node
   *   The library item node.
   *
   * @return bool
   *   TRUE if the item has a per-use fee, FALSE otherwise.
   */
  public function isFeeItem(NodeInterface $library_item_node): bool;
}
