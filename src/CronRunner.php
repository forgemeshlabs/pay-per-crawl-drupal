<?php

namespace Drupal\pay_per_crawl;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use Psr\Log\LoggerInterface;
use ForgeMesh\PayPerCrawl\NativeHttpClient;
use ForgeMesh\PayPerCrawl\Pro;

/**
 * hook_cron work: prune old hits daily; sync to the optional Pro dashboard hourly when a
 * license key is configured. Without a key nothing leaves the site.
 */
final class CronRunner {

  public function __construct(
    private GateFactory $factory,
    private DrupalHitStore $store,
    private StateInterface $state,
    private ConfigFactoryInterface $configFactory,
    private LoggerInterface $logger,
  ) {}

  public function run(): void {
    $s = $this->factory->settings();
    $now = time();

    if ($now - (int) $this->state->get('pay_per_crawl.last_prune', 0) > 86400) {
      $n = $this->store->prune((int) ($s['retention_days'] ?? 90));
      $this->state->set('pay_per_crawl.last_prune', $now);
      if ($n) {
        $this->logger->info('Pruned @n old hit rows.', ['@n' => $n]);
      }
    }

    if (!Pro::enabled($s) || $now - (int) $this->state->get('pay_per_crawl.last_pro', 0) < 3300) {
      return;
    }
    $url = rtrim((string) ($s['site_url'] ?? ''), '/');
    $host = (string) parse_url($url, PHP_URL_HOST);
    if ($host === '') {
      $this->logger->warning('Pro sync skipped: set the canonical site URL in the Pay-per-Crawl settings.');
      return;
    }
    $this->state->set('pay_per_crawl.last_pro', $now);
    $pro = new Pro($s, new NativeHttpClient(GateFactory::UA), $host, $url . '/', 'drupal');
    $new = $pro->sync($this->store, (array) $this->state->get('pay_per_crawl.pro', []), (string) $s['site_name']);
    $this->state->set('pay_per_crawl.pro', $new);
    $this->logger->info('Pro sync: license @l, sync @s.', ['@l' => !empty($new['ok']) ? 'ok' : 'invalid', '@s' => !empty($new['sync_ok']) ? 'ok' : 'failed']);
  }

}
