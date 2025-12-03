<?php

namespace Drupal\lending_library\Service;

use Drupal\Component\Datetime\TimeInterface;
use Drupal\Core\Datetime\DateFormatterInterface;
use Drupal\Core\Datetime\DrupalDateTime;
use Drupal\Core\Entity\EntityInterface;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Field\FieldItemListInterface;
use Drupal\node\NodeInterface;

/**
 * Builds aggregate statistics for the lending library.
 */
class StatsCollector implements StatsCollectorInterface {

  protected const ACTION_WITHDRAW = 'withdraw';
  protected const ITEM_NODE_TYPE = 'library_item';
  protected const ITEM_STATUS_FIELD = 'field_library_item_status';
  protected const ITEM_STATUS_BORROWED = 'borrowed';
  protected const ITEM_BORROWER_FIELD = 'field_library_item_borrower';
  protected const ITEM_REPLACEMENT_VALUE_FIELD = 'field_library_item_replacement_v';
  protected const ITEM_CATEGORY_FIELD = 'field_library_item_category';
  protected const ITEM_WAITLIST_FIELD = 'field_library_item_waitlist';
  protected const TRANSACTION_ENTITY_TYPE = 'library_transaction';
  protected const TRANSACTION_BUNDLE = 'library_transaction';
  protected const TRANSACTION_ITEM_FIELD = 'field_library_item';
  protected const TRANSACTION_ACTION_FIELD = 'field_library_action';
  protected const TRANSACTION_BORROWER_FIELD = 'field_library_borrower';
  protected const TRANSACTION_BORROW_DATE_FIELD = 'field_library_borrow_date';
  protected const TRANSACTION_DUE_DATE_FIELD = 'field_library_due_date';
  protected const TRANSACTION_RETURN_DATE_FIELD = 'field_library_return_date';
  protected const TRANSACTION_CLOSED_FIELD = 'field_library_closed';
  protected const BATTERY_STATUS_FIELD = 'field_battery_status';
  protected const BATTERY_BORROWER_FIELD = 'field_battery_borrower';
  protected const BATTERY_STATUS_BORROWED = 'borrowed';
  protected const MONTHLY_LOAN_SERIES_MIN_START = '2025-08-01';
  protected const UNCATEGORIZED_KEY = '__uncategorized';

  protected EntityTypeManagerInterface $entityTypeManager;
  protected DateFormatterInterface $dateFormatter;
  protected TimeInterface $time;
  protected \DateTimeZone $timezone;

  /**
   * StatsCollector constructor.
   */
  public function __construct(EntityTypeManagerInterface $entity_type_manager, DateFormatterInterface $date_formatter, TimeInterface $time) {
    $this->entityTypeManager = $entity_type_manager;
    $this->dateFormatter = $date_formatter;
    $this->time = $time;
    $this->timezone = new \DateTimeZone(date_default_timezone_get() ?: 'UTC');
  }

