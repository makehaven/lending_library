<?php

namespace Drupal\lending_library\Access;

use Drupal\Core\Access\AccessResult;
use Drupal\Core\Routing\Access\AccessInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\lending_library\Service\LendingLibraryManagerInterface;
use Drupal\node\NodeInterface;

/**
 * Custom access check for the return form route.
 *
 * Allows access if user has 'create library_transaction entities' permission
 * OR if user is the current borrower of the item (regardless of role).
 */
class ReturnAccessCheck implements AccessInterface {

  /**
   * The lending library manager.
   *
   * @var \Drupal\lending_library\Service\LendingLibraryManagerInterface
   */
  protected $lendingLibraryManager;

  /**
   * Constructs a ReturnAccessCheck object.
   *
   * @param \Drupal\lending_library\Service\LendingLibraryManagerInterface $lending_library_manager
   *   The lending library manager service.
   */
  public function __construct(LendingLibraryManagerInterface $lending_library_manager) {
    $this->lendingLibraryManager = $lending_library_manager;
  }

  /**
   * Checks access for the return form route.
   *
   * @param \Drupal\Core\Session\AccountInterface $account
   *   The current user account.
   * @param \Drupal\node\NodeInterface|null $node
   *   The library item node.
   *
   * @return \Drupal\Core\Access\AccessResultInterface
   *   The access result.
   */
  public function access(AccountInterface $account, ?NodeInterface $node = NULL) {
    // Standard permission check.
    if ($account->hasPermission('create library_transaction entities')) {
      return AccessResult::allowed()
        ->addCacheContexts(['user.permissions'])
        ->addCacheTags(['node:' . ($node ? $node->id() : 0)]);
    }

    // Allow current borrower to return items even without borrower role.
    if ($node && $node->bundle() === 'library_item') {
      $item_details = $this->lendingLibraryManager->getItemDetails($node);
      if ($item_details && (int) $item_details['borrower_uid'] === (int) $account->id()) {
        return AccessResult::allowed()
          ->addCacheContexts(['user'])
          ->addCacheTags(['node:' . $node->id()]);
      }
    }

    return AccessResult::forbidden()
      ->addCacheContexts(['user', 'user.permissions'])
      ->addCacheTags(['node:' . ($node ? $node->id() : 0)]);
  }

}
