<?php

namespace Drupal\pay_per_crawl\Http;

use Drupal\pay_per_crawl\GateFactory;
use ForgeMesh\PayPerCrawl\Request as GateRequest;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\HttpKernelInterface;

/**
 * The gate, as a stack middleware ahead of the page cache.
 *
 * Fails open: if the gate itself throws, the page is served and the error is logged. A paywall
 * bug must not take the site down.
 */
final class PayPerCrawlMiddleware implements HttpKernelInterface {

  public function __construct(private HttpKernelInterface $httpKernel, private GateFactory $factory) {}

  public function handle(Request $request, int $type = self::MAIN_REQUEST, bool $catch = TRUE): Response {
    if ($type !== self::MAIN_REQUEST) {
      return $this->httpKernel->handle($request, $type, $catch);
    }
    $passHeaders = [];
    try {
      $result = $this->factory->create()->handle(new GateRequest(
        $request->getMethod(),
        $request->getRequestUri(),
        $request->getSchemeAndHttpHost() . $request->getRequestUri(),
        array_map(static fn ($v) => is_array($v) ? (string) ($v[0] ?? '') : (string) $v, $request->headers->all()),
        (string) $request->getClientIp()
      ));
      if ($result->respond) {
        // Symfony sends the headers first and the status line last, so the 402 survives the
        // SAPI rewriting the status to 401 when WWW-Authenticate is present.
        return new Response($result->body, $result->status, $result->headers);
      }
      $passHeaders = $result->passHeaders;
    }
    catch (\Throwable $e) {
      error_log('pay_per_crawl gate failed open: ' . $e->getMessage());
    }
    $response = $this->httpKernel->handle($request, $type, $catch);
    foreach ($passHeaders as $name => $value) {
      $response->headers->set($name, $value);
    }
    return $response;
  }

}
