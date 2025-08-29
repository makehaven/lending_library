<?php

namespace Drupal\lending_library\Plugin\Block;

use Drupal\Core\Block\BlockBase;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Session\AccountProxyInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Entity\EntityFormBuilderInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\node\NodeInterface;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Provides a 'Library Transaction Add Form' block.
 *
 * @Block(
 * id = "library_transaction_add_form",
 * admin_label = @Translation("Library Transaction Add Form"),
 * context_definitions = {
 * "node" = @ContextDefinition("entity:node", label = @Translation("Library Item"))
 * }
 * )
 */
class LibraryTransactionAddBlock extends BlockBase implements ContainerFactoryPluginInterface {

  protected $entityTypeManager;
  protected $entityFormBuilder;
  protected $currentUser;

  public function __construct(array $configuration, $plugin_id, $plugin_definition, EntityTypeManagerInterface $etm, EntityFormBuilderInterface $efb, AccountProxyInterface $user) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->entityTypeManager  = $etm;
    $this->entityFormBuilder  = $efb;
    $this->currentUser        = $user;
  }

  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition) {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('entity_type.manager'),
      $container->get('entity.form_builder'),
      $container->get('current_user')
    );
  }

  public function build() {
    $node = $this->getContextValue('node');
    if (!$node instanceof NodeInterface || $node->bundle() !== 'library_item') {
      // If the context is not a 'library_item' node, do not build the form.
      return [];
    }

    $today_datetime = new DrupalDateTime('now');
    $due_datetime = new DrupalDateTime('+7 days');

    //
    // CRITICAL: Set the correct date format for your fields.
    // Since your fields are 'Date' (date-only), use 'Y-m-d'.
    //
    // $date_format = 'Y-m-d\TH:i:s'; // This is for 'Date and time' fields.
    $date_format = 'Y-m-d';         // <<< CORRECT for 'Date' (date-only) fields.

    // Optional Debugging Lines (uncomment to see values when the block renders):
    // \Drupal::messenger()->addWarning('DEBUG Block - Date Format Used: ' . $date_format);
    // \Drupal::messenger()->addWarning('DEBUG Block - Today Formatted: ' . $today_datetime->format($date_format));
    // \Drupal::messenger()->addWarning('DEBUG Block - Due Date Formatted: ' . $due_datetime->format($date_format));

    $values = [
      'type'                     => 'library_transaction', // Bundle for the ECK entity.
      'field_library_item'       => ['target_id' => $node->id()],
      'field_library_borrower'   => ['target_id' => $this->currentUser->id()],
      'uid'                      => $this->currentUser->id(), // Sets the author of the entity.
      'field_library_borrow_date'=> [['value' => $today_datetime->format($date_format)]],
      'field_library_due_date'   => [['value' => $due_datetime->format($date_format)]],
      'field_library_renew_count'=> [['value' => 0]], // Default renew count to 0.
      // Add any other fields that need a default value when the transaction is initiated.
      // For example, if 'withdraw' should be the default action:
      // 'field_library_action'     => [['value' => 'withdraw']],
    ];
    
    // Optional Debugging Line (uncomment to see the full $values array):
    // \Drupal::messenger()->addWarning('DEBUG Block - Values for entity creation: <pre>' . print_r($values, TRUE) . '</pre>');

    $txn = $this->entityTypeManager
      ->getStorage('library_transaction')
      ->create($values);
  
    // Render the 'default' entity add form for the new transaction entity.
    // If you have a specific form mode for adding (e.g., 'add'), you can use it here.
    return $this->entityFormBuilder->getForm($txn, 'default');
  }
}