  /**
   * {@inheritdoc}
   */
  public function collect(): array {
    $now = $this->now();

    $last_month_bounds = $this->getLastMonthBounds($now);
    $last_month_dataset = $this->loadLoanDataset($last_month_bounds['start'], $last_month_bounds['end']);

    $rolling_90_bounds = $this->getRollingBounds($now, 90);
    $rolling_90_dataset = $this->loadLoanDataset($rolling_90_bounds['start'], $rolling_90_bounds['end']);

    $current = $this->buildCurrentStats();

    $month_window = $this->getMonthSeriesWindow($now, 12);
    $series_start = $this->enforceMonthlySeriesStart($month_window['start']);
    if ($series_start >= $month_window['end']) {
      $twelve_month_dataset = [
        'transactions' => [],
        'items' => [],
      ];
      $monthly_series = [];
      $monthly_value_series = [];
    }
    else {
      $series_months = $this->calculateMonthSpan($series_start, $month_window['end']);
      $twelve_month_dataset = $this->loadLoanDataset($series_start, $month_window['end']);
      $twelve_month_transactions = $twelve_month_dataset['transactions'];
      $monthly_series = $this->buildMonthlyLoanSeries($twelve_month_transactions, $series_start, $series_months);
      $monthly_value_series = $this->buildMonthlyLoanValueSeries($twelve_month_dataset, $series_start, $series_months);
    }

    $category_distribution = $this->buildCategoryDistribution($rolling_90_dataset);
    $battery_stats = $this->buildBatteryStats();
    $inventory_totals = $this->buildInventoryTotals();
    $retention_insights = $this->buildMembershipRetentionInsights();
    $retention_cohorts = $this->buildMembershipCohorts();

    $stats = [
      'generated' => $now->getTimestamp(),
      'current' => $current,
      'inventory_totals' => $inventory_totals,
      'periods' => [
        'last_month' => [
          'label' => $this->dateFormatter->format($last_month_bounds['start']->getTimestamp(), 'custom', 'F Y'),
          'start' => $last_month_bounds['start']->getTimestamp(),
          'end' => $last_month_bounds['end']->getTimestamp(),
          'metrics' => $this->buildLoanSummary($last_month_dataset),
        ],
        'rolling_90_days' => [
          'label' => $this->dateFormatter->format($rolling_90_bounds['start']->getTimestamp(), 'custom', 'M j') . ' – ' . $this->dateFormatter->format($rolling_90_bounds['end']->modify('-1 day')->getTimestamp(), 'custom', 'M j'),
          'start' => $rolling_90_bounds['start']->getTimestamp(),
          'end' => $rolling_90_bounds['end']->getTimestamp(),
          'metrics' => $this->buildLoanSummary($rolling_90_dataset),
        ],
      ],
      'chart_data' => [
        'monthly_loans' => $monthly_series,
        'top_categories' => $category_distribution,
        'loan_value_vs_items' => $monthly_value_series,
      ],
      'batteries' => $battery_stats,
      'retention_insights' => $retention_insights,
      'retention_cohorts' => $retention_cohorts,
    ];

    return $stats;
  }

  /**
   * {@inheritdoc}
   */
  public function buildSnapshotPayload(?array $stats = NULL): array {
    $stats = $stats ?? $this->collect();
    $snapshot = [
      'generated' => $stats['generated'],
      'active_loans' => $stats['current']['active_loans'],
      'active_borrowers' => $stats['current']['active_borrowers'],
      'inventory_value_on_loan' => round($stats['current']['inventory_value_on_loan'], 2),
      'overdue_loans' => $stats['current']['overdue_loans'],
    ];

    if (!empty($stats['periods']['last_month']['metrics'])) {
      $monthly = $stats['periods']['last_month']['metrics'];
      $snapshot += [
        'loans_last_month' => $monthly['loan_count'],
        'unique_borrowers_last_month' => $monthly['unique_borrowers'],
        'total_value_borrowed_last_month' => round($monthly['total_value'], 2),
        'avg_loan_length_last_month' => $monthly['average_loan_length_days'],
        'median_loan_length_last_month' => $monthly['median_loan_length_days'],
        'repeat_borrower_rate_last_month' => $monthly['repeat_borrower_rate'],
      ];
    }

    if (!empty($stats['periods']['rolling_90_days']['metrics'])) {
      $rolling = $stats['periods']['rolling_90_days']['metrics'];
      $snapshot += [
        'loans_last_90_days' => $rolling['loan_count'],
        'unique_borrowers_last_90_days' => $rolling['unique_borrowers'],
        'on_time_return_rate_90_days' => $rolling['on_time_return_rate'],
      ];
    }

    return $snapshot;
  }

