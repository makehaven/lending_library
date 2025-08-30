<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Controller for handling PayPal IPN notifications.
 */
class PaypalIpnController extends ControllerBase {

  /**
   * The entity type manager.
   *
   * @var \Drupal\Core\Entity\EntityTypeManagerInterface
   */
  protected $entityTypeManager;

  /**
   * Constructs a PaypalIpnController object.
   *
   * @param \Drupal\Core\Entity\EntityTypeManagerInterface $entity_type_manager
   *   The entity type manager.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager) {
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('entity_type.manager')
    );
  }

  /**
   * IPN listener to handle inventory updates after PayPal checkout.
   */
  public function ipnListener(Request $request) {
    // Read the raw POST data from the service.
    $raw_post_data = $request->getContent();
    $post_data = [];
    parse_str($raw_post_data, $post_data);

    // For local testing, you can bypass validation.
    $is_local = in_array(\Drupal::request()->server->get('REMOTE_ADDR'), ['127.0.0.1', '::1']) || strpos(\Drupal::request()->server->get('HTTP_HOST'), 'lndo.site') !== false;

    // Validate the request if not in a local environment.
    if ($is_local || $this->validateIpn($raw_post_data)) {
      // Check for a specific condition, like a completed payment.
      if (isset($post_data['payment_status']) && $post_data['payment_status'] == 'Completed' && !empty($post_data['custom'])) {

        $transaction_id = $post_data['custom'];

        try {
          // Get the storage handler for the entity you want to create.
          $storage = $this->entityTypeManager->getStorage('library_transaction');
          $transaction = $storage->load($transaction_id);

          if ($transaction) {
            // Update the transaction entity.
            $transaction->set('field_library_closed', TRUE);
            if ($transaction->hasField('field_paypal_ipn_log')) {
              $transaction->set('field_paypal_ipn_log', $raw_post_data);
            }
            if ($transaction->hasField('field_library_amount_paid') && isset($post_data['mc_gross'])) {
              $transaction->set('field_library_amount_paid', $post_data['mc_gross']);
            }
            // The user will provide YAML for other fields.
            // For now, we just close the transaction and log the IPN.
            $transaction->save();
            \Drupal::logger('lending_library')->notice('PayPal IPN: Transaction @id marked as paid.', ['@id' => $transaction_id]);
          }
          else {
            \Drupal::logger('lending_library')->warning('PayPal IPN: Received valid IPN but could not load transaction @id.', ['@id' => $transaction_id]);
          }

        } catch (\Exception $e) {
          \Drupal::logger('lending_library')->error('PayPal IPN: Failed to update transaction @id. Error: @error', ['@id' => $transaction_id, '@error' => $e->getMessage()]);
        }
      }
    }
    else {
        \Drupal::logger('lending_library')->error('PayPal IPN: Invalid IPN received.');
    }

    // Return a 200 OK response to the service to acknowledge receipt.
    return new Response('', 200);
  }

  /**
   * A private function to validate the incoming data (optional but recommended).
   */
  private function validateIpn($raw_post_data) {
    // Validation logic here. For PayPal, this involves sending the data back to them.
    $request_body = 'cmd=_notify-validate&' . $raw_post_data;
    $ch = curl_init('https://ipnpb.paypal.com/cgi-bin/webscr');
    curl_setopt($ch, CURLOPT_HTTPHEADER, ['Connection: Close']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $request_body);
    $response = curl_exec($ch);
    curl_close($ch);

    return strcmp($response, "VERIFIED") == 0;
  }
}
