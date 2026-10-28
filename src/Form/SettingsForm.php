<?php

namespace Drupal\pay_per_crawl\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;
use ForgeMesh\PayPerCrawl\Classifier;
use ForgeMesh\PayPerCrawl\Settings;

/**
 * Admin settings at /admin/config/services/pay-per-crawl.
 */
final class SettingsForm extends ConfigFormBase {

  protected function getEditableConfigNames(): array {
    return ['pay_per_crawl.settings'];
  }

  public function getFormId(): string {
    return 'pay_per_crawl_settings_form';
  }

  public function buildForm(array $form, FormStateInterface $form_state): array {
    $c = $this->config('pay_per_crawl.settings');
    $policies = ['free' => $this->t('Free'), 'paid' => $this->t('Charge'), 'block' => $this->t('Block (403)')];

    $form['intro'] = [
      '#markup' => '<p>' . $this->t('Payments settle straight to your wallet: you keep 100%, there is no account, no custody and no private key on this site. Cloudflare "AI Crawl Control" (or any WAF rule that blocks AI crawlers before they reach Drupal) must be set to Allow, otherwise crawlers never see the 402. <a href="@r">Bot report</a>.', ['@r' => '/admin/reports/pay-per-crawl']) . '</p>',
    ];

    $form['mode'] = [
      '#type' => 'radios',
      '#title' => $this->t('Mode'),
      '#options' => [
        'monitor' => $this->t('Monitor: classify and log, never charge (shows which bots you would have charged)'),
        'gate' => $this->t('Gate: answer HTTP 402 to bots until they pay'),
      ],
      '#default_value' => $c->get('mode'),
    ];
    $form['pay_to'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Wallet address (Base, receives USDC)'),
      '#default_value' => $c->get('pay_to'),
      '#maxlength' => 42,
      '#description' => $this->t('0x followed by 40 hex characters. Required for Gate mode.'),
    ];
    $form['price_usd'] = [
      '#type' => 'number',
      '#title' => $this->t('Price per page (USD)'),
      '#default_value' => $c->get('price_usd'),
      '#step' => 0.001,
      '#min' => Settings::MIN_PRICE_USD,
    ];

    $form['policy'] = ['#type' => 'details', '#title' => $this->t('Who pays'), '#open' => TRUE];
    $labels = [
      'search' => $this->t('Search engines (reverse-DNS verified; impostors are charged)'),
      'social' => $this->t('Social link previews'),
      'monitor' => $this->t('Uptime monitors, feed readers, archive.org'),
      'seo' => $this->t('SEO crawlers'),
      'ai' => $this->t('AI crawlers and agents'),
      'automation' => $this->t('Generic automation (curl, python-requests, headless browsers, empty User-Agent)'),
      'bot' => $this->t('Unknown self-declared bots'),
    ];
    foreach ($labels as $class => $label) {
      $form['policy']['class_policy_' . $class] = [
        '#type' => 'select',
        '#title' => $label,
        '#options' => $policies,
        '#default_value' => $c->get('class_policy.' . $class),
      ];
    }
    $over = '';
    foreach ((array) $c->get('bot_overrides') as $name => $p) {
      $over .= "$name: $p\n";
    }
    $form['policy']['bot_overrides'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Per-crawler overrides'),
      '#default_value' => $over,
      '#rows' => 4,
      '#description' => $this->t('One per line, "Name: free|paid|block", e.g. "GPTBot: paid". Names are from the built-in catalog (@n agents).', ['@n' => count(Classifier::shipped())]),
    ];
    $form['policy']['allow_paths'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Never-gated path prefixes'),
      '#default_value' => implode("\n", (array) $c->get('allow_paths')),
      '#rows' => 3,
      '#description' => $this->t('One per line, e.g. /feed or /api/health. /robots.txt, /llms.txt, /agents.txt, /.well-known/, /sitemap and Drupal admin/login paths are always free.'),
    ];

    $form['advanced'] = ['#type' => 'details', '#title' => $this->t('Advanced')];
    $form['advanced']['facilitator_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Facilitator URL'),
      '#default_value' => $c->get('facilitator_url'),
      '#description' => $this->t('Empty = PayAI (https://facilitator.payai.network): no key, no seller fee, Base mainnet.'),
    ];
    $form['advanced']['facilitator_auth'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Facilitator Authorization header (optional)'),
      '#default_value' => $c->get('facilitator_auth'),
      '#description' => $this->t('For a facilitator that needs a key, e.g. "Bearer ...". Stored in config: keep it out of exported config with a settings.php override if you sync config to git.'),
    ];
    $form['advanced']['trusted_ip_header'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Client IP header'),
      '#default_value' => $c->get('trusted_ip_header'),
      '#description' => $this->t('Behind a proxy, e.g. CF-Connecting-IP or X-Forwarded-For. It MUST be a header your proxy overwrites or appends to; for a multi-value header the right-most entry is used. Never set it if the origin is reachable directly. Used for search-engine verification.'),
    ];
    $form['advanced']['site_url'] = [
      '#type' => 'url',
      '#title' => $this->t('Canonical site URL'),
      '#default_value' => $c->get('site_url'),
      '#description' => $this->t('Optional origin such as https://example.com. Pins payment URLs when a proxy rewrites the host. Required for the Pro sync cron.'),
    ];
    $form['advanced']['discovery'] = ['#type' => 'checkbox', '#title' => $this->t('Serve /.well-known/x402.json, /llms.txt and /agents.txt'), '#default_value' => $c->get('discovery')];
    $form['advanced']['publish_contact'] = ['#type' => 'checkbox', '#title' => $this->t('Publish the site email in the manifest'), '#default_value' => $c->get('publish_contact')];
    $form['advanced']['log_humans'] = ['#type' => 'checkbox', '#title' => $this->t('Count human page views (no IPs stored: sources are daily-salted hashes)'), '#default_value' => $c->get('log_humans')];
    $form['advanced']['retention_days'] = ['#type' => 'number', '#title' => $this->t('Keep hit rows for (days)'), '#default_value' => $c->get('retention_days'), '#min' => 1];