  /**
   * Builds aggregated statistics for a dataset.
   */
  protected function buildLoanSummary(array $dataset): array {
    $transactions = $dataset['transactions'];
    $items = $dataset['items'];

    if (empty($transactions)) {
      return [
        'loan_count' => 0,
        'unique_borrowers' => 0,
        'unique_items' => 0,
        'total_value' => 0.0,
        'average_value_per_loan' => NULL,
        'average_loans_per_borrower' => NULL,
        'average_loan_length_days' => NULL,
        'median_loan_length_days' => NULL,
        'completed_loans' => 0,
        'repeat_borrower_rate' => NULL,
        'on_time_return_rate' => NULL,
      ];
    }

    $loan_count = count($transactions);
    $borrower_counts = [];
    $item_ids = [];
    $durations = [];
    $total_value = 0.0;

    foreach ($transactions as $transaction) {
      $is_closed = $transaction->hasField(self::TRANSACTION_CLOSED_FIELD)
        && !$transaction->get(self::TRANSACTION_CLOSED_FIELD)->isEmpty()
        && (bool) $transaction->get(self::TRANSACTION_CLOSED_FIELD)->value;

      $borrower_id = $this->getBorrowerId($transaction);
      if ($borrower_id) {
        if (!isset($borrower_counts[$borrower_id])) {
          $borrower_counts[$borrower_id] = 0;
        }
        $borrower_counts[$borrower_id]++;
      }

      $item_id = NULL;
      if ($transaction->hasField(self::TRANSACTION_ITEM_FIELD) && !$transaction->get(self::TRANSACTION_ITEM_FIELD)->isEmpty()) {
        $item_id = (int) $transaction->get(self::TRANSACTION_ITEM_FIELD)->target_id;
      }
      if ($item_id) {
        $item_ids[$item_id] = TRUE;
        if (isset($items[$item_id]) && $items[$item_id] instanceof NodeInterface) {
          $total_value += $this->getReplacementValue($items[$item_id]);
        }
      }

      $borrow_date = $this->getDateFromField($transaction->get(self::TRANSACTION_BORROW_DATE_FIELD));
      $return_date = $this->getDateFromField($transaction->get(self::TRANSACTION_RETURN_DATE_FIELD));

      if (!$return_date && $is_closed) {
        $return_date = $this->getDateFromField($transaction->get(self::TRANSACTION_DUE_DATE_FIELD));
      }

      if ($is_closed && $borrow_date && $return_date && $return_date >= $borrow_date) {
        $durations[] = $this->diffInDays($borrow_date, $return_date);
      }
    }

    $unique_borrowers = count($borrower_counts);
    $completed_loans = count($durations);
    $repeat_borrowers = array_reduce($borrower_counts, static function ($carry, $count) {
      return $carry + ($count > 1 ? 1 : 0);
    }, 0);

    $on_time = $this->calculateOnTimePerformance($transactions);

    return [
      'loan_count' => $loan_count,
      'unique_borrowers' => $unique_borrowers,
      'unique_items' => count($item_ids),
      'total_value' => round($total_value, 2),
      'average_value_per_loan' => $loan_count ? round($total_value / $loan_count, 2) : NULL,
      'average_loans_per_borrower' => $unique_borrowers ? round($loan_count / $unique_borrowers, 2) : NULL,
      'average_loan_length_days' => $completed_loans ? round(array_sum($durations) / $completed_loans, 2) : NULL,
      'median_loan_length_days' => $this->calculateMedian($durations),
      'completed_loans' => $completed_loans,
      'repeat_borrower_rate' => $unique_borrowers ? round($repeat_borrowers / $unique_borrowers, 3) : NULL,
      'on_time_return_rate' => $on_time['rate'],
    ];
  }

  /**
   * Retrieves the borrower ID from a transaction entity.
   */
  protected function getBorrowerId(EntityInterface $transaction): ?int {
    if ($transaction->hasField(self::TRANSACTION_BORROWER_FIELD) && !$transaction->get(self::TRANSACTION_BORROWER_FIELD)->isEmpty()) {
      $target = $transaction->get(self::TRANSACTION_BORROWER_FIELD)->target_id;
      return $target ? (int) $target : NULL;
    }

    $owner_id = $transaction->getOwnerId();
    return $owner_id ? (int) $owner_id : NULL;
  }

  /**
   * Calculates on-time return rate for a batch of transactions.
   */
  protected function calculateOnTimePerformance(array $transactions): array {
    $completed = 0;
    $on_time = 0;

    foreach ($transactions as $transaction) {
      $return_date = $this->getDateFromField($transaction->get(self::TRANSACTION_RETURN_DATE_FIELD));
      $due_date = $this->getDateFromField($transaction->get(self::TRANSACTION_DUE_DATE_FIELD));
      if ($return_date && $due_date) {
        $completed++;
        if ($return_date <= $due_date) {
          $on_time++;
        }
      }
    }

    return [
      'completed' => $completed,
      'on_time' => $on_time,
      'rate' => $completed ? round($on_time / $completed, 3) : NULL,
    ];
  }

