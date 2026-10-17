<?php

namespace Drupal\pay_per_crawl;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\State\StateInterface;
use ForgeMesh\PayPerCrawl\Classifier;
use ForgeMesh\PayPerCrawl\Contracts\CacheInterface;
use ForgeMesh\PayPerCrawl\Contracts\HitStoreInterface;
use ForgeMesh\PayPerCrawl\Gate;
use ForgeMesh\PayPerCrawl\NativeHttpClient;
use ForgeMesh\PayPerCrawl\Pro;

/**
 * Builds the framework-free Gate from Drupal config.
 */
final class GateFactory {

  public const VERSION = '0.1.0';
  public const UA = 'forgemesh-pay-per-crawl-drupal/' . self::VERSION;

  public function __construct(
    private ConfigFactoryInterface $configFactory,
    private CacheInterface $cache,
    private HitStoreInterface $store,
    private StateInterface $state,
  ) {}

  /**
   * Config as the core's settings array.
   *
   * @return array<string,mixed>
   */
  public function settings(): array {
    $c = $this->configFactory->get('pay_per_crawl.settings')->get() ?: [];
    $site = $this->configFactory->get('system.site');
    $c['site_name'] = (string) $site->get('name');
    $c['site_description'] = (string) $site->get('slogan');
    $c['site_email'] = (string) $site->get('mail');
    // Never gate the CMS's own plumbing.
    $c['exempt_paths'] = ['/admin/', '/user/login', '/user/password', '/user/register', '/user/reset/', '/user/logout', '/batch', '/system/', '/core/', '/sites/', '/modules/', '/themes/', '/libraries/', '/profiles/', '/update.php', '/install.php', '/cron/', '/contextual/', '/media/oembed'];
    return $c;
  }

  public function create(): Gate {
    $s = $this->settings();
    $classifier = NULL;
    if (Pro::enabled($s)) {
      $agents = $this->state->get('pay_per_crawl.pro', [])['intel']['agents'] ?? [];
      if ($agents) {
        $classifier = new Classifier($this->cache, Classifier::mergeCatalog(Classifier::shipped(), $agents));
      }
    }
    return new Gate($s, $this->cache, new NativeHttpClient(self::UA), new SafeHitStore($this->store), $classifier, 'forgemesh-pay-per-crawl-drupal');
  }

  /**
   * Newest published nodes for /llms.txt, when the node module is present. Only call this from
   * a controller: it needs the router and the current user, which do not exist yet while the
   * gate runs in the middleware stack.
   */
  public function recentPages(): array {
    if (!\Drupal::moduleHandler()->moduleExists('node')) {
      return [];
    }
    try {
      $storage = \Drupal::entityTypeManager()->getStorage('node');
      $ids = $storage->getQuery()->accessCheck(TRUE)->condition('status', 1)->sort('changed', 'DESC')->range(0, 20)->execute();
      $out = [];
      foreach ($storage->loadMultiple($ids) as $node) {
        $out[] = [$node->label(), $node->toUrl('canonical', ['absolute' => TRUE])->toString()];
      }
      return $out;
    }
    catch (\Throwable) {
      return [];
    }
  }

}
