<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Drupal\Core\Url;

/**
 * Controller for the Lending Library statistics dashboard.
 */
class LendingLibraryStatsController extends ControllerBase {

  protected StatsCollectorInterface $statsCollector;

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('lending_library.stats_collector')
    );
  }

  /**
   * LendingLibraryStatsController constructor.
   */
  public function __construct(StatsCollectorInterface $stats_collector) {
    $this->statsCollector = $stats_collector;
  }

  /**
   * Displays the statistics page.
   */
  public function view(): array {
    $stats = $this->statsCollector->collect();
    $snapshot = $this->statsCollector->buildSnapshotPayload($stats);
    $charts = [
      'monthly' => $this->buildMonthlyLoansChart($stats['chart_data']['monthly_loans'] ?? []),
      'full_history' => $this->buildFullHistoryChart($stats['chart_data']['full_history'] ?? []),
      'categories' => $this->buildCategoryBreakdownChart($stats['chart_data']['top_categories'] ?? []),
      'gender' => $this->buildDemographicPieChart($stats['demographics']['gender'] ?? []),
      'age' => $this->buildDemographicBarChart($stats['demographics']['age'] ?? []),
      'inventory_history' => $this->buildInventoryHistoryChart($stats['chart_data']['full_history'] ?? []),
    ];

    return [
      '#theme' => 'lending_library_stats',
      '#stats' => $stats + ['snapshot' => $snapshot],
      '#charts' => $charts,
      '#attached' => [
        'library' => [
          'lending_library/lending_library.actions',
        ],
        'drupalSettings' => [
          'lendingLibraryStats' => [
            'snapshot' => $snapshot,
            'chartData' => $stats['chart_data'] ?? [],
          ],
        ],
      ],
      '#cache' => [
        'max-age' => 300,
      ],
    ];
  }

  /**
   * Returns the statistics as JSON for external consumers.
   */
  public function json(): JsonResponse {
    $stats = $this->statsCollector->collect();
    $snapshot = $this->statsCollector->buildSnapshotPayload($stats);

    return new JsonResponse([
      'generated' => $stats['generated'],
      'current' => $stats['current'],
      'periods' => $stats['periods'],
      'chart_data' => $stats['chart_data'],
      'snapshot' => $snapshot,
    ]);
  }

  /**
   * Redirects alias routes to the canonical stats page.
   */
  public function redirectToStats(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('lending_library.stats')->toString(), 301);
  }

  /**
   * Redirects alias preview routes to the canonical preview page.
   */
  public function redirectToPreview(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('lending_library.stats_preview')->toString(), 301);
  }

  /**
   * Redirects alias JSON routes to the canonical JSON endpoint.
   */
  public function redirectToStatsJson(): RedirectResponse {
    return new RedirectResponse(Url::fromRoute('lending_library.stats_json')->toString(), 301);
  }

  /**
   * Builds a Chart.js pie chart for demographics.
   */
  protected function buildDemographicPieChart(array $data): ?array {
    if (empty($data)) {
      return NULL;
    }
    $labels = array_keys($data);
    $values = array_values($data);

    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'pie',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 300,
      ],
      '#raw_options' => [
        'options' => [
          'plugins' => [
            'legend' => [
              'position' => 'bottom',
            ],
            'tooltip' => [
              'callbacks' => [
                'label' => $this->buildPercentageTooltipCallback(),
              ],
            ],
          ],
        ],
      ],
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Gender Identity'),
      '#data' => $values,
      '#labels' => $labels,
      '#colors' => $this->buildPalette(count($labels)),
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Builds a Chart.js bar chart for age groups.
   */
  protected function buildDemographicBarChart(array $data): ?array {
    if (empty($data)) {
      return NULL;
    }
    ksort($data);
    $labels = array_keys($data);
    $values = array_values($data);

    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'column',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 300,
      ],
      '#raw_options' => [
        'options' => [
          'plugins' => [
            'tooltip' => [
              'callbacks' => [
                'label' => $this->buildPercentageTooltipCallback(),
              ],
            ],
          ],
        ],
      ],
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Borrowers'),
      '#data' => $values,
      '#color' => '#2563eb',
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Builds a Chart.js line chart render array for monthly loans.
   */
  protected function buildMonthlyLoansChart(array $series): ?array {
    if (empty($series)) {
      return NULL;
    }

    $labels = array_map(static fn(array $point): string => (string) ($point['label'] ?? ''), $series);
    $values = array_map(static fn(array $point): float => (float) ($point['value'] ?? 0), $series);

    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 320,
      ],
      '#raw_options' => [
        'options' => [
          'plugins' => [
            'legend' => ['display' => FALSE],
          ],
          'scales' => [
            'y' => [
              'beginAtZero' => TRUE,
              'ticks' => [
                'callback' => 'function(value){ return Number(value).toLocaleString(); }',
              ],
            ],
          ],
        ],
      ],
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Loans'),
      '#data' => $values,
      '#color' => '#2563eb',
      '#options' => [
        'fill' => TRUE,
        'tension' => 0.35,
        'borderWidth' => 3,
        'backgroundColor' => 'rgba(37, 99, 235, 0.12)',
        'pointRadius' => 4,
      ],
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Builds a Chart.js line chart for full history (multi-series).
   */
  protected function buildFullHistoryChart(array $series): ?array {
    if (empty($series)) {
      return NULL;
    }

    $labels = array_column($series, 'label');
    $loans = array_column($series, 'loans');
    $borrowers = array_column($series, 'active_borrowers');
    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 400,
        'title' => $this->t('Lending Library Growth'),
      ],
      '#raw_options' => [
        'options' => [
          'interaction' => [
            'mode' => 'index',
            'intersect' => FALSE,
          ],
          'plugins' => [
            'tooltip' => [
              'mode' => 'index',
              'intersect' => FALSE,
            ],
            'legend' => [
              'position' => 'bottom',
            ],
          ],
          'scales' => [
            'y' => [
              'beginAtZero' => TRUE,
              'position' => 'left',
              'title' => ['display' => TRUE, 'text' => $this->t('Activity (Loans/Users)')],
            ],
            'y1' => [
              'beginAtZero' => TRUE,
              'position' => 'right',
              'grid' => ['drawOnChartArea' => FALSE], // Only show grid for primary axis
              'title' => ['display' => TRUE, 'text' => $this->t('Inventory Size')],
            ],
          ],
        ],
      ],
    ];

    // Loans
    $chart['loans'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Loans'),
      '#data' => $loans,
      '#color' => '#2563eb', // Blue
      '#options' => [
        'yAxisID' => 'y',
        'tension' => 0.3,
        'borderWidth' => 2,
      ],
    ];

    // Active Borrowers
    $chart['borrowers'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Active Borrowers'),
      '#data' => $borrowers,
      '#color' => '#16a34a', // Green
      '#options' => [
        'yAxisID' => 'y',
        'tension' => 0.3,
        'borderWidth' => 2,
      ],
    ];

    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Builds a chart showing inventory count and cumulative value.
   */
  protected function buildInventoryHistoryChart(array $series): ?array {
    if (empty($series)) {
      return NULL;
    }

    $labels = array_column($series, 'label');
    $counts = array_map(static fn($value): int => (int) ($value ?? 0), array_column($series, 'inventory'));
    $values = array_map(static fn($value): float => (float) ($value ?? 0), array_column($series, 'inventory_value'));

    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'line',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 320,
      ],
      // Define the primary Y-axis for Drupal Charts module.
      '#y_axis' => [
        'title' => $this->t('Inventory count'),
      ],
      // Define the secondary Y-axis for Drupal Charts module.
      '#secondary_y_axis' => [
        'title' => $this->t('Inventory value'),
      ],
      '#raw_options' => [
        'options' => [
          'interaction' => [
            'mode' => 'index',
            'intersect' => FALSE,
          ],
          'plugins' => [
            'legend' => ['position' => 'bottom'],
          ],
          'scales' => [
            'y' => [
              'type' => 'linear',
              'display' => TRUE,
              'position' => 'left',
              'beginAtZero' => TRUE,
              'title' => ['display' => TRUE, 'text' => $this->t('Inventory count')],
              'ticks' => [
                'callback' => 'function(value){ return Number(value).toLocaleString(); }',
              ],
            ],
            'y1' => [
              'type' => 'linear',
              'display' => TRUE,
              'position' => 'right',
              'beginAtZero' => TRUE,
              'grid' => ['drawOnChartArea' => FALSE], // Only show grid for primary axis
              'title' => ['display' => TRUE, 'text' => $this->t('Inventory value')],
              'ticks' => [
                'callback' => 'function(value){ return "$" + Number(value).toLocaleString(); }',
              ],
            ],
          ],
        ],
      ],
    ];

    $chart['count'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Items'),
      '#data' => $counts,
      '#color' => '#9333ea',
      '#y_axis' => 'primary', // Explicitly assign to primary y-axis.
      '#options' => [
        'yAxisID' => 'y',
        'borderWidth' => 2,
        'tension' => 0.2,
        'fill' => FALSE,
      ],
    ];

    $chart['value'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Value'),
      '#data' => $values,
      '#color' => '#f97316',
      '#y_axis' => 'secondary', // Explicitly assign to secondary y-axis.
      '#options' => [
        'yAxisID' => 'y1',
        'borderWidth' => 2,
        'tension' => 0.2,
        'fill' => FALSE,
      ],
    ];

    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Builds a Chart.js doughnut chart render array for category distribution.
   */
  protected function buildCategoryBreakdownChart(array $series): ?array {
    if (empty($series)) {
      return NULL;
    }

    $labels = array_map(static fn(array $entry): string => (string) ($entry['label'] ?? ''), $series);
    $values = array_map(static fn(array $entry): float => (float) ($entry['value'] ?? 0), $series);

    $chart = [
      '#type' => 'chart',
      '#chart_type' => 'donut',
      '#chart_library' => 'chartjs',
      '#options' => [
        'height' => 320,
      ],
      '#raw_options' => [
        'options' => [
          'plugins' => [
            'legend' => [
              'position' => 'bottom',
              'labels' => [
                'usePointStyle' => TRUE,
              ],
            ],
            'tooltip' => [
              'callbacks' => [
                'label' => $this->buildPercentageTooltipCallback(),
              ],
            ],
          ],
        ],
      ],
    ];
    $chart['series'] = [
      '#type' => 'chart_data',
      '#title' => $this->t('Loans'),
      '#data' => $values,
      '#labels' => $labels,
      '#colors' => $this->buildPalette(count($labels)),
    ];
    $chart['xaxis'] = [
      '#type' => 'chart_xaxis',
      '#labels' => $labels,
    ];

    return $chart;
  }

  /**
   * Generates a repeating color palette for charts.
   */
  protected function buildPalette(int $length): array {
    $base = [
      '#2563EB',
      '#0EA5E9',
      '#22C55E',
      '#A855F7',
      '#F97316',
      '#F43F5E',
      '#14B8A6',
      '#EAB308',
    ];
    if ($length <= count($base)) {
      return array_slice($base, 0, $length);
    }
    $palette = [];
    for ($i = 0; $i < $length; $i++) {
      $palette[] = $base[$i % count($base)];
    }
    return $palette;
  }

  /**
   * Tooltip callback that injects percentage into Chart.js popups.
   */
  protected function buildPercentageTooltipCallback(): string {
    return "function(context) {
      const dataset = context.dataset || {};
      const data = dataset.data || [];
      const total = data.reduce((sum, value) => sum + (Number(value) || 0), 0);
      const rawValue = context.parsed !== undefined ? context.parsed : context.raw;
      const value = Number(rawValue) || 0;
      const pct = total ? ((value / total) * 100).toFixed(1) : 0;
      let label = context.label || dataset.label || '';
      if (!label && context.chart && context.chart.data && Array.isArray(context.chart.data.labels)) {
        label = context.chart.data.labels[context.dataIndex] || '';
      }
      return (label ? label + ': ' : '') + value.toLocaleString() + ' (' + pct + '%)';
    }";
  }

}