    $form['pro'] = ['#type' => 'details', '#title' => $this->t('ForgeMesh Pro (optional)')];
    $form['pro']['pro_key'] = [
      '#type' => 'textfield',
      '#title' => $this->t('License key'),
      '#default_value' => $c->get('pro_key'),
      '#description' => $this->t('Hosted dashboard, alerts and the crawler-intel feed. Without a key nothing leaves this site. Syncs on cron.'),
    ];
    $form['pro']['pro_listed'] = ['#type' => 'checkbox', '#title' => $this->t('List this site in the Pro directory'), '#default_value' => $c->get('pro_listed')];

    return parent::buildForm($form, $form_state);
  }

  public function validateForm(array &$form, FormStateInterface $form_state): void {
    $wallet = trim((string) $form_state->getValue('pay_to'));
    if ($wallet !== '' && !Settings::validWallet($wallet)) {
      $form_state->setErrorByName('pay_to', $this->t('Enter a 0x wallet address with 40 hex characters.'));
    }
    if ($form_state->getValue('mode') === 'gate' && $wallet === '') {
      $form_state->setErrorByName('pay_to', $this->t('Gate mode needs a wallet address: a 402 nobody can pay is just a block.'));
    }
    if ((float) $form_state->getValue('price_usd') < Settings::MIN_PRICE_USD) {
      $form_state->setErrorByName('price_usd', $this->t('The minimum price is $@m.', ['@m' => Settings::MIN_PRICE_USD]));
    }
    foreach (preg_split('/\R/', (string) $form_state->getValue('bot_overrides')) as $line) {
      if (trim($line) !== '' && !preg_match('/^[^:]+:\s*(free|paid|block)\s*$/', $line)) {
        $form_state->setErrorByName('bot_overrides', $this->t('Bad override line: @l', ['@l' => $line]));
      }
    }
    $key = trim((string) $form_state->getValue('pro_key'));
    if ($key !== '' && !preg_match('/^fmppc_[A-Za-z0-9_\-]{20,}$/', $key)) {
      $form_state->setErrorByName('pro_key', $this->t('That does not look like a ForgeMesh Pro license key.'));
    }
  }

  public function submitForm(array &$form, FormStateInterface $form_state): void {
    $over = [];
    foreach (preg_split('/\R/', (string) $form_state->getValue('bot_overrides')) as $line) {
      if (preg_match('/^([^:]+):\s*(free|paid|block)\s*$/', trim($line), $m)) {
        $over[trim($m[1])] = $m[2];
      }
    }
    $allow = array_values(array_filter(array_map('trim', preg_split('/\R/', (string) $form_state->getValue('allow_paths')))));
    $cfg = $this->config('pay_per_crawl.settings')
      ->set('mode', $form_state->getValue('mode'))
      ->set('pay_to', trim((string) $form_state->getValue('pay_to')))
      ->set('price_usd', (float) $form_state->getValue('price_usd'))
      ->set('bot_overrides', $over)
      ->set('allow_paths', $allow);
    foreach (['search', 'social', 'monitor', 'seo', 'ai', 'automation', 'bot'] as $class) {
      $cfg->set('class_policy.' . $class, $form_state->getValue('class_policy_' . $class));
    }
    foreach (['facilitator_url', 'facilitator_auth', 'trusted_ip_header', 'site_url', 'pro_key'] as $k) {
      $cfg->set($k, trim((string) $form_state->getValue($k)));
    }
    foreach (['discovery', 'publish_contact', 'log_humans', 'pro_listed'] as $k) {
      $cfg->set($k, (bool) $form_state->getValue($k));
    }
    $cfg->set('retention_days', max(1, (int) $form_state->getValue('retention_days')))->save();
    parent::submitForm($form, $form_state);
  }

}
