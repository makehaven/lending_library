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
    if ($node->bundle() === 'library_item' && $node->hasField('field_library_item_waitlist')) {
      $waitlist_users = $node->get('field_library_item_waitlist')->getValue();
      $user_ids = array_column($waitlist_users, 'target_id');
      $current_user_id = $this->currentUser()->id();

      if (!in_array($current_user_id, $user_ids)) {
        $node->get('field_library_item_waitlist')->appendItem($current_user_id);
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
    if ($node->bundle() === 'library_item' && $node->hasField('field_library_item_waitlist')) {
      $waitlist_users = $node->get('field_library_item_waitlist')->getValue();
      $user_ids = array_column($waitlist_users, 'target_id');
      $current_user_id = $this->currentUser()->id();

      if (($key = array_search($current_user_id, $user_ids)) !== false) {
        $node->get('field_library_item_waitlist')->removeItem($key);
        $node->save();
        $this->messenger()->addStatus($this->t('You have been removed from the waitlist for %title.', ['%title' => $node->label()]));
      }
    }

    return new RedirectResponse($node->toUrl()->toString());
  }

}
