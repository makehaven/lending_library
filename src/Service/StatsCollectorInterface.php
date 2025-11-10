<?php

namespace Drupal\lending_library\Service;

/**
 * Defines the interface for lending library statistics collectors.
 */
interface StatsCollectorInterface {

  /**
   * Builds the structured statistics array for the lending library.
   *
   * @return array
   *   A nested array containing current snapshot information, period summaries,
   *   and chart-friendly data.
   */
  public function collect(): array;

  /**
   * Flattens the most relevant metrics into a snapshot-friendly payload.
   *
   * @param array|null $stats
   *   Optional pre-built stats array from ::collect(). When omitted a fresh
   *   collection will be generated.
   *
   * @return array
   *   Keyed array of scalars that can be persisted by makerspace_snapshots or
   *   consumed by other systems.
   */
  public function buildSnapshotPayload(?array $stats = NULL): array;

}
