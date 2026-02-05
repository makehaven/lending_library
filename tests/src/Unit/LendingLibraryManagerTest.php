<?php

namespace Drupal\Tests\lending_library\Unit;

use Drupal\Core\Config\Config;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Entity\EntityFieldManagerInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Mail\MailManagerInterface;
use Drupal\Core\Language\LanguageManagerInterface;
use Drupal\node\NodeInterface;
use Drupal\Tests\UnitTestCase;
use Drupal\lending_library\Service\LendingLibraryManager;

/**
 * @coversDefaultClass \Drupal\lending_library\Service\LendingLibraryManager
 * @group lending_library
 */
class LendingLibraryManagerTest extends UnitTestCase {

  protected $entityTypeManager;
  protected $configFactory;
  protected $loggerFactory;
  protected $currentUser;
  protected $time;
  protected $dateFormatter;
  protected $mailManager;
  protected $languageManager;
  protected $entityFieldManager;
  protected $manager;

  protected function setUp(): void {
    parent::setUp();

    $this->entityTypeManager = $this->createMock(EntityTypeManagerInterface::class);
    $this->configFactory = $this->createMock(ConfigFactoryInterface::class);
    $this->loggerFactory = $this->createMock(LoggerChannelFactoryInterface::class);
    $this->currentUser = $this->createMock(AccountInterface::class);
    $this->time = $this->createMock(TimeInterface::class);
    $this->dateFormatter = $this->createMock(DateFormatterInterface::class);
    $this->mailManager = $this->createMock(MailManagerInterface::class);
    $this->languageManager = $this->createMock(LanguageManagerInterface::class);
    $this->entityFieldManager = $this->createMock(EntityFieldManagerInterface::class);

    $logger = $this->createMock(LoggerChannelInterface::class);
    $this->loggerFactory->method('get')->willReturn($logger);

    $this->manager = new LendingLibraryManager(
      $this->entityTypeManager,
      $this->configFactory,
      $this->loggerFactory,
      $this->currentUser,
      $this->time,
      $this->dateFormatter,
      $this->mailManager,
      $this->languageManager,
      $this->entityFieldManager
    );
  }

  /**
   * Helper to create a mock field item list with a value.
   */
  protected function createMockField($value, $isEmpty = FALSE) {
    $field = $this->getMockBuilder('Drupal\Core\Field\FieldItemListInterface')
      ->disableOriginalConstructor()
      ->getMock();
    $field->method('isEmpty')->willReturn($isEmpty);
    $field->method("__get")->with("value")->willReturn($value);
    return $field;
  }

  /**
   * @covers \getPerUseFee
   * @dataProvider feeDataProvider
   */
  public function testGetPerUseFee($replacement_value, $uses_battery, $config_settings, $expected_fee) {
    $node = $this->createMock(NodeInterface::class);
    $node->method('bundle')->willReturn('library_item');
    $node->method('id')->willReturn(123);

    $fields = [
      'field_library_item_replacement_v' => $this->createMockField($replacement_value, $replacement_value === NULL),
      'field_library_item_uses_battery' => $this->createMockField($uses_battery),
      'field_library_item_status' => $this->createMockField('available'),
      'field_library_item_borrower' => $this->createMockField(NULL, TRUE),
    ];

    $node->method('get')->willReturnCallback(function($field_name) use ($fields) {
      return $fields[$field_name] ?? NULL;
    });

    $node->method('hasField')->willReturnCallback(function($field_name) use ($fields) {
      return isset($fields[$field_name]);
    });

    // Mock config
    $config = $this->createMock(Config::class);
    $config->method('get')->willReturnMap([
      ['fee_system_enabled', $config_settings['fee_system_enabled'] ?? TRUE],
      ['fee_free_threshold', $config_settings['fee_free_threshold'] ?? 150.0],
      ['fee_value_increment', $config_settings['fee_value_increment'] ?? 500.0],
      ['fee_step_amount', $config_settings['fee_step_amount'] ?? 5.0],
      ['fee_battery_value_adder_enabled', $config_settings['fee_battery_value_adder_enabled'] ?? TRUE],
      ['fee_battery_value_adder', $config_settings['fee_battery_value_adder'] ?? 150.0],
    ]);
    $this->configFactory->method('get')->with('lending_library.settings')->willReturn($config);

    $fee = $this->manager->getPerUseFee($node);
    $this->assertEquals($expected_fee, $fee);
  }

  public static function feeDataProvider(): array {
    return [
      'below threshold' => [100.0, FALSE, [], 0.0],
      'at threshold' => [150.0, FALSE, [], 5.0],
      'above threshold step 1' => [600.0, FALSE, [], 5.0],
      'at next step' => [650.0, FALSE, [], 10.0],
      'battery adder below' => [50.0, TRUE, [], 5.0],
      'battery adder above' => [500.0, TRUE, [], 10.0],
      'fee system disabled' => [1000.0, FALSE, ['fee_system_enabled' => FALSE], 0.0],
    ];
  }
}