  /**
   * Builds the current operational snapshot.
   */
  protected function buildCurrentStats(): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->condition(self::ITEM_STATUS_FIELD, self::ITEM_STATUS_BORROWED);

    $ids = $query->execute();
    $nodes = $ids ? $node_storage->loadMultiple($ids) : [];

    $borrowers = [];
    $value_on_loan = 0.0;

    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $value_on_loan += $this->getReplacementValue($node);
        if ($node->hasField(self::ITEM_BORROWER_FIELD) && !$node->get(self::ITEM_BORROWER_FIELD)->isEmpty()) {
          $borrowers[$node->get(self::ITEM_BORROWER_FIELD)->target_id] = TRUE;
        }
      }
    }

    $overdue = $this->buildOverdueSummary();

    return [
      'active_loans' => count($nodes),
      'active_borrowers' => count($borrowers),
      'inventory_value_on_loan' => round($value_on_loan, 2),
      'overdue_loans' => $overdue['count'],
      'borrowers_with_overdue' => $overdue['borrowers'],
    ];
  }

  /**
   * Summarizes overdue withdraw transactions.
   */
  protected function buildOverdueSummary(): array {
    $storage = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->condition(self::TRANSACTION_ACTION_FIELD, self::ACTION_WITHDRAW)
      ->condition(self::TRANSACTION_CLOSED_FIELD, 1, '<>');

    $today = $this->now()->format('Y-m-d');
    $query->condition(self::TRANSACTION_DUE_DATE_FIELD . '.value', $today, '<');
    $query->condition(self::TRANSACTION_ITEM_FIELD . '.entity.' . self::ITEM_STATUS_FIELD, self::ITEM_STATUS_BORROWED);

    $ids = $query->execute();
    if (empty($ids)) {
      return [
        'count' => 0,
        'borrowers' => 0,
      ];
    }
    $transactions = $storage->loadMultiple($ids);
    $borrowers = [];
    foreach ($transactions as $transaction) {
      $borrower_id = $this->getBorrowerId($transaction);
      if ($borrower_id) {
        $borrowers[$borrower_id] = TRUE;
      }
    }

    return [
      'count' => count($transactions),
      'borrowers' => count($borrowers),
    ];
  }

  /**
   * Builds aggregate stats about batteries.
   */
  protected function buildBatteryStats(): array {
    if (!$this->entityTypeManager->hasDefinition('battery')) {
      return [];
    }

    $storage = $this->entityTypeManager->getStorage('battery');
    $ids = $storage->getQuery()
      ->accessCheck(FALSE)
      ->execute();

    if (empty($ids)) {
      return [];
    }

    $batteries = $storage->loadMultiple($ids);
    $status_counts = [
      'borrowed' => 0,
      'available' => 0,
      'missing' => 0,
      'other' => 0,
    ];
    $borrowers = [];

    foreach ($batteries as $battery) {
      $status = 'other';
      if ($battery->hasField(self::BATTERY_STATUS_FIELD) && !$battery->get(self::BATTERY_STATUS_FIELD)->isEmpty()) {
        $status_value = $battery->get(self::BATTERY_STATUS_FIELD)->value;
        if (isset($status_counts[$status_value])) {
          $status = $status_value;
        }
      }
      $status_counts[$status]++;

      if ($status === self::BATTERY_STATUS_BORROWED && $battery->hasField(self::BATTERY_BORROWER_FIELD) && !$battery->get(self::BATTERY_BORROWER_FIELD)->isEmpty()) {
        $borrowers[(int) $battery->get(self::BATTERY_BORROWER_FIELD)->target_id] = TRUE;
      }
    }

    $total = array_sum($status_counts);
    $borrowed = $status_counts['borrowed'] ?? 0;

    return [
      'total' => $total,
      'borrowed' => $borrowed,
      'available' => $status_counts['available'] ?? 0,
      'missing' => $status_counts['missing'] ?? 0,
      'other' => $status_counts['other'] ?? 0,
      'borrower_count' => count($borrowers),
      'borrowed_ratio' => $total ? round(($borrowed / $total) * 100, 1) : 0,
    ];
  }

  /**
   * Builds borrower vs non-borrower active membership rates by join year.
   */
  protected function buildMembershipCohorts(int $years = 5): array {
    if (!$this->entityTypeManager->hasDefinition('profile')) {
      return [];
    }

    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $user_storage = $this->entityTypeManager->getStorage('user');
    $current_year = (int) $this->now()->format('Y');
    $start_year = $current_year - ($years - 1);
    $start_date = (new \DateTimeImmutable("first day of January $start_year", $this->timezone))->setTime(0, 0);

    $profile_ids = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->condition('created', $start_date->getTimestamp(), '>=')
      ->execute();

    if (empty($profile_ids)) {
      return [];
    }

    $profiles = $profile_storage->loadMultiple($profile_ids);
    $buckets = [];
    for ($year = $start_year; $year <= $current_year; $year++) {
      $buckets[$year] = [
        'borrower' => ['active' => 0, 'total' => 0],
        'non_borrower' => ['active' => 0, 'total' => 0],
      ];
    }

    $user_cache = [];
    foreach ($profiles as $profile) {
      $year = (int) (new \DateTimeImmutable('@' . $profile->getCreatedTime()))->setTimezone($this->timezone)->format('Y');
      if ($year < $start_year || $year > $current_year) {
        continue;
      }

      $uid = $profile->getOwnerId();
      if (!$uid) {
        continue;
      }

      if (!isset($user_cache[$uid])) {
        $user_cache[$uid] = $user_storage->load($uid);
      }
      $account = $user_cache[$uid];
      if (!$account) {
        continue;
      }

      $bucket_key = $account->hasRole('borrower') ? 'borrower' : 'non_borrower';
      $buckets[$year][$bucket_key]['total']++;
      if ($account->hasRole('member')) {
        $buckets[$year][$bucket_key]['active']++;
      }
    }

    $output = [];
    for ($year = $current_year; $year >= $start_year; $year--) {
      if (!isset($buckets[$year])) {
        continue;
      }
      $borrower = $buckets[$year]['borrower'];
      $non = $buckets[$year]['non_borrower'];
      $output[] = [
        'year' => $year,
        'borrower' => [
          'total' => $borrower['total'],
          'active' => $borrower['active'],
          'rate' => $borrower['total'] ? round(($borrower['active'] / $borrower['total']) * 100, 1) : NULL,
        ],
        'non_borrower' => [
          'total' => $non['total'],
          'active' => $non['active'],
          'rate' => $non['total'] ? round(($non['active'] / $non['total']) * 100, 1) : NULL,
        ],
      ];
    }

    return $output;
  }

  /**
   * Builds the overall inventory totals (count + replacement value).
   */
  protected function buildInventoryTotals(): array {
    $storage = $this->entityTypeManager->getStorage('node');
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE);

    $ids = $query->execute();
    if (empty($ids)) {
      return [
        'count' => 0,
        'value' => 0,
      ];
    }

    $nodes = $storage->loadMultiple($ids);
    $value = 0.0;
    foreach ($nodes as $node) {
      if ($node instanceof NodeInterface) {
        $value += $this->getReplacementValue($node);
      }
    }

    return [
      'count' => count($nodes),
      'value' => round($value, 2),
    ];
  }

  /**
   * Builds a dataset containing transactions and the referenced items.
   */
  protected function loadLoanDataset(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $transactions = $this->loadWithdrawals($start, $end);
    $item_ids = [];
    foreach ($transactions as $transaction) {
      if ($transaction->hasField(self::TRANSACTION_ITEM_FIELD) && !$transaction->get(self::TRANSACTION_ITEM_FIELD)->isEmpty()) {
        $item_id = (int) $transaction->get(self::TRANSACTION_ITEM_FIELD)->target_id;
        if ($item_id) {
          $item_ids[$item_id] = TRUE;
        }
      }
    }

    $items = [];
    if (!empty($item_ids)) {
      $items = $this->entityTypeManager->getStorage('node')->loadMultiple(array_keys($item_ids));
    }

    return [
      'transactions' => $transactions,
      'items' => $items,
    ];
  }

  /**
   * Loads withdraw transactions in the provided window.
   */
  protected function loadWithdrawals(\DateTimeImmutable $start, \DateTimeImmutable $end): array {
    $storage = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE);
    $query = $storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->condition(self::TRANSACTION_ACTION_FIELD, self::ACTION_WITHDRAW)
      ->condition(self::TRANSACTION_BORROW_DATE_FIELD . '.value', $start->format('Y-m-d'), '>=')
      ->condition(self::TRANSACTION_BORROW_DATE_FIELD . '.value', $end->format('Y-m-d'), '<')
      ->sort(self::TRANSACTION_BORROW_DATE_FIELD . '.value', 'ASC');

    $ids = $query->execute();
    return $ids ? $storage->loadMultiple($ids) : [];
  }

  /**
   * Builds the month → loan count series.
   */
  protected function buildMonthlyLoanSeries(array $transactions, \DateTimeImmutable $start, int $months): array {
    if ($months <= 0) {
      return [];
    }

    $series = [];
    for ($i = 0; $i < $months; $i++) {
      $month_start = $start->modify("+$i months");
      $series[$month_start->format('Y-m')] = [
        'key' => $month_start->format('Y-m'),
        'label' => $this->dateFormatter->format($month_start->getTimestamp(), 'custom', 'M Y'),
        'value' => 0,
      ];
    }
    $end = $start->modify("+$months months");

    foreach ($transactions as $transaction) {
      $borrow_date = $this->getDateFromField($transaction->get(self::TRANSACTION_BORROW_DATE_FIELD));
      if (!$borrow_date || $borrow_date < $start || $borrow_date >= $end) {
        continue;
      }
      $key = $borrow_date->format('Y-m');
      if (isset($series[$key])) {
        $series[$key]['value']++;
      }
    }

    return array_values($series);
  }

  /**
   * Builds the monthly value dataset used to correlate loans vs. value.
   */
  protected function buildMonthlyLoanValueSeries(array $dataset, \DateTimeImmutable $start, int $months): array {
    if ($months <= 0) {
      return [];
    }

    $transactions = $dataset['transactions'] ?? [];
    $items = $dataset['items'] ?? [];

    $series = [];
    $itemBuckets = [];
    for ($i = 0; $i < $months; $i++) {
      $month_start = $start->modify("+$i months");
      $key = $month_start->format('Y-m');
      $series[$key] = [
        'key' => $key,
        'label' => $this->dateFormatter->format($month_start->getTimestamp(), 'custom', 'M Y'),
        'loan_count' => 0,
        'unique_items' => 0,
        'total_value' => 0.0,
      ];
      $itemBuckets[$key] = [];
    }

    $end = $start->modify("+$months months");
    foreach ($transactions as $transaction) {
      $borrow_date = $this->getDateFromField($transaction->get(self::TRANSACTION_BORROW_DATE_FIELD));
      if (!$borrow_date || $borrow_date < $start || $borrow_date >= $end) {
        continue;
      }
      $key = $borrow_date->format('Y-m');
      if (!isset($series[$key])) {
        continue;
      }

      $series[$key]['loan_count']++;
      $item_id = NULL;
      if ($transaction->hasField(self::TRANSACTION_ITEM_FIELD) && !$transaction->get(self::TRANSACTION_ITEM_FIELD)->isEmpty()) {
        $item_id = (int) $transaction->get(self::TRANSACTION_ITEM_FIELD)->target_id;
      }

      if ($item_id) {
        $itemBuckets[$key][$item_id] = TRUE;
        if (isset($items[$item_id]) && $items[$item_id] instanceof NodeInterface) {
          $series[$key]['total_value'] += $this->getReplacementValue($items[$item_id]);
        }
      }
    }

    foreach ($series as $key => &$point) {
      $point['unique_items'] = isset($itemBuckets[$key]) ? count($itemBuckets[$key]) : 0;
      $point['total_value'] = round($point['total_value'], 2);
    }
    unset($point);

    return array_values($series);
  }

  /**
   * Builds the top category distribution for the provided dataset.
   */
  protected function buildCategoryDistribution(array $dataset, int $limit = 6): array {
    $transactions = $dataset['transactions'];
    $items = $dataset['items'];
    if (empty($transactions) || empty($items)) {
      return [];
    }

    $counts = [];
    foreach ($transactions as $transaction) {
      $item_id = NULL;
      if ($transaction->hasField(self::TRANSACTION_ITEM_FIELD) && !$transaction->get(self::TRANSACTION_ITEM_FIELD)->isEmpty()) {
        $item_id = (int) $transaction->get(self::TRANSACTION_ITEM_FIELD)->target_id;
      }

      if (!$item_id || empty($items[$item_id]) || !$items[$item_id]->hasField(self::ITEM_CATEGORY_FIELD)) {
        $counts[self::UNCATEGORIZED_KEY] = ($counts[self::UNCATEGORIZED_KEY] ?? 0) + 1;
        continue;
      }

      $field = $items[$item_id]->get(self::ITEM_CATEGORY_FIELD);
      if ($field->isEmpty()) {
        $counts[self::UNCATEGORIZED_KEY] = ($counts[self::UNCATEGORIZED_KEY] ?? 0) + 1;
        continue;
      }

      foreach ($field as $item) {
        $tid = (int) $item->target_id;
        if ($tid) {
          $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        }
      }
    }

    $total = array_sum($counts);
    if ($total === 0) {
      return [];
    }

    arsort($counts);

    $term_ids = array_filter(array_keys($counts), static function ($key) {
      return is_int($key);
    });
    $terms = $term_ids ? $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple($term_ids) : [];

    $output = [];
    foreach ($counts as $key => $count) {
      $label = 'Uncategorized';
      if ($key !== self::UNCATEGORIZED_KEY) {
        $label = isset($terms[$key]) ? $terms[$key]->label() : 'Unknown';
      }
      $output[] = [
        'label' => $label,
        'value' => $count,
        'share' => round($count / $total, 3),
      ];
      if (count($output) >= $limit) {
        break;
      }
    }

    return $output;
  }

  /**
   * Compares membership length between former borrowers and non-borrowers.
   */
  protected function buildMembershipRetentionInsights(): array {
    if (!$this->entityTypeManager->hasDefinition('profile')) {
      return [];
    }

    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $query = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->exists('field_member_end_date')
      ->condition('field_member_end_date.value', '', '<>');
    $profile_ids = $query->execute();
    if (empty($profile_ids)) {
      return [];
    }

    $profiles = $profile_storage->loadMultiple($profile_ids);
    $user_storage = $this->entityTypeManager->getStorage('user');
    $buckets = [
      'borrower' => [],
      'non_borrower' => [],
    ];

    foreach ($profiles as $profile) {
      if (!$profile->hasField('field_member_end_date') || $profile->get('field_member_end_date')->isEmpty()) {
        continue;
      }

      $uid = $profile->getOwnerId();
      if (!$uid) {
        continue;
      }

      /** @var \Drupal\user\UserInterface|null $account */
      $account = $user_storage->load($uid);
      if (!$account) {
        continue;
      }

      // Skip active members; we only care about former members.
      if ($account->hasRole('member')) {
        continue;
      }

      $end_date = $this->getDateFromField($profile->get('field_member_end_date'));
      if (!$end_date) {
        continue;
      }

      $start_date = (new \DateTimeImmutable())->setTimestamp($profile->getCreatedTime())->setTimezone($this->timezone);
      if ($end_date <= $start_date) {
        continue;
      }

      $bucket = $account->hasRole('borrower') ? 'borrower' : 'non_borrower';
      $buckets[$bucket][] = $this->diffInDays($start_date, $end_date);
    }

    return [
      'borrower' => $this->summarizeLengthBucket($buckets['borrower']),
      'non_borrower' => $this->summarizeLengthBucket($buckets['non_borrower']),
    ];
  }

  /**
   * Summarizes membership lengths for a bucket.
   */
  protected function summarizeLengthBucket(array $values): array {
    if (empty($values)) {
      return [
        'count' => 0,
        'average' => NULL,
        'median' => NULL,
      ];
    }
    $count = count($values);
    sort($values, SORT_NUMERIC);
    $average = round(array_sum($values) / $count, 1);

    return [
      'count' => $count,
      'average' => $average,
      'median' => $this->calculateMedian($values),
    ];
  }

  /**
   * Calculates the median for an array of numbers.
   */
  protected function calculateMedian(array $values): ?float {
    if (empty($values)) {
      return NULL;
    }
    sort($values, SORT_NUMERIC);
    $count = count($values);
    $middle = (int) floor($count / 2);
    if ($count % 2) {
      return round($values[$middle], 2);
    }
    return round(($values[$middle - 1] + $values[$middle]) / 2, 2);
  }

  /**
   * Converts a field value into a PHP immutable date.
   */
  protected function getDateFromField(?FieldItemListInterface $field): ?\DateTimeImmutable {
    if (!$field || $field->isEmpty()) {
      return NULL;
    }
    $item = $field->first();
    if (!$item) {
      return NULL;
    }

    if (isset($item->date) && $item->date instanceof DrupalDateTime) {
      $php_date = $item->date->getPhpDateTime();
      if ($php_date instanceof \DateTimeInterface) {
        return \DateTimeImmutable::createFromMutable($php_date)->setTimezone($this->timezone);
      }
    }

    $value = $item->value ?? NULL;
    if (!$value) {
      return NULL;
    }

    try {
      return new \DateTimeImmutable($value, $this->timezone);
    }
    catch (\Exception $e) {
      return NULL;
    }
  }

  /**
   * Calculates the difference between two dates in days (with decimals).
   */
  protected function diffInDays(\DateTimeImmutable $start, \DateTimeImmutable $end): float {
    $seconds = $end->getTimestamp() - $start->getTimestamp();
    return round($seconds / 86400, 2);
  }

  /**
   * Gets the replacement value for an item node.
   */
  protected function getReplacementValue(NodeInterface $node): float {
    if ($node->hasField(self::ITEM_REPLACEMENT_VALUE_FIELD) && !$node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->isEmpty()) {
      $raw = $node->get(self::ITEM_REPLACEMENT_VALUE_FIELD)->value;
      return is_numeric($raw) ? (float) $raw : 0.0;
    }
    return 0.0;
  }

  /**
   * Returns the DateTimeImmutable for "now".
   */
  protected function now(): \DateTimeImmutable {
    return (new \DateTimeImmutable('@' . $this->time->getRequestTime()))->setTimezone($this->timezone);
  }

  /**
   * Returns the bounds for the previous full calendar month.
   */
  protected function getLastMonthBounds(\DateTimeImmutable $now): array {
    $first_day_this_month = $now->modify('first day of this month')->setTime(0, 0, 0);
    $start = $first_day_this_month->modify('-1 month');
    $end = $first_day_this_month;
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Returns the rolling window bounds in days (end exclusive).
   */
  protected function getRollingBounds(\DateTimeImmutable $now, int $days): array {
    $end = $now->setTime(0, 0, 0)->modify('+1 day');
    $start = $end->modify("-$days days");
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Returns the window for a month-based series.
   */
  protected function getMonthSeriesWindow(\DateTimeImmutable $now, int $months): array {
    $start = $now->modify('first day of this month')->setTime(0, 0, 0)->modify('-' . ($months - 1) . ' months');
    $end = $start->modify("+$months months");
    return [
      'start' => $start,
      'end' => $end,
    ];
  }

  /**
   * Ensures the monthly chart never renders before the system launch date.
   */
  protected function enforceMonthlySeriesStart(\DateTimeImmutable $requested_start): \DateTimeImmutable {
    $min_start = new \DateTimeImmutable(self::MONTHLY_LOAN_SERIES_MIN_START, $this->timezone);
    return $requested_start < $min_start ? $min_start : $requested_start;
  }

  /**
   * Returns the number of first-of-month intervals between start and end.
   */
  protected function calculateMonthSpan(\DateTimeImmutable $start, \DateTimeImmutable $end): int {
    if ($start >= $end) {
      return 0;
    }
    $interval = $start->diff($end);
    return (int) (($interval->y * 12) + $interval->m);
  }

}
