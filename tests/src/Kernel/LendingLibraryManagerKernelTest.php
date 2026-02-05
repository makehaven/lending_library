<?php

namespace Drupal\Tests\lending_library\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\eck\Entity\EckEntity;
use Drupal\Core\Datetime\DrupalDateTime;

/**
 * Tests LendingLibraryManager kernel functionality.
 *
 * @group lending_library
 */
class LendingLibraryManagerKernelTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'datetime',
    'datetime_range',
    'eck',
    'lending_library',
    'mh_stripe',
    'file',
    'image',
    'taxonomy',
    'options',
    'link',
    'field_permissions',
    'comment',
    'field_group',
    'charts',
  ];

  /**
   * The LendingLibraryManager service.
   *
   * @var \Drupal\lending_library\Service\LendingLibraryManager
   */
  protected $manager;

  /**
   * The ToolStatusUpdater service.
   *
   * @var \Drupal\lending_library\Service\ToolStatusUpdater
   */
  protected $updater;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent\setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('eck_entity');
    $this->installEntitySchema('file');
    $this->installEntitySchema('taxonomy_term');
    
    $this->installConfig(['field', 'node', 'user', 'lending_library', 'eck', 'system']);

    $this->manager = $this->container->get('lending_library.manager');
    $this->updater = $this->container->get('lending_library.tool_status_updater');
  }

  /**
   * Tests late fee calculation.
   */
  public function testCalculateLateFee() {
    $this->config('lending_library.settings')->set('daily_late_fee', 5.0)->save();

    $due_date = new DrupalDateTime('2026-02-01T12:00:00');
    $return_date = new DrupalDateTime('2026-02-06T12:00:00'); 
    
    $transaction = EckEntity\create([
      'type' => 'library_transaction',
      'eck_entity_type' => 'library_transaction',
      'field_library_due_date' => $due_date->format('Y-m-d\TH:i:s'),
    ]);
    $transaction->save();

    $result = $this->manager->calculateLateFee($transaction, $return_date);

    $this->assertNotNull($result);
    $this->assertEquals(5, $result['days_late']);
    $this->assertEquals(25.0, $result['late_fee']);
  }

  /**
   * Tests tool status updates from transactions.
   */
  public function testToolStatusUpdates() {
    // 1. Setup a borrower and a tool.
    $borrower = User\create(['name' => 'borrower', 'mail' => 'b@example.com']);
    $borrower->save();

    $tool = Node\create([
      'type' => 'library_item',
      'title' => 'Test Tool',
      'field_library_item_status' => 'available',
    ]);
    $tool->save();

    // 2. Withdraw the tool.
    $withdraw = EckEntity\create([
      'type' => 'library_transaction',
      'eck_entity_type' => 'library_transaction',
      'field_library_item' => $tool->id(),
      'field_library_action' => 'withdraw',
      'field_library_borrower' => $borrower->id(),
      'uid' => $borrower->id(),
    ]);
    $withdraw->save();

    $this->updater->updateFromTransaction($withdraw);

    $tool = Node\load($tool->id());
    $this->assertEquals('borrowed', $tool->get('field_library_item_status')->value);
    $this->assertEquals($borrower->id(), $tool->get('field_library_item_borrower')->target_id);

    // 3. Return the tool with damage.
    $return = EckEntity\create([
      'type' => 'library_transaction',
      'eck_entity_type' => 'library_transaction',
      'field_library_item' => $tool->id(),
      'field_library_action' => 'return',
      'field_library_inspection_issues' => 'damage',
      'uid' => $borrower->id(),
    ]);
    $return->save();

    $this->updater->updateFromTransaction($return);

    $tool = Node\load($tool->id());
    $this->assertEquals('repair', $tool->get('field_library_item_status')->value);
    $this->assertEmpty($tool->get('field_library_item_borrower')->target_id);
  }
}