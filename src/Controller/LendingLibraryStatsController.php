<?php

namespace Drupal\lending_library\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\lending_library\Service\StatsCollectorInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;

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
      'categories' => $this->buildCategoryBreakdownChart($stats['chart_data']['top_categories'] ?? []),
    ];

    return [
      '#theme' => 'lending_library_stats',
      '#stats' => $stats + ['snapshot' => $snapshot],
      '#charts' => $charts,
      '#attached' => [
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

}
