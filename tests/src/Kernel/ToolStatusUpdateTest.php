<?php

namespace Drupal\Tests\lending_library\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\node\Entity\Node;
use Drupal\node\Entity\NodeType;
use Drupal\user\Entity\User;
use Drupal\eck\Entity\EckEntityType;
use Drupal\eck\Entity\EckEntity;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;

/**
 * Tests the tool status updates when transactions are created.
 *
 * @group lending_library
 */
class ToolStatusUpdateTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  public static $modules = [
    'system',
    'user',
    'node',
    'field',
    'text',
    'eck',
    'lending_library',
  ];

  /**
   * The user that will be the borrower.
   *
   * @var \Drupal\user\Entity\User
   */
  protected $borrower;

  /**
   * The library item node.
   *
   * @var \Drupal\node\Entity\Node
   */
  protected $tool;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('eck_entity');
    $this->installConfig(['field', 'node', 'lending_library', 'eck']);

    // Create a user to be the borrower.
    $this->borrower = User::create([
      'name' => 'borrower',
      'mail' => 'borrower@example.com',
    ]);
    $this->borrower->save();

    // Create the library_item node type.
    $node_type = NodeType::create([
      'type' => 'library_item',
      'name' => 'Library Item',
    ]);
    $node_type->save();

    // Create fields for the library_item node type.
    FieldStorageConfig::create([
      'field_name' => 'field_library_item_status',
      'entity_type' => 'node',
      'type' => 'list_string',
      'settings' => [
        'allowed_values' => [
          'available' => 'Available',
          'borrowed' => 'Borrowed',
          'repair' => 'In Repair',
          'missing' => 'Missing',
        ],
      ],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_library_item_status',
      'entity_type' => 'node',
      'bundle' => 'library_item',
      'label' => 'Status',
    ])->save();

    FieldStorageConfig::create([
      'field_name' => 'field_library_item_borrower',
      'entity_type' => 'node',
      'type' => 'entity_reference',
      'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_library_item_borrower',
      'entity_type' => 'node',
      'bundle' => 'library_item',
      'label' => 'Borrower',
    ])->save();

    // Create the library_transaction ECK entity type.
    EckEntityType::create([
        'id' => 'library_transaction',
        'label' => 'Library Transaction',
        'eck_entity_type' => 'library_transaction',
    ])->save();

    EckEntity::create([
        'eck_entity_type' => 'library_transaction',
        'type' => 'library_transaction',
    ]);


    // Create fields for the library_transaction entity.
    FieldStorageConfig::create([
        'field_name' => 'field_library_action',
        'entity_type' => 'library_transaction',
        'type' => 'list_string',
        'settings' => [
            'allowed_values' => [
                'withdraw' => 'Withdraw',
                'return' => 'Return',
                'issue' => 'Issue',
            ],
        ],
    ])->save();
    FieldConfig::create([
        'field_name' => 'field_library_action',
        'entity_type' => 'library_transaction',
        'bundle' => 'library_transaction',
        'label' => 'Action',
    ])->save();

    FieldStorageConfig::create([
        'field_name' => 'field_library_item',
        'entity_type' => 'library_transaction',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'node'],
    ])->save();
    FieldConfig::create([
        'field_name' => 'field_library_item',
        'entity_type' => 'library_transaction',
        'bundle' => 'library_transaction',
        'label' => 'Item',
    ])->save();

    FieldStorageConfig::create([
        'field_name' => 'field_library_borrower',
        'entity_type' => 'library_transaction',
        'type' => 'entity_reference',
        'settings' => ['target_type' => 'user'],
    ])->save();
    FieldConfig::create([
        'field_name' => 'field_library_borrower',
        'entity_type' => 'library_transaction',
        'bundle' => 'library_transaction',
        'label' => 'Borrower',
    ])->save();

    FieldStorageConfig::create([
        'field_name' => 'field_library_inspection_issues',
        'entity_type' => 'library_transaction',
        'type' => 'list_string',
        'settings' => [
            'allowed_values' => [
                'no_issues' => 'No Issues',
                'damage' => 'Damage',
                'missing' => 'Missing',
                'other' => 'Other',
            ],
        ],
    ])->save();
    FieldConfig::create([
        'field_name' => 'field_library_inspection_issues',
        'entity_type' => 'library_transaction',
        'bundle' => 'library_transaction',
        'label' => 'Inspection Issues',
    ])->save();

    // Create a tool to be borrowed.
    $this->tool = Node::create([
      'type' => 'library_item',
      'title' => 'Hammer',
      'field_library_item_status' => 'available',
    ]);
    $this->tool->save();
  }

  /**
   * Tests tool status updates.
   */
  public function testToolStatusUpdates() {
    // 1. Withdraw the tool.
    $withdraw_transaction = EckEntity::create([
        'eck_entity_type' => 'library_transaction',
        'type' => 'library_transaction',
        'field_library_item' => $this->tool->id(),
        'field_library_action' => 'withdraw',
        'field_library_borrower' => $this->borrower->id(),
        'uid' => $this->borrower->id(),
    ]);
    $withdraw_transaction->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$this->tool->id()]);
    $updated_tool = Node::load($this->tool->id());

    $this->assertEquals('borrowed', $updated_tool->get('field_library_item_status')->value, 'Tool status is borrowed after withdrawal.');
    $this->assertEquals($this->borrower->id(), $updated_tool->get('field_library_item_borrower')->target_id, 'Borrower is set correctly after withdrawal.');

    // 2. Return the tool.
    $return_transaction = EckEntity::create([
        'eck_entity_type' => 'library_transaction',
        'type' => 'library_transaction',
        'field_library_item' => $this->tool->id(),
        'field_library_action' => 'return',
        'field_library_borrower' => $this->borrower->id(),
        'uid' => $this->borrower->id(),
        'field_library_inspection_issues' => 'no_issues',
    ]);
    $return_transaction->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$this->tool->id()]);
    $returned_tool = Node::load($this->tool->id());

    $this->assertEquals('available', $returned_tool->get('field_library_item_status')->value, 'Tool status is available after return.');
    $this->assertEmpty($returned_tool->get('field_library_item_borrower')->target_id, 'Borrower is cleared after return.');

    // 3. Return a tool that was in repair.
    $this->tool->set('field_library_item_status', 'repair')->save();

    $return_from_repair_transaction = EckEntity::create([
        'eck_entity_type' => 'library_transaction',
        'type' => 'library_transaction',
        'field_library_item' => $this->tool->id(),
        'field_library_action' => 'return',
        'field_library_borrower' => $this->borrower->id(),
        'uid' => $this->borrower->id(),
        'field_library_inspection_issues' => 'no_issues',
    ]);
    $return_from_repair_transaction->save();

    $this->container->get('entity_type.manager')->getStorage('node')->resetCache([$this->tool->id()]);
    $repaired_tool = Node::load($this->tool->id());

    $this->assertEquals('available', $repaired_tool->get('field_library_item_status')->value, 'Tool status is available after being returned from repair.');
    $this->assertEmpty($repaired_tool->get('field_library_item_borrower')->target_id, 'Borrower is cleared after return from repair.');
  }

}
