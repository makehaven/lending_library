<?php

namespace Drupal\lending_library\Tests\Functional;

use Drupal\Tests\BrowserTestBase;
use Drupal\node\Entity\Node;
use Drupal\user\Entity\User;
use Drupal\user\Entity\Role;

/**
 * Tests the waitlist functionality.
 *
 * @group lending_library
 */
class WaitlistTest extends BrowserTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = ['lending_library', 'node', 'user', 'field', 'text', 'datetime', 'options'];

  /**
   * {@inheritdoc}
   */
  protected $defaultTheme = 'stark';

  /**
   * A user with permission to borrow items and get in line.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $borrower;

  /**
   * A user with permission to get in line.
   *
   * @var \Drupal\user\UserInterface
   */
  protected $waitlister;

  /**
   * A library item node.
   *
   * @var \Drupal\node\NodeInterface
   */
  protected $libraryItem;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    // Create a user with the 'borrower' and 'get in line for library items' roles.
    $this->borrower = $this->drupalCreateUser([
      'create library_transaction entities',
      'view library_transaction entities',
      'edit own library_transaction entities',
      'delete own library_transaction entities',
    ]);

    $this->waitlister = $this->drupalCreateUser([
        'get in line for library items',
    ]);

    // Create a library item.
    $this->libraryItem = $this->drupalCreateNode([
      'type' => 'library_item',
      'title' => 'Test Library Item',
      'field_library_item_status' => 'available',
    ]);
  }

  /**
   * Tests the waitlist functionality.
   */
  public function testWaitlist() {
    // Log in as the borrower.
    $this->drupalLogin($this->borrower);

    // Borrow the item.
    $this->drupalGet('library/item/' . $this->libraryItem->id() . '/withdraw');
    $this->submitForm([], 'Confirm Withdrawal & Agree');

    // Log in as the waitlister.
    $this->drupalLogin($this->waitlister);

    // Go to the library item page.
    $this->drupalGet('node/' . $this->libraryItem->id());

    // Verify that the "Get in Line" button is visible.
    $this->assertSession()->linkExists('Get in Line');

    // Click the "Get in Line" button.
    $this->clickLink('Get in Line');

    // Verify that the user is on the waitlist.
    $this->assertSession()->pageTextContains('You have been added to the waitlist for Test Library Item.');

    // Log in as the borrower again.
    $this->drupalLogin($this->borrower);

    // Return the item.
    $this->drupalGet('library/item/' . $this->libraryItem->id() . '/return');
    $this->submitForm([], 'Confirm Return');

    // Verify that the user received an email.
    $this->assertMailSent();

    // Log in as the waitlister again.
    $this->drupalLogin($this->waitlister);

    // Verify that the "Withdraw This Item" button is visible.
    $this->drupalGet('node/' . $this->libraryItem->id());
    $this->assertSession()->linkExists('Withdraw This Item');
  }

}
