<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\node\NodeInterface;
use Symfony\Component\HttpFoundation\RedirectResponse;

/**
 * Controller for waitlist actions.
 */
class WaitlistController extends ControllerBase {

  /**
   * Adds the current user to the waitlist for a library item.
   */
  public function add(NodeInterface $node) {
    if ($node->bundle() === 'library_item' && $node->hasField(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)) {
      $waitlist_users = $node->get(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->getValue();
      $user_ids = array_column($waitlist_users, 'target_id');
      $current_user_id = $this->currentUser()->id();

      if (!in_array($current_user_id, $user_ids)) {
        $node->get(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->appendItem($current_user_id);
        $node->save();
        $this->messenger()->addStatus($this->t('You have been added to the waitlist for %title.', ['%title' => $node->label()]));
      }
    }

    return new RedirectResponse($node->toUrl()->toString());
  }

  /**
   * Removes the current user from the waitlist for a library item.
   */
  public function remove(NodeInterface $node) {
    if ($node->bundle() === 'library_item' && $node->hasField(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)) {
      $waitlist_users = $node->get(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->getValue();
      $current_user_id = $this->currentUser()->id();

      foreach ($waitlist_users as $delta => $item) {
        if ((int) $item['target_id'] === (int) $current_user_id) {
          $node->get(LENDING_LIBRARY_ITEM_WAITLIST_FIELD)->removeItem($delta);
          $node->save();
          $this->messenger()->addStatus($this->t('You have been removed from the waitlist for %title.', ['%title' => $node->label()]));
          break;
        }
      }
    }

    return new RedirectResponse($node->toUrl()->toString());
  }

}
