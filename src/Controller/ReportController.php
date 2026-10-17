<?php

namespace Drupal\pay_per_crawl\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\pay_per_crawl\DrupalHitStore;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Bot traffic and revenue at /admin/reports/pay-per-crawl?days=7.
 */
final class ReportController extends ControllerBase {

  public function __construct(private DrupalHitStore $store) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('pay_per_crawl.hit_store'));
  }

  private static function usd(int $units): string {
    return '$' . number_format($units / 1000000, 4);
  }

  public function report(Request $request): array {
    $days = min(365, max(1, (int) $request->query->get('days', 7)));
    $t = $this->store->totals($days);
    $mode = $this->config('pay_per_crawl.settings')->get('mode');

    $build['#cache'] = ['max-age' => 0];
    $build['summary'] = [
      '#markup' => '<p>' . $this->t('Last @d day(s), UTC. Mode: <strong>@m</strong>. Days: @links', [
        '@d' => $days,
        '@m' => $mode,
        '@links' => '1 / 7 / 30 / 90 (use ?days=N)',
      ]) . '</p>',
    ];
    $build['totals'] = [
      '#type' => 'table',
      '#caption' => $this->t('Totals'),
      '#header' => [$this->t('Bot hits'), $this->t('Sources'), $this->t('Paid'), $this->t('Revenue'), $this->t('402 unpaid'), $this->t('Rejected'), $this->t('Blocked'), $this->t('Monitor'), $this->t('Would-be revenue'), $this->t('Human views')],
      '#rows' => [[$t['hits'], $t['sources'], $t['paid'], self::usd($t['revenue_units']), $t['unpaid'], $t['rejected'], $t['blocked'], $t['monitor'], self::usd($t['would_units']), $t['humans']]],
    ];

    $rows = [];
    foreach ($this->store->byBot($days) as $r) {
      $rows[] = [$r['bot_name'] ?: $this->t('(unnamed)'), $r['ua_class'], $r['hits'], $r['paid'], $r['unpaid'], $r['rejected'], $r['monitor'], self::usd($r['revenue_units'])];
    }
    $build['bots'] = [
      '#type' => 'table',
      '#caption' => $this->t('By crawler'),
      '#header' => [$this->t('Crawler'), $this->t('Class'), $this->t('Hits'), $this->t('Paid'), $this->t('402'), $this->t('Rejected'), $this->t('Monitor'), $this->t('Revenue')],
      '#rows' => $rows,
      '#empty' => $this->t('No bot traffic recorded yet.'),
    ];

    $rows = [];
    foreach ($this->store->recentSettlements() as $r) {
      $rows[] = [gmdate('Y-m-d H:i:s', (int) $r['ts']), $r['bot_name'], $r['path'], $r['rail'], self::usd((int) $r['amount_units']), $r['tx_hash']];
    }
    $build['settlements'] = [
      '#type' => 'table',
      '#caption' => $this->t('Recent settlements'),
      '#header' => [$this->t('When (UTC)'), $this->t('Crawler'), $this->t('Path'), $this->t('Rail'), $this->t('Amount'), $this->t('Transaction')],
      '#rows' => $rows,
      '#empty' => $this->t('No payments yet.'),
    ];
    return $build;
  }

}
