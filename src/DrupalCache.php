<?php

namespace Drupal\pay_per_crawl;

use Drupal\Core\KeyValueStore\KeyValueExpirableFactoryInterface;
use ForgeMesh\PayPerCrawl\Contracts\CacheInterface;

/**
 * Core cache contract on Drupal's expirable key/value store. setWithExpireIfNotExists() is
 * atomic, which is what the payment replay lock needs.
 */
final class DrupalCache implements CacheInterface {

  public function __construct(private KeyValueExpirableFactoryInterface $factory) {}

  private function store() {
    return $this->factory->get('pay_per_crawl');
  }

  public function get(string $key): mixed {
    return $this->store()->get($key);
  }

  public function set(string $key, mixed $value, int $ttlSeconds): void {
    $this->store()->setWithExpire($key, $value, $ttlSeconds);
  }

  public function add(string $key, mixed $value, int $ttlSeconds): bool {
    return (bool) $this->store()->setWithExpireIfNotExists($key, $value, $ttlSeconds);
  }

  public function delete(string $key): void {
    $this->store()->delete($key);
  }

}
