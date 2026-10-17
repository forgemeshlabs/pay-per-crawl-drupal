<?php

namespace Drupal\pay_per_crawl;

use Drupal\Core\Database\Connection;
use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;

/**
 * Hit log in the pay_per_crawl_hits table (see pay_per_crawl_schema()).
 */
final class DrupalHitStore implements HitStoreInterface {

  private const COLUMNS = ['ts', 'ua_class', 'bot_name', 'verified', 'method', 'path', 'status', 'rail', 'amount_units', 'tx_hash', 'payer', 'reason', 'ip_hash', 'ua'];

  private const SUMS = "COUNT(*) AS hits,
    SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) AS paid,
    SUM(CASE WHEN status = 'unpaid' THEN 1 ELSE 0 END) AS unpaid,
    SUM(CASE WHEN status IN ('rejected','settle_failed') THEN 1 ELSE 0 END) AS rejected,
    SUM(CASE WHEN status = 'monitor' THEN 1 ELSE 0 END) AS monitor,
    SUM(CASE WHEN status = 'paid' THEN amount_units ELSE 0 END) AS revenue_units,
    SUM(CASE WHEN status = 'monitor' THEN amount_units ELSE 0 END) AS would_units";

  public function __construct(private Connection $db) {}

  public function record(array $row): int {
    $row = array_intersect_key($row, array_flip(self::COLUMNS));
    $row['ts'] = isset($row['ts']) ? (is_numeric($row['ts']) ? (int) $row['ts'] : (int) strtotime($row['ts'] . ' UTC')) : time();
    $row['verified'] = array_key_exists('verified', $row) && $row['verified'] !== NULL ? (int) $row['verified'] : NULL;
    foreach (['bot_name' => 64, 'path' => 255, 'ua' => 255, 'reason' => 64] as $k => $max) {
      if (isset($row[$k])) {
        $row[$k] = mb_substr((string) $row[$k], 0, $max);
      }
    }
    return (int) $this->db->insert('pay_per_crawl_hits')->fields($row)->execute();
  }

  public function dailyRollup(string $day): array {
    $from = (int) strtotime($day . ' 00:00:00 UTC');
    $rows = $this->db->query(
      "SELECT bot_name, ua_class, " . self::SUMS . ", COUNT(DISTINCT ip_hash) AS sources
       FROM {pay_per_crawl_hits} WHERE ts >= :from AND ts < :to
       GROUP BY bot_name, ua_class ORDER BY hits DESC",
      [':from' => $from, ':to' => $from + 86400]
    )->fetchAll(\PDO::FETCH_ASSOC);
    return array_slice(array_map(fn ($r) => $this->ints($r, ['bot_name', 'ua_class']), $rows), 0, 500);
  }

  public function settlementsAfter(int $afterId, int $limit): array {
    $rows = $this->db->query(
      "SELECT id, ts, bot_name, ua_class, path, rail, amount_units, tx_hash, payer
       FROM {pay_per_crawl_hits} WHERE status = 'paid' AND id > :id ORDER BY id ASC",
      [':id' => $afterId]
    )->fetchAll(\PDO::FETCH_ASSOC);
    $rows = array_slice($rows, 0, $limit);
    foreach ($rows as &$r) {
      $r['id'] = (int) $r['id'];
      $r['amount_units'] = (int) $r['amount_units'];
      $r['ts'] = gmdate('Y-m-d H:i:s', (int) $r['ts']);
    }
    return $rows;
  }

  /** Report helpers. */
  public function totals(int $days): array {
    $r = $this->db->query(
      "SELECT " . self::SUMS . ", SUM(CASE WHEN status = 'blocked' THEN 1 ELSE 0 END) AS blocked, COUNT(DISTINCT ip_hash) AS sources
       FROM {pay_per_crawl_hits} WHERE ua_class <> 'human' AND ts >= :since",
      [':since' => time() - $days * 86400]
    )->fetchAssoc();
    $humans = (int) $this->db->query("SELECT COUNT(*) FROM {pay_per_crawl_hits} WHERE ua_class = 'human' AND ts >= :since", [':since' => time() - $days * 86400])->fetchField();
    return $this->ints((array) $r, []) + ['humans' => $humans];
  }

  public function byBot(int $days, int $limit = 25): array {
    $rows = $this->db->queryRange(
      "SELECT bot_name, ua_class, " . self::SUMS . " FROM {pay_per_crawl_hits}
       WHERE ua_class <> 'human' AND ts >= :since GROUP BY bot_name, ua_class ORDER BY hits DESC",
      0, $limit, [':since' => time() - $days * 86400]
    )->fetchAll(\PDO::FETCH_ASSOC);
    return array_map(fn ($r) => $this->ints($r, ['bot_name', 'ua_class']), $rows);
  }

  public function recentSettlements(int $limit = 20): array {
    return $this->db->queryRange(
      "SELECT ts, bot_name, path, rail, amount_units, tx_hash, payer FROM {pay_per_crawl_hits} WHERE status = 'paid' ORDER BY id DESC",
      0, $limit
    )->fetchAll(\PDO::FETCH_ASSOC);
  }

  public function prune(int $days): int {
    return (int) $this->db->delete('pay_per_crawl_hits')->condition('ts', time() - max(1, $days) * 86400, '<')->execute();
  }

  /** @param array<string,mixed> $r */
  private function ints(array $r, array $keep): array {
    foreach ($r as $k => $v) {
      if (!in_array($k, $keep, TRUE)) {
        $r[$k] = (int) $v;
      }
    }
    return $r;
  }

}
