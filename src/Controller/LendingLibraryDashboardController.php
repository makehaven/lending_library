<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\Core\Datetime\DrupalDateTime;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Drupal\Core\Database\Connection;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Drupal\Core\Entity\Query\QueryInterface;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Url;

/**
 * Controller for the Lending Library Dashboard.
 */
class LendingLibraryDashboardController extends ControllerBase {

  protected $database;
  protected $entityTypeManager;
  protected $dateFormatter;
  protected $statsCollector;
  protected array $activeLoanStatuses = [LENDING_LIBRARY_ITEM_STATUS_BORROWED, LENDING_LIBRARY_ITEM_STATUS_MISSING];

  /**
   * Applies base conditions to a query so it only returns open withdrawals.
   */
  protected function applyOpenLoanConditions(QueryInterface $query): QueryInterface {
    return $query
      ->condition('type', 'library_transaction')
      ->condition('field_library_action', 'withdraw')
      ->condition('field_library_closed', 1, '<>')
      ->condition('field_library_return_date', NULL, 'IS NULL');
  }

  /**
   * Load withdraw transactions that still reflect an active loan.
   */
  protected function loadActiveLoanTransactions(callable $query_modifier, ?int $limit = NULL): array {
    $storage = $this->entityTypeManager->getStorage('library_transaction');
    $query = $this->applyOpenLoanConditions($storage->getQuery()->accessCheck(FALSE));
    $query_modifier($query);
    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $transactions = $storage->loadMultiple($ids);
    $ordered = [];
    foreach ($ids as $id) {
      if (!isset($transactions[$id])) {
        continue;
      }
      $transaction = $transactions[$id];
      if ($this->transactionReflectsCurrentBorrower($transaction)) {
        $ordered[$id] = $transaction;
        if ($limit && count($ordered) >= $limit) {
          break;
        }
      }
    }

    return $ordered;
  }

  /**
   * Determines whether the withdraw record still belongs to the active borrower.
   */
  protected function transactionReflectsCurrentBorrower(EntityInterface $transaction): bool {
    if ($transaction->get('field_library_item')->isEmpty() || $transaction->get('field_library_borrower')->isEmpty()) {
      return FALSE;
    }

    $tool = $transaction->get('field_library_item')->entity;
    if (!$tool) {
      return FALSE;
    }

    if (!$tool->hasField(LENDING_LIBRARY_ITEM_STATUS_FIELD) || !$tool->hasField(LENDING_LIBRARY_ITEM_BORROWER_FIELD)) {
      return FALSE;
    }

    $status = $tool->get(LENDING_LIBRARY_ITEM_STATUS_FIELD)->value;
    if (!in_array($status, $this->activeLoanStatuses, TRUE)) {
      return FALSE;
    }

    if ($tool->get(LENDING_LIBRARY_ITEM_BORROWER_FIELD)->isEmpty()) {
      return FALSE;
    }
    $current_borrower = (int) $tool->get(LENDING_LIBRARY_ITEM_BORROWER_FIELD)->target_id;
    $transaction_borrower = (int) $transaction->get('field_library_borrower')->target_id;

    return $current_borrower === $transaction_borrower;
  }

