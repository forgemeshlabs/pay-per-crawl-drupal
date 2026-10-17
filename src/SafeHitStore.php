<?php

namespace Drupal\pay_per_crawl;

use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;

/**
 * Logging must never break a page view: a failed insert (table missing mid-update, database
 * hiccup) is swallowed after a watchdog entry.
 */
final class SafeHitStore implements HitStoreInterface {

  public function __construct(private HitStoreInterface $inner) {}

  public function record(array $row): int {
    try {
      return $this->inner->record($row);
    }
    catch (\Throwable $e) {
      \Drupal::logger('pay_per_crawl')->error('Hit log write failed: @m', ['@m' => $e->getMessage()]);
      return 0;
    }
  }

  public function dailyRollup(string $day): array {
    return $this->inner->dailyRollup($day);
  }

  public function settlementsAfter(int $afterId, int $limit): array {
    return $this->inner->settlementsAfter($afterId, $limit);
  }

}
