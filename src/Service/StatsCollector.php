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
  protected const ITEM_STATUS_RETIRED = 'retired';
  protected const ITEM_STATUS_MISSING = 'missing';
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
  protected const MONTHLY_LOAN_SERIES_MIN_START = '2021-08-01';
  protected const UNCATEGORIZED_KEY = '__uncategorized';
  protected const COLLAPSED_MEMBERSHIP_LABELS = [
    'Partnership',
    'Corporate',
    'Provided',
    'Household Additional',
    'Unspecified membership type',
    'Terminal Program',
    'Student (Deprecated)',
  ];

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
    // Attempt to get cached data.
    $cid = 'lending_library:stats_data';
    if ($cache = \Drupal::cache()->get($cid)) {
      return $cache->data;
    }

    $now = $this->now();

    $last_month_bounds = $this->getLastMonthBounds($now);
    $last_month_dataset = $this->loadLoanDataset($last_month_bounds['start'], $last_month_bounds['end']);

    $rolling_90_bounds = $this->getRollingBounds($now, 90);
    $rolling_90_dataset = $this->loadLoanDataset($rolling_90_bounds['start'], $rolling_90_bounds['end']);

    $current = $this->buildCurrentStats();

    $month_window = $this->getMonthSeriesWindow($now, 12);
    $month_window['end'] = $month_window['end']->modify('-1 month'); // End at previous full month
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

    // Full History Chart
    $history_start = new \DateTimeImmutable(self::MONTHLY_LOAN_SERIES_MIN_START, $this->timezone);
    $history_end = $month_window['end'];
    $history_months = $this->calculateMonthSpan($history_start, $history_end);
    $full_history_dataset = $this->loadLoanDataset($history_start, $history_end);
    $full_history_series = $this->buildFullHistorySeries($full_history_dataset, $history_start, $history_months);

    $category_distribution = $this->buildCategoryDistribution($rolling_90_dataset);
    $most_borrowed_tools = $this->buildMostBorrowedToolsAggregate();
    $year_start = $now->modify('-1 year');
    $most_borrowed_tools_recent = $this->buildMostBorrowedToolsAggregate(10, $year_start, $now);
    $battery_stats = $this->buildBatteryStats();
    $inventory_totals = $this->buildInventoryTotals();
    $retention_insights = $this->buildMembershipRetentionInsights();
    $retention_cohorts = $this->buildMembershipCohorts();
    $lifecycle = $this->buildLifecycleAnalysis();
    $demographics = $this->buildDemographics();

    $periods = [
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
    ];

    $retention_insights = $this->augmentRetentionInsights($retention_insights, $periods['rolling_90_days']['metrics'] ?? []);

    $stats = [
      'generated' => $now->getTimestamp(),
      'current' => $current,
      'inventory_totals' => $inventory_totals,
      'lifecycle' => $lifecycle,
      'demographics' => $demographics,
      'all_time_loans' => $lifecycle['total_system_loans'] ?? 0,
      'periods' => $periods,
      'chart_data' => [
        'monthly_loans' => $monthly_series,
        'full_history' => $full_history_series,
        'top_categories' => $category_distribution,
        'most_borrowed_tools' => $most_borrowed_tools,
        'most_borrowed_tools_recent' => $most_borrowed_tools_recent,
        'loan_value_vs_items' => $monthly_value_series,
      ],
      'batteries' => $battery_stats,
      'retention_insights' => $retention_insights,
      'retention_cohorts' => $retention_cohorts,
    ];

    // Cache the data for 1 hour.
    // Invalidate if any library item or transaction changes.
    \Drupal::cache()->set($cid, $stats, $now->getTimestamp() + 3600, ['library_transaction_list', 'node_list:library_item']);

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
   * Builds lifecycle analysis for retired and missing items.
   */
  protected function buildLifecycleAnalysis(): array {
    $node_storage = $this->entityTypeManager->getStorage('node');
    $query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->condition(self::ITEM_STATUS_FIELD, [self::ITEM_STATUS_RETIRED, self::ITEM_STATUS_MISSING], 'IN');

    $ids = $query->execute();
    if (empty($ids)) {
      return [
        'retired_count' => 0,
        'missing_count' => 0,
        'total_retired_value' => 0.0,
        'total_missing_value' => 0.0,
        'avg_loans_before_retirement' => 0,
        'avg_cost_per_loan_retired' => 0,
      ];
    }

    $nodes = $node_storage->loadMultiple($ids);

    // Get loan counts for these items
    $count_alias = 'count';
    $t_query = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE)->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->condition(self::TRANSACTION_ACTION_FIELD, self::ACTION_WITHDRAW)
      ->condition(self::TRANSACTION_ITEM_FIELD, $ids, 'IN')
      ->groupBy(self::TRANSACTION_ITEM_FIELD . '.target_id')
      ->aggregate(self::TRANSACTION_ITEM_FIELD . '.target_id', 'COUNT', NULL, $count_alias);

    $t_results = $t_query->execute();
    $loan_counts = [];
    foreach ($t_results as $res) {
      $loan_counts[$res[self::TRANSACTION_ITEM_FIELD . '_target_id']] = $res['count'];
    }

    $retired_loans = [];
    $retired_costs = [];
    $total_retired_value = 0.0;
    $total_missing_value = 0.0;
    $retired_count = 0;
    $missing_count = 0;

    foreach ($nodes as $nid => $node) {
      $status = $node->get(self::ITEM_STATUS_FIELD)->value;
      $val = $this->getReplacementValue($node);
      $loans = $loan_counts[$nid] ?? 0;

      if ($status === self::ITEM_STATUS_RETIRED) {
        $retired_count++;
        $total_retired_value += $val;
        $retired_loans[] = $loans;
        if ($loans > 0) {
          $retired_costs[] = $val / $loans;
        }
      } elseif ($status === self::ITEM_STATUS_MISSING) {
        $missing_count++;
        $total_missing_value += $val;
      }
    }

    // Calculate global amortized cost (Total Loss / All Time Loans)
    $total_loan_query = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE)->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->condition(self::TRANSACTION_ACTION_FIELD, self::ACTION_WITHDRAW)
      ->count();
    $total_system_loans = $total_loan_query->execute();

    $total_loss = $total_retired_value + $total_missing_value;
    $total_dead_items = $retired_count + $missing_count;

    return [
      'retired_count' => $retired_count,
      'missing_count' => $missing_count,
      'total_retired_value' => round($total_retired_value, 2),
      'total_missing_value' => round($total_missing_value, 2),
      'avg_loans_before_retirement' => $retired_loans ? round(array_sum($retired_loans) / count($retired_loans), 1) : 0,
      'avg_cost_per_loan_retired' => $retired_costs ? round(array_sum($retired_costs) / count($retired_costs), 2) : 0,
      'system_loss_per_loan' => $total_system_loans > 0 ? round($total_loss / $total_system_loans, 2) : 0,
      'system_loans_per_loss' => $total_dead_items > 0 ? round($total_system_loans / $total_dead_items, 1) : $total_system_loans,
      'total_system_loans' => $total_system_loans,
    ];
  }

  /**
   * Builds demographic stats for all borrowers using CiviCRM.
   */
  protected function buildDemographics(): array {
    if (!\Drupal::moduleHandler()->moduleExists('civicrm')) {
      return [];
    }
    try {
      \Drupal::service('civicrm')->initialize();
    }
    catch (\Exception $e) {
      return [];
    }

    // Aggregate query to get all unique borrowers
    $query = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE)->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->exists(self::TRANSACTION_BORROWER_FIELD)
      ->groupBy(self::TRANSACTION_BORROWER_FIELD . '.target_id');

    $results = $query->execute();

    $uids = [];
    foreach ($results as $res) {
      if (!empty($res[self::TRANSACTION_BORROWER_FIELD . '_target_id'])) {
        $uid = (int) $res[self::TRANSACTION_BORROWER_FIELD . '_target_id'];
        $uids[$uid] = $uid;
      }
    }

    if (empty($uids)) {
      return [];
    }

    $contact_ids = [];
    foreach ($uids as $uid) {
      try {
        $contact_id = \CRM_Core_BAO_UFMatch::getContactId($uid);
        if ($contact_id) {
          $contact_ids[] = $contact_id;
        }
      }
      catch (\Exception $e) {
        // Ignore
      }
    }

    if (empty($contact_ids)) {
      return [];
    }

    try {
      $result = \civicrm_api3('Contact', 'get', [
        'id' => ['IN' => $contact_ids],
        'return' => ['gender_id', 'birth_date'],
        'options' => ['limit' => 0],
      ]);
    }
    catch (\Exception $e) {
      return [];
    }

    if (empty($result['values'])) {
      return [];
    }

    $gender_counts = [];
    $age_groups = [
      '18-24' => 0, '25-34' => 0, '35-44' => 0,
      '45-54' => 0, '55-64' => 0, '65+' => 0,
    ];

    $gender_map = [];
    try {
      $gender_map = \CRM_Core_PseudoConstant::get('CRM_Contact_DAO_Contact', 'gender_id');
    }
    catch (\Exception $e) {
      // Fallback if lookup fails
    }

    foreach ($result['values'] as $contact) {
      // Gender
      $gender_id = $contact['gender_id'] ?? NULL;
      if ($gender_id) {
        $label = $gender_map[$gender_id] ?? $gender_id;
        if (!isset($gender_counts[$label])) {
          $gender_counts[$label] = 0;
        }
        $gender_counts[$label]++;
      }

      // Age
      if (!empty($contact['birth_date'])) {
        try {
          $birth = new \DateTime($contact['birth_date']);
          $now = new \DateTime();
          $age = $now->diff($birth)->y;
          
          if ($age >= 18 && $age <= 24) $grp = '18-24';
          elseif ($age <= 34) $grp = '25-34';
          elseif ($age <= 44) $grp = '35-44';
          elseif ($age <= 54) $grp = '45-54';
          elseif ($age <= 64) $grp = '55-64';
          elseif ($age >= 65) $grp = '65+';
          else $grp = 'Other';
          
          if ($grp !== 'Other') {
             $age_groups[$grp]++;
          }
        } catch (\Exception $e) {}
      }
    }

    $ethnicity = $this->buildEthnicityBreakdown(array_values($uids));

    return [
      'gender' => $gender_counts,
      'age' => array_filter($age_groups),
      'ethnicity' => $ethnicity,
    ];
  }

  /**
   * Builds an ethnicity breakdown from member profiles.
   */
  protected function buildEthnicityBreakdown(array $uids): array {
    if (empty($uids) || !$this->entityTypeManager->hasDefinition('profile')) {
      return [];
    }

    $profile_storage = $this->entityTypeManager->getStorage('profile');
    $profile_ids = $profile_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', 'main')
      ->condition('uid', $uids, 'IN')
      ->execute();
    if (empty($profile_ids)) {
      return [];
    }

    $profiles = $profile_storage->loadMultiple($profile_ids);
    $term_ids = [];
    $counts = [];
    $profiles_with_data = 0;

    foreach ($profiles as $profile) {
      if (!$profile->hasField('field_member_ethnicity') || $profile->get('field_member_ethnicity')->isEmpty()) {
        continue;
      }
      $profile_has_value = FALSE;
      foreach ($profile->get('field_member_ethnicity') as $item) {
        $tid = isset($item->target_id) ? (int) $item->target_id : 0;
        if ($tid <= 0) {
          continue;
        }
        $counts[$tid] = ($counts[$tid] ?? 0) + 1;
        $term_ids[$tid] = TRUE;
        $profile_has_value = TRUE;
      }
      if ($profile_has_value) {
        $profiles_with_data++;
      }
    }

    if (empty($counts) || $profiles_with_data === 0) {
      return [];
    }

    $labels = [];
    if (!empty($term_ids) && $this->entityTypeManager->hasDefinition('taxonomy_term')) {
      $terms = $this->entityTypeManager->getStorage('taxonomy_term')->loadMultiple(array_keys($term_ids));
      foreach ($terms as $term) {
        $labels[(int) $term->id()] = $term->label();
      }
    }

    $entries = [];
    foreach ($counts as $tid => $count) {
      $label = $labels[$tid] ?? ('Term ' . $tid);
      $entries[] = [
        'tid' => $tid,
        'label' => $label,
        'count' => $count,
        'share' => $profiles_with_data ? round(($count / $profiles_with_data) * 100, 1) : NULL,
      ];
    }
    usort($entries, static fn(array $a, array $b): int => $b['count'] <=> $a['count']);

    return [
      'total_profiles' => $profiles_with_data,
      'entries' => $entries,
    ];
  }

  /**
   * Builds the current operational snapshot.
   */
  protected function buildCurrentStats(): array {
    $node_storage = $this->entityTypeManager->getStorage('node');

    // 1. Status Counts
    $count_alias = 'count';
    $query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->groupBy(self::ITEM_STATUS_FIELD)
      ->aggregate(self::ITEM_STATUS_FIELD, 'COUNT', NULL, $count_alias);

    $results = $query->execute();
    $status_counts = [];
    foreach ($results as $res) {
      $status_counts[$res[self::ITEM_STATUS_FIELD]] = (int) $res['count'];
    }

    // 2. Total Active Value (Non-Retired)
    $sum_alias = 'sum';
    $val_query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->condition(self::ITEM_STATUS_FIELD, self::ITEM_STATUS_RETIRED, '<>')
      ->condition(self::ITEM_STATUS_FIELD, self::ITEM_STATUS_MISSING, '<>')
      ->aggregate(self::ITEM_REPLACEMENT_VALUE_FIELD, 'SUM', NULL, $sum_alias);
    $val_res = $val_query->execute();
    $total_active_value = $val_res[0]['sum'] ?? 0;

    // 3. Value on Loan (Borrowed)
    $loan_val_query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->condition(self::ITEM_STATUS_FIELD, self::ITEM_STATUS_BORROWED)
      ->aggregate(self::ITEM_REPLACEMENT_VALUE_FIELD, 'SUM', NULL, $sum_alias);
    $loan_val_res = $loan_val_query->execute();
    $value_on_loan = $loan_val_res[0]['sum'] ?? 0;

    // 4. Active Borrowers (Unique count)
    $borrower_query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->condition(self::ITEM_STATUS_FIELD, self::ITEM_STATUS_BORROWED)
      ->exists(self::ITEM_BORROWER_FIELD)
      ->groupBy(self::ITEM_BORROWER_FIELD . '.target_id');
    $active_borrowers = $borrower_query->count()->execute();

    // Calculate total active tools count
    $total_active_tools = 0;
    foreach ($status_counts as $status => $count) {
      if (!in_array($status, [self::ITEM_STATUS_RETIRED, self::ITEM_STATUS_MISSING], TRUE)) {
        $total_active_tools += $count;
      }
    }

    $overdue = $this->buildOverdueSummary();

    return [
      'active_loans' => $status_counts[self::ITEM_STATUS_BORROWED] ?? 0,
      'active_borrowers' => $active_borrowers,
      'inventory_value_on_loan' => round($value_on_loan, 2),
      'overdue_loans' => $overdue['count'],
      'borrowers_with_overdue' => $overdue['borrowers'],
      'total_active_tools' => $total_active_tools,
      'total_active_value' => round($total_active_value, 2),
      'status_counts' => $status_counts,
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
      ->condition('created', $start_date->getTimestamp(), '>=' )
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
    $node_storage = $this->entityTypeManager->getStorage('node');

    // Count
    $count_query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->count();
    $count = $count_query->execute();

    // Value Sum
    $sum_alias = 'sum';
    $value_query = $node_storage->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->aggregate(self::ITEM_REPLACEMENT_VALUE_FIELD, 'SUM', NULL, $sum_alias);
    $value_res = $value_query->execute();
    $value = $value_res[0]['sum'] ?? 0;

    return [
      'count' => $count,
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
      ->condition(self::TRANSACTION_BORROW_DATE_FIELD . '.value', $start->format('Y-m-d'), '>=' )
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

    foreach ($series as $key => & $point) {
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
      ->condition('field_member_end_date.value', '', '<>')
      ->condition('created', strtotime('2021-01-01'), '>=' ); // Filter by creation date
    $profile_ids = $query->execute();
    if (empty($profile_ids)) {
      return [];
    }

    $profiles = $profile_storage->loadMultiple($profile_ids);
    $user_storage = $this->entityTypeManager->getStorage('user');
    $buckets = [
      'borrower' => [
        'lengths' => [],
        'payments' => [],
      ],
      'non_borrower' => [
        'lengths' => [],
        'payments' => [],
      ],
    ];
    $membership_type_buckets = [];

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

      $length = $this->diffInDays($start_date, $end_date);
      $monthly_payment = $this->getMonthlyPayment($profile);
      $bucket = $account->hasRole('borrower') ? 'borrower' : 'non_borrower';
      $buckets[$bucket]['lengths'][] = $length;
      if ($monthly_payment !== NULL) {
        $buckets[$bucket]['payments'][] = $monthly_payment;
      }
      $membership_type_id = $this->resolveMembershipTypeId($profile);
      if (!isset($membership_type_buckets[$membership_type_id])) {
        $membership_type_buckets[$membership_type_id] = [
          'borrower' => [
            'lengths' => [],
            'payments' => [],
          ],
          'non_borrower' => [
            'lengths' => [],
            'payments' => [],
          ],
        ];
      }
      $membership_type_buckets[$membership_type_id][$bucket]['lengths'][] = $length;
      if ($monthly_payment !== NULL) {
        $membership_type_buckets[$membership_type_id][$bucket]['payments'][] = $monthly_payment;
      }
    }

    $summary = [
      'borrower' => $this->summarizeLengthBucket($buckets['borrower']['lengths'], $buckets['borrower']['payments']),
      'non_borrower' => $this->summarizeLengthBucket($buckets['non_borrower']['lengths'], $buckets['non_borrower']['payments']),
      'membership_types' => $this->summarizeMembershipTypeBuckets($membership_type_buckets),
    ];
    if (!empty($summary['borrower']['ltv']) && !empty($summary['non_borrower']['ltv'])) {
      $summary['ltv_difference'] = round($summary['borrower']['ltv'] - $summary['non_borrower']['ltv'], 2);
    }
    else {
      $summary['ltv_difference'] = NULL;
    }
    return $summary;
  }

  /**
   * Builds top 10 most borrowed tools using aggregate query for all-time stats.
   */
  protected function buildMostBorrowedToolsAggregate(int $limit = 10, ?\DateTimeImmutable $start = NULL, ?\DateTimeImmutable $end = NULL): array {
    $alias = 'loan_count';
    $query = $this->entityTypeManager->getStorage(self::TRANSACTION_ENTITY_TYPE)->getAggregateQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::TRANSACTION_BUNDLE)
      ->condition(self::TRANSACTION_ACTION_FIELD, self::ACTION_WITHDRAW)
      ->exists(self::TRANSACTION_ITEM_FIELD)
      ->groupBy(self::TRANSACTION_ITEM_FIELD . '.target_id')
      ->aggregate(self::TRANSACTION_ITEM_FIELD . '.target_id', 'COUNT', NULL, $alias);

    if ($start) {
      $query->condition(self::TRANSACTION_BORROW_DATE_FIELD . '.value', $start->format('Y-m-d'), '>=');
    }
    if ($end) {
      $query->condition(self::TRANSACTION_BORROW_DATE_FIELD . '.value', $end->format('Y-m-d'), '<');
    }

    $results = $query->execute();
    
    // Sort by count descending
    usort($results, function($a, $b) use ($alias) {
      return $b[$alias] <=> $a[$alias];
    });

    // Slice top N
    $results = array_slice($results, 0, $limit);
    
    $output = [];
    if (!empty($results)) {
      $item_ids = array_column($results, self::TRANSACTION_ITEM_FIELD . '_target_id');
      // Ensure no nulls or invalid IDs are passed to loadMultiple
      $item_ids = array_filter($item_ids, function($id) {
          return !empty($id) && (is_string($id) || is_int($id));
      });
      
      if (!empty($item_ids)) {
        $items = $this->entityTypeManager->getStorage('node')->loadMultiple($item_ids);

        foreach ($results as $result) {
          $item_id = $result[self::TRANSACTION_ITEM_FIELD . '_target_id'];
          // Skip if ID was filtered out
          if (empty($item_id) || !isset($items[$item_id])) {
             continue;
          }
          $count = $result[$alias];
          $label = $items[$item_id]->label();
          
          $output[] = [
            'label' => $label,
            'value' => $count,
          ];
        }
      }
    }

    return $output;
  }

  /**
   * Builds top 10 most borrowed tools for the provided dataset.
   */
  protected function buildMostBorrowedTools(array $dataset, int $limit = 10): array {
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

      if ($item_id) {
        $counts[$item_id] = ($counts[$item_id] ?? 0) + 1;
      }
    }

    arsort($counts);
    $counts = array_slice($counts, 0, $limit, TRUE);

    $output = [];
    foreach ($counts as $item_id => $count) {
      $label = isset($items[$item_id]) ? $items[$item_id]->label() : 'Unknown Item';
      $output[] = [
        'label' => $label,
        'value' => $count,
      ];
    }

    return $output;
  }

  /**
   * Builds full history series (Loans, Users, Inventory).
   */
  protected function buildFullHistorySeries(array $dataset, \DateTimeImmutable $start, int $months): array {
    if ($months <= 0) {
      return [];
    }

    $transactions = $dataset['transactions'] ?? [];
    
    $buckets = [];
    for ($i = 0; $i < $months; $i++) {
      $month_start = $start->modify("+$i months");
      $key = $month_start->format('Y-m');
      $buckets[$key] = [
        'loans' => 0,
        'borrowers' => [],
      ];
    }
    $end = $start->modify("+$months months");

    foreach ($transactions as $transaction) {
      $borrow_date = $this->getDateFromField($transaction->get(self::TRANSACTION_BORROW_DATE_FIELD));
      if (!$borrow_date || $borrow_date < $start || $borrow_date >= $end) {
        continue;
      }
      $key = $borrow_date->format('Y-m');
      if (isset($buckets[$key])) {
        $buckets[$key]['loans']++;
        $uid = $this->getBorrowerId($transaction);
        if ($uid) {
          $buckets[$key]['borrowers'][$uid] = TRUE;
        }
      }
    }

    $node_storage = $this->entityTypeManager->getStorage('node');
    $node_query = $node_storage->getQuery()
      ->accessCheck(FALSE)
      ->condition('type', self::ITEM_NODE_TYPE)
      ->sort('created', 'ASC');
    $nids = $node_query->execute();
    $nodes = $node_storage->loadMultiple($nids);
    
    $inventory_changes = [];
    $addChange = function(string $month_key, int $count_delta, float $value_delta) use (&$inventory_changes): void {
      if (!isset($inventory_changes[$month_key])) {
        $inventory_changes[$month_key] = [
          'count' => 0,
          'value' => 0.0,
        ];
      }
      $inventory_changes[$month_key]['count'] += $count_delta;
      $inventory_changes[$month_key]['value'] += $value_delta;
    };

    foreach ($nodes as $node) {
      $created = (int) $node->getCreatedTime();
      $month_key = $this->dateFormatter->format($created, 'custom', 'Y-m');
      $value = $this->getReplacementValue($node);
      $addChange($month_key, 1, $value);

      $retirement_timestamp = $this->getRetirementTimestamp($node, $node_storage);
      if ($retirement_timestamp) {
        $retire_month = $this->dateFormatter->format($retirement_timestamp, 'custom', 'Y-m');
        $addChange($retire_month, -1, -$value);
      }
    }

    $series = [];
    $cumulative_inventory = 0;
    $cumulative_value = 0.0;
    if (!empty($inventory_changes)) {
      ksort($inventory_changes);
      foreach ($inventory_changes as $m_key => $data) {
        if ($m_key < $start->format('Y-m')) {
          $cumulative_inventory += $data['count'];
          $cumulative_value += $data['value'];
        }
      }
    }

    for ($i = 0; $i < $months; $i++) {
      $month_start = $start->modify("+$i months");
      $key = $month_start->format('Y-m');
      $label = $this->dateFormatter->format($month_start->getTimestamp(), 'custom', 'M Y');

      if (isset($inventory_changes[$key])) {
        $cumulative_inventory += $inventory_changes[$key]['count'];
        $cumulative_value += $inventory_changes[$key]['value'];
      }

      $series[] = [
        'key' => $key,
        'label' => $label,
        'loans' => $buckets[$key]['loans'],
        'active_borrowers' => count($buckets[$key]['borrowers']),
        'inventory' => $cumulative_inventory,
        'inventory_value' => round($cumulative_value, 2),
      ];
    }

    return $series;
  }

  /**
   * Summarizes membership lengths for a bucket.
   */
  protected function summarizeLengthBucket(array $values, array $payments = []): array {
    if (empty($values)) {
      return [
        'count' => 0,
        'average' => NULL,
        'median' => NULL,
        'average_payment' => NULL,
        'ltv' => NULL,
      ];
    }
    $count = count($values);
    sort($values, SORT_NUMERIC);
    $average = round(array_sum($values) / $count, 1);
    $median = $this->calculateMedian($values);
    $average_payment = NULL;
    if (!empty($payments)) {
      $average_payment = round(array_sum($payments) / count($payments), 2);
    }
    $ltv = NULL;
    if ($average_payment !== NULL) {
      $ltv = round($average_payment * ($average / 30), 2);
    }

    return [
      'count' => $count,
      'average' => $average,
      'median' => $median,
      'average_payment' => $average_payment,
      'ltv' => $ltv,
    ];
  }

  /**
   * Builds summaries grouped by membership type.
   */
  protected function summarizeMembershipTypeBuckets(array $buckets): array {
    if (empty($buckets)) {
      return [];
    }

    $tids = array_filter(array_keys($buckets));
    $labels = $this->loadMembershipTypeLabels($tids);
    $output = [];
    $collapsedBucket = [
      'label' => 'Other membership types',
      'borrower' => [
        'lengths' => [],
        'payments' => [],
      ],
      'non_borrower' => [
        'lengths' => [],
        'payments' => [],
      ],
    ];
    $hasCollapsedData = FALSE;

    foreach ($buckets as $tid => $values) {
      $borrower_lengths = $values['borrower']['lengths'] ?? [];
      $borrower_payments = $values['borrower']['payments'] ?? [];
      $non_lengths = $values['non_borrower']['lengths'] ?? [];
      $non_payments = $values['non_borrower']['payments'] ?? [];
      $total_lengths = array_merge($borrower_lengths, $non_lengths);
      $total_payments = array_merge($borrower_payments, $non_payments);
      $total_summary = $this->summarizeLengthBucket($total_lengths, $total_payments);
      if (empty($total_summary['count'])) {
        continue;
      }
      $label = $labels[$tid] ?? ($tid ? 'Membership Type ' . $tid : 'Unspecified membership type');
      if (in_array($label, self::COLLAPSED_MEMBERSHIP_LABELS, TRUE)) {
        if (!empty($borrower_lengths)) {
          $collapsedBucket['borrower']['lengths'] = array_merge($collapsedBucket['borrower']['lengths'], $borrower_lengths);
        }
        if (!empty($borrower_payments)) {
          $collapsedBucket['borrower']['payments'] = array_merge($collapsedBucket['borrower']['payments'], $borrower_payments);
        }
        if (!empty($non_lengths)) {
          $collapsedBucket['non_borrower']['lengths'] = array_merge($collapsedBucket['non_borrower']['lengths'], $non_lengths);
        }
        if (!empty($non_payments)) {
          $collapsedBucket['non_borrower']['payments'] = array_merge($collapsedBucket['non_borrower']['payments'], $non_payments);
        }
        $hasCollapsedData = TRUE;
        continue;
      }
      $output[] = [
        'tid' => $tid,
        'label' => $label,
        'total' => $total_summary,
        'borrower' => $this->summarizeLengthBucket($borrower_lengths, $borrower_payments),
        'non_borrower' => $this->summarizeLengthBucket($non_lengths, $non_payments),
      ];
    }

    if ($hasCollapsedData) {
      $collapsed_total_lengths = array_merge($collapsedBucket['borrower']['lengths'], $collapsedBucket['non_borrower']['lengths']);
      $collapsed_total_payments = array_merge($collapsedBucket['borrower']['payments'], $collapsedBucket['non_borrower']['payments']);
      $collapsed_summary = $this->summarizeLengthBucket($collapsed_total_lengths, $collapsed_total_payments);
      if (!empty($collapsed_summary['count'])) {
        $output[] = [
          'tid' => 0,
          'label' => $collapsedBucket['label'],
          'total' => $collapsed_summary,
          'borrower' => $this->summarizeLengthBucket($collapsedBucket['borrower']['lengths'], $collapsedBucket['borrower']['payments']),
          'non_borrower' => $this->summarizeLengthBucket($collapsedBucket['non_borrower']['lengths'], $collapsedBucket['non_borrower']['payments']),
        ];
      }
    }

    usort($output, static fn(array $a, array $b): int => ($b['total']['count'] ?? 0) <=> ($a['total']['count'] ?? 0));
    return $output;
  }

  /**
   * Adds contextual metrics (LTV deltas, per-loan value) to retention data.
   */
  protected function augmentRetentionInsights(array $insights, array $rolling_metrics = []): array {
    $avg_loans_recent = $rolling_metrics['average_loans_per_borrower'] ?? NULL;
    $insights['average_loans_per_borrower_recent'] = ($avg_loans_recent && $avg_loans_recent > 0) ? $avg_loans_recent : NULL;

    $estimated_lifetime_loans = NULL;
    if (!empty($insights['average_loans_per_borrower_recent']) && !empty($insights['borrower']['average'])) {
      $avg_loans_per_day = $insights['average_loans_per_borrower_recent'] / 90;
      if ($avg_loans_per_day > 0) {
        $estimated_lifetime_loans = round($avg_loans_per_day * (float) $insights['borrower']['average'], 2);
      }
    }
    $insights['estimated_lifetime_loans_per_borrower'] = $estimated_lifetime_loans;

    if (!empty($insights['ltv_difference']) && $estimated_lifetime_loans && $estimated_lifetime_loans > 0) {
      $insights['ltv_value_per_loan'] = round($insights['ltv_difference'] / $estimated_lifetime_loans, 2);
    }
    else {
      $insights['ltv_value_per_loan'] = NULL;
    }

    return $insights;
  }

  /**
   * Loads taxonomy labels for membership type IDs.
   */
  protected function loadMembershipTypeLabels(array $tids): array {
    if (empty($tids) || !$this->entityTypeManager->hasDefinition('taxonomy_term')) {
      return [];
    }
    $storage = $this->entityTypeManager->getStorage('taxonomy_term');
    $terms = $storage->loadMultiple(array_unique($tids));
    $labels = [];
    foreach ($terms as $term) {
      $labels[(int) $term->id()] = $term->label();
    }
    return $labels;
  }

  /**
   * Resolves the membership type taxonomy ID from a profile.
   */
  protected function resolveMembershipTypeId(EntityInterface $profile): int {
    if ($profile->hasField('field_membership_type') && !$profile->get('field_membership_type')->isEmpty()) {
      $item = $profile->get('field_membership_type')->first();
      if ($item && isset($item->target_id)) {
        return (int) $item->target_id;
      }
    }
    return 0;
  }

  /**
   * Extracts the monthly payment amount from a profile.
   */
  protected function getMonthlyPayment(EntityInterface $profile): ?float {
    if ($profile->hasField('field_member_payment_monthly') && !$profile->get('field_member_payment_monthly')->isEmpty()) {
      $value = $profile->get('field_member_payment_monthly')->value;
      if ($value !== NULL && $value !== '') {
        return (float) $value;
      }
    }
    return NULL;
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
   * Finds the timestamp when a node was first marked retired or missing.
   */
  protected function getRetirementTimestamp(NodeInterface $node, $node_storage): ?int {
    if (!method_exists($node_storage, 'revisionIds')) {
      return NULL;
    }
    $rev_ids = $node_storage->revisionIds($node);
    if (empty($rev_ids)) {
      return NULL;
    }
    $previous_status = NULL;
    foreach ($rev_ids as $rid) {
      $revision = $node_storage->loadRevision($rid);
      if (!$revision instanceof NodeInterface) {
        continue;
      }
      $status = NULL;
      if ($revision->hasField(self::ITEM_STATUS_FIELD) && !$revision->get(self::ITEM_STATUS_FIELD)->isEmpty()) {
        $status = $revision->get(self::ITEM_STATUS_FIELD)->value;
      }
      $is_retired = in_array($status, [self::ITEM_STATUS_RETIRED, self::ITEM_STATUS_MISSING], TRUE);
      $was_retired = in_array($previous_status, [self::ITEM_STATUS_RETIRED, self::ITEM_STATUS_MISSING], TRUE);
      if ($is_retired && !$was_retired) {
        return $revision->getRevisionCreationTime();
      }
      $previous_status = $status;
    }
    return NULL;
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