  /**
   * Constructs the controller.
   */
  public function __construct(Connection $database, EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, StatsCollectorInterface $stats_collector) {
    $this->database = $database;
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->statsCollector = $stats_collector;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('database'),
      $container->get('entity_type.manager'),
      $container->get('date.formatter'),
      $container->get('lending_library.stats_collector')
    );
  }

  /**
   * Displays the dashboard.
   */
  public function dashboard() {
    $stats = $this->getStats();
    $triggers = $this->getUpcomingTriggers();
    $activity = $this->getRecentActivity();
    $grouped_links = $this->getGroupedLinks();
    $due_soon_items = $this->getDueSoonItems();
    $overdue_items = $this->getOverdueItems();
    $active_loans = $this->getActiveLoans();
    $debtors = $this->getDebtors();
    $damaged_items = $this->getDamagedItems();
    $key_stats = $this->getKeyStats();

    return [
      '#theme' => 'lending_library_dashboard',
      '#stats' => $stats,
      '#triggers' => $triggers,
      '#recent_activity' => $activity,
      '#grouped_links' => $grouped_links,
      '#due_soon_items' => $due_soon_items,
      '#overdue_items' => $overdue_items,
      '#active_loans' => $active_loans,
      '#debtors' => $debtors,
      '#damaged_items' => $damaged_items,
      '#key_stats' => $key_stats,
      '#attached' => [
        'library' => [
          'lending_library/lending_library.actions',
        ],
      ],
    ];
  }

  /**
   * Gather general statistics.
   */
  protected function getStats() {
    $node_storage = $this->entityTypeManager->getStorage('node');
    
    // Item status counts.
    $count_alias = 'count';
    $query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'library_item')
      ->groupBy('field_library_item_status')
      ->aggregate('field_library_item_status', 'COUNT', NULL, $count_alias);
    $results = $query->execute();
    
    $statuses = [];
    foreach ($results as $res) {
      $statuses[$res['field_library_item_status']] = $res['count'];
    }

    // Amount Owed (Sum of field_library_amount_due on Transactions).
    $transaction_storage = $this->entityTypeManager->getStorage('library_transaction');
    $total_due_alias = 'total_due';
    $query = $transaction_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'library_transaction')
      ->condition('field_library_closed', 1, '<>')
      ->aggregate('field_library_amount_due', 'SUM', NULL, $total_due_alias);
    $results = $query->execute();
    $total_owed = $results[0]['total_due'] ?? 0;

    return [
      'lent_items' => $statuses['borrowed'] ?? 0,
      'available_items' => $statuses['available'] ?? 0,
      'repair_items' => $statuses['repair'] ?? 0,
      'missing_items' => $statuses['missing'] ?? 0,
      'total_owed' => $total_owed,
    ];
  }

  /**
   * Identify upcoming system triggers (emails/fees).
   */
  protected function getUpcomingTriggers() {
    $now = new DrupalDateTime('now');
    $one_day_future = (clone $now)->modify('+1 day');
    $due_soon_transactions = $this->loadActiveLoanTransactions(function (QueryInterface $query) use ($now, $one_day_future) {
      $query
        ->condition('field_library_due_date', $now->format('Y-m-d\TH:i:s'), '>')
        ->condition('field_library_due_date', $one_day_future->format('Y-m-d\TH:i:s'), '<')
        ->sort('field_library_due_date', 'ASC');
    });

    $overdue_transactions = $this->loadActiveLoanTransactions(function (QueryInterface $query) use ($now) {
      $query
        ->condition('field_library_due_date', $now->format('Y-m-d\TH:i:s'), '<')
        ->sort('field_library_due_date', 'ASC');
    });

    return [
      'due_soon_count' => count($due_soon_transactions),
      'overdue_count' => count($overdue_transactions),
    ];
  }

  /**
   * Get recent log entries for lending_library.
   */
  protected function getRecentActivity() {
    // Check if dblog module is enabled/table exists.
    if (!$this->database->schema()->tableExists('watchdog')) {
      return [];
    }

    $query = $this->database->select('watchdog', 'w')
      ->fields('w', ['message', 'variables', 'timestamp', 'severity'])
      ->condition('type', 'lending_library')
      ->orderBy('timestamp', 'DESC')
      ->range(0, 20);
    
    $results = $query->execute()->fetchAll();
    $logs = [];

    foreach ($results as $row) {
      // Use allowed_classes: false to prevent object instantiation from untrusted data.
      $variables = @unserialize($row->variables, ['allowed_classes' => FALSE]);
      $message = $row->message;
      if (is_array($variables)) {
        $message = strtr($message, $variables);
      }
      $logs[] = [
        'message' => strip_tags($message),
        'time' => $this->dateFormatter->format($row->timestamp, 'short'),
        'severity' => $row->severity,
      ];
    }

    return $logs;
  }

  /**
   * Fetch key usage stats (30/90 days).
   */
  protected function getKeyStats() {
    $data = $this->statsCollector->collect();
    $last_month = $data['periods']['last_month']['metrics'] ?? [];
    $rolling_90 = $data['periods']['rolling_90_days']['metrics'] ?? [];

    return [
      'last_month' => [
        'loans' => $last_month['loan_count'] ?? 0,
        'users' => $last_month['unique_borrowers'] ?? 0,
      ],
      'rolling_90' => [
        'loans' => $rolling_90['loan_count'] ?? 0,
        'users' => $rolling_90['unique_borrowers'] ?? 0,
      ],
    ];
  }

  /**
   * Fetch items currently marked as 'repair'.
   */
  protected function getDamagedItems() {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'library_item')
      ->condition('field_library_item_status', 'repair')
      ->sort('changed', 'DESC')
      ->range(0, 10);

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    $nodes = $node_storage->loadMultiple($nids);
    $items = [];
    foreach ($nodes as $node) {
      $items[] = [
        'title' => $node->label(),
        'nid' => $node->id(),
        'changed' => $this->dateFormatter->format($node->getChangedTime(), 'short'),
      ];
    }
    return $items;
  }

  /**
   * Fetch overdue items.
   */
  protected function getOverdueItems() {
    $now = new DrupalDateTime('now');
    $transactions = $this->loadActiveLoanTransactions(function (QueryInterface $query) use ($now) {
      $query
        ->condition('field_library_due_date', $now->format('Y-m-d\TH:i:s'), '<')
        ->sort('field_library_due_date', 'ASC');
    }, 10);

    if (empty($transactions)) {
      return [];
    }
    $items = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($transactions as $t) {
      $borrower = 'Unknown';
      if (!$t->get('field_library_borrower')->isEmpty()) {
        $uid = $t->get('field_library_borrower')->target_id;
        $user = $user_storage->load($uid);
        if ($user) {
          $borrower = $user->getDisplayName();
        }
      }

      $tool = 'Unknown Tool';
      $tool_id = NULL;
      if (!$t->get('field_library_item')->isEmpty()) {
        $nid = $t->get('field_library_item')->target_id;
        $tool_node = $node_storage->load($nid);
        if ($tool_node) {
          $tool = $tool_node->label();
          $tool_id = $nid;
        }
      }

      $due_date = $t->get('field_library_due_date')->date;
      $days_overdue = $due_date ? $due_date->diff($now)->days : 0;

      $items[] = [
        'tool' => $tool,
        'tool_id' => $tool_id,
        'borrower' => $borrower,
        'due_date' => $due_date ? $this->dateFormatter->format($due_date->getTimestamp(), 'short') : $this->t('Unknown'),
        'days_overdue' => $days_overdue,
      ];
    }
    return $items;
  }

  /**
   * Fetch active loans.
   */
  protected function getActiveLoans() {
    $now = new DrupalDateTime('now');
    $transactions = $this->loadActiveLoanTransactions(function (QueryInterface $query) use ($now) {
      $query->sort('field_library_due_date', 'ASC');
    }, 10);

    if (empty($transactions)) {
      return [];
    }
    $items = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($transactions as $t) {
      $borrower = 'Unknown';
      if (!$t->get('field_library_borrower')->isEmpty()) {
        $uid = $t->get('field_library_borrower')->target_id;
        $user = $user_storage->load($uid);
        if ($user) {
          $borrower = $user->getDisplayName();
        }
      }

      $tool = 'Unknown Tool';
      $tool_id = NULL;
      if (!$t->get('field_library_item')->isEmpty()) {
        $nid = $t->get('field_library_item')->target_id;
        $tool_node = $node_storage->load($nid);
        if ($tool_node) {
          $tool = $tool_node->label();
          $tool_id = $nid;
        }
      }

      $batteries = [];
      if ($t->hasField('field_library_borrow_batteries') && !$t->get('field_library_borrow_batteries')->isEmpty()) {
        $battery_entities = $t->get('field_library_borrow_batteries')->referencedEntities();
        foreach ($battery_entities as $battery) {
          $batteries[] = $battery->label();
        }
      }
      $battery_str = implode(', ', $batteries);

      $transaction_url = Url::fromRoute('entity.library_transaction.edit_form', ['library_transaction' => $t->id()])->toString();

      $due_date = $t->get('field_library_due_date')->date;

      $items[] = [
        'tool' => $tool,
        'tool_id' => $tool_id,
        'borrower' => $borrower,
        'batteries' => $battery_str,
        'due_date' => $due_date ? $this->dateFormatter->format($due_date->getTimestamp(), 'short') : $this->t('Unknown'),
        'id' => $t->id(),
        'transaction_url' => $transaction_url,
      ];
    }
    return $items;
  }

  /**
   * Fetch users who owe money.
   */
  protected function getDebtors() {
    $storage = $this->entityTypeManager->getStorage('library_transaction');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'library_transaction')
      ->condition('field_library_amount_due', 0, '>')
      ->condition('field_library_closed', 1, '<>')
      ->sort('field_library_amount_due', 'DESC')
      ->range(0, 10);

    $ids = $query->execute();
    if (empty($ids)) {
      return [];
    }

    $transactions = $storage->loadMultiple($ids);
    $debtors = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($transactions as $t) {
      $borrower = 'Unknown';
      if (!$t->get('field_library_borrower')->isEmpty()) {
        $uid = $t->get('field_library_borrower')->target_id;
        $user = $user_storage->load($uid);
        if ($user) {
          $borrower = $user->getDisplayName();
        }
      }

      $tool = 'Unknown Tool';
      if (!$t->get('field_library_item')->isEmpty()) {
        $nid = $t->get('field_library_item')->target_id;
        $tool_node = $node_storage->load($nid);
        if ($tool_node) {
          $tool = $tool_node->label();
        }
      }

      $amount_due = $t->get('field_library_amount_due')->value;

      $due_date_display = NULL;
      if (!$t->get('field_library_due_date')->isEmpty()) {
        $due_date_value = $t->get('field_library_due_date')->date;
        if ($due_date_value instanceof DrupalDateTime) {
          $due_date_display = $this->dateFormatter->format($due_date_value->getTimestamp(), 'short');
        }
      }

      $transaction_url = Url::fromRoute('entity.library_transaction.edit_form', ['library_transaction' => $t->id()])->toString();

      $debtors[] = [
        'borrower' => $borrower,
        'tool' => $tool,
        'amount' => $amount_due,
        'id' => $t->id(),
        'due_date' => $due_date_display,
        'transaction_url' => $transaction_url,
      ];
    }
    return $debtors;
  }

  /**
   * Fetch items due in the next 48 hours.
   */
  protected function getDueSoonItems() {
    $now = new DrupalDateTime('now');
    $future = (clone $now)->modify('+2 days');

    $transactions = $this->loadActiveLoanTransactions(function (QueryInterface $query) use ($now, $future) {
      $query
        ->condition('field_library_due_date', $now->format('Y-m-d\TH:i:s'), '>=')
        ->condition('field_library_due_date', $future->format('Y-m-d\TH:i:s'), '<=')
        ->sort('field_library_due_date', 'ASC');
    }, 10);

    if (empty($transactions)) {
      return [];
    }
    $items = [];
    $user_storage = $this->entityTypeManager->getStorage('user');
    $node_storage = $this->entityTypeManager->getStorage('node');

    foreach ($transactions as $t) {
      $borrower = 'Unknown';
      if (!$t->get('field_library_borrower')->isEmpty()) {
        $uid = $t->get('field_library_borrower')->target_id;
        $user = $user_storage->load($uid);
        if ($user) {
          $borrower = $user->getDisplayName();
        }
      }

      $tool = 'Unknown Tool';
      $tool_id = NULL;
      if (!$t->get('field_library_item')->isEmpty()) {
        $nid = $t->get('field_library_item')->target_id;
        $tool_node = $node_storage->load($nid);
        if ($tool_node) {
          $tool = $tool_node->label();
          $tool_id = $nid;
        }
      }

      $items[] = [
        'tool' => $tool,
        'tool_id' => $tool_id,
        'borrower' => $borrower,
        'due_date' => $this->dateFormatter->format($t->get('field_library_due_date')->date->getTimestamp(), 'short'),
        'id' => $t->id(),
      ];
    }
    return $items;
  }

  /**
   * Grouped quick links for managers.
   */
  protected function getGroupedLinks() {
    return [
      'Daily Operations' => [
        [
          'title' => 'Transactions',
          'url' => '/library/transactions',
          'description' => 'View all library transactions.',
          'sub_links' => [
            ['title' => 'Details', 'url' => '/library/transactions/detail'],
          ],
        ],
        [
          'title' => 'Overdue Items',
          'url' => '/library/transactions/due',
          'description' => 'View items currently overdue.',
        ],
        [
          'title' => 'Add Library Item',
          'url' => '/node/add/library_item',
          'description' => 'Create a new item in the lending library.',
        ],
      ],
      'Inventory Management' => [
        [
          'title' => 'Tool Audit',
          'url' => '/library/audit',
          'description' => 'Audit tool inventory.',
        ],
        [
          'title' => 'Manage Batteries',
          'url' => '/library/batteries',
          'description' => 'View and manage battery inventory.',
        ],
      ],
      'Financial & Config' => [
        [
          'title' => 'Outstanding Fees',
          'url' => '/library/transactions/detail/money',
          'description' => 'View unpaid fees.',
        ],
        [
          'title' => 'Library Settings',
          'url' => '/admin/config/makehaven/lending-library',
          'description' => 'Configure email templates and fees.',
        ],
      ],
    ];
  }
}
