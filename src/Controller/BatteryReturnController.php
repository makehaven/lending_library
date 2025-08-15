<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Url;
use Symfony\Component\HttpFoundation\RedirectResponse;

class BatteryReturnController extends ControllerBase {

  public function return($battery) {
    $storage = \Drupal::entityTypeManager()->getStorage('battery');
    $bat = $storage->load($battery);
    if (!$bat) {
      $this->messenger()->addError($this->t('Battery not found.'));
      return $this->redirectToLibrary();
    }

    // Field machine names must match your ECK setup.
    $status_field = 'field_battery_status';
    $borrower_field = 'field_battery_borrower';
    $item_field = 'field_battery_current_item';

    // Sanity checks and updates.
    if ($bat->hasField($status_field)) {
      $bat->set($status_field, 'available');
    }
    if ($bat->hasField($borrower_field)) {
      $bat->set($borrower_field, NULL);
    }
    if ($bat->hasField($item_field)) {
      $bat->set($item_field, NULL);
    }

    try {
      $bat->save();
      $this->logger('lending_library')->notice('Battery @id returned via direct action.', ['@id' => $bat->id()]);
      $this->messenger()->addStatus($this->t('Battery has been marked as returned.'));
    }
    catch (\Exception $e) {
      $this->logger('lending_library')->error('Battery return failed (@id): @msg', ['@id' => $battery, '@msg' => $e->getMessage()]);
      $this->messenger()->addError($this->t('Could not save the battery return.'));
    }

    // Redirect back to referer if possible, otherwise the library page.
    $referer = \Drupal::request()->headers->get('referer');
    if ($referer) {
      return new RedirectResponse($referer);
    }
    return $this->redirectToLibrary();
  }

  private function redirectToLibrary() {
    return $this->redirectUrl(Url::fromUri('internal:/library'));
  }
}
