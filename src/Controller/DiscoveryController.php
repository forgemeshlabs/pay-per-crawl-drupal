<?php

namespace Drupal\pay_per_crawl\Controller;

use Drupal\Core\Cache\CacheableMetadata;
use Drupal\Core\Cache\CacheableResponse;
use Drupal\Core\Controller\ControllerBase;
use Drupal\pay_per_crawl\GateFactory;
use ForgeMesh\PayPerCrawl\Discovery;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * /.well-known/x402.json, /llms.txt, /agents.txt.
 */
final class DiscoveryController extends ControllerBase {

  public function __construct(private GateFactory $factory) {}

  public static function create(ContainerInterface $container): static {
    return new static($container->get('pay_per_crawl.gate_factory'));
  }

  public function serve(Request $request, string $doc): CacheableResponse {
    $settings = $this->factory->settings();
    $settings['llms_pages'] = $this->factory->recentPages();
    $d = new Discovery($settings, $request->getSchemeAndHttpHost(), 'forgemesh-pay-per-crawl-drupal');
    $found = $d->resolve($doc);
    if ($found === NULL) {
      throw new NotFoundHttpException();
    }
    $response = new CacheableResponse($found['body'], 200, Discovery::headers($found['type']));
    $response->addCacheableDependency((new CacheableMetadata())
      ->setCacheTags(['config:pay_per_crawl.settings', 'config:system.site', 'node_list'])
      ->setCacheContexts(['url.site']));
    return $response;
  }

}
