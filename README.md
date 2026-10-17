# ForgeMesh Pay-per-Crawl for Drupal

Drupal 10 and 11 module that charges AI crawlers and scrapers per page with [x402](https://x402.org) (v1 and v2) and MPP USDC micropayments on Base. Humans and verified search engines read free; bots get an HTTP `402 Payment Required` until they pay, and the money goes straight to **your** wallet.

- Config form at `/admin/config/services/pay-per-crawl`, bot report at `/admin/reports/pay-per-crawl`
- Discovery routes: `/.well-known/x402.json`, `/llms.txt` (newest published nodes), `/agents.txt`
- Hit log table via `hook_schema`; prune and optional Pro sync on `hook_cron`
- 85-agent classifier, search engines verified by reverse DNS (impostors are charged)

## Money flow

- You keep **100%**. Payments are USDC transfers from the paying agent to the wallet you enter in the form.
- No custody, no account, no signup, and **no private key** on your site. Only a public address is stored.
- The facilitator (PayAI by default: no key, no seller fee, Base mainnet) verifies and submits the transfer. Your page is served only when it returns a transaction hash.
- ForgeMesh takes no cut. An optional Pro tier (hosted dashboard, alerts) exists; nothing leaves your site without a Pro license key.

> **Cloudflare:** AI Crawl Control (and any bot-fight or WAF rule that blocks AI crawlers) must be set to **Allow**. A crawler blocked at the edge never reaches Drupal and never sees the 402.

## Install

Not yet on drupal.org or Packagist, so add the GitHub repositories:

```bash
composer config repositories.ppc-core vcs https://github.com/forgemeshlabs/pay-per-crawl-php
composer config repositories.ppc-drupal vcs https://github.com/forgemeshlabs/pay-per-crawl-drupal
composer require forgemesh/pay-per-crawl-drupal:^0.1
drush pm:install pay_per_crawl
```

(`composer/installers` places it in `modules/contrib/pay_per_crawl`.) Requires PHP 8.1+, Drupal 10.1+ or 11.

It starts in **monitor** mode: classify and log, never charge. Open `/admin/config/services/pay-per-crawl`, enter your Base wallet, choose **Gate**, save. Grant `administer pay per crawl` only to trusted roles.

## How it gates (and why it is a middleware)

The gate is an `http_middleware` service at priority 250, **ahead of Drupal's page cache** (200). A `kernel.request` subscriber would never see a cached page: the page cache answers anonymous requests before the kernel event fires, so a bot would read every cached page for free. The integration suite warms the page cache and then checks a bot still gets 402 (and `X-Drupal-Cache: HIT` for the human warm-up proves the precondition).

The gate fails open: if it throws, the page is served and the error goes to the PHP error log.

Drupal's own plumbing (`/admin/`, login/reset paths, `/batch`, `/system/`, `/core/`, `/sites/`, `/modules/`, `/themes/`, `/libraries/`, `/update.php`, `/install.php`, `/cron/`, `/contextual/`) is never gated. Signed-in users are not exempted by cookie (a cookie is client-controlled); browsers are classified as humans and read free anyway.

## Settings

| Setting | Notes |
|---|---|
| Mode | `monitor` or `gate`. Without a valid wallet, `gate` is refused by the form and degrades to monitor at runtime. |
| Wallet, price | Base address; minimum `$0.005`. |
| Who pays | `free` / `paid` / `block` per class (search, social, monitor, seo, ai, automation, bot) plus per-crawler overrides and never-gated path prefixes (matched on a segment boundary: `/pricing` exempts `/pricing/plans`, not `/pricing-secret`). |
| Facilitator URL / auth | Empty = PayAI. |
| Client IP header | Behind a proxy, e.g. `CF-Connecting-IP` or `X-Forwarded-For`. **It must be a header your proxy overwrites or appends to.** For a multi-value header the right-most entry is used; a client-supplied left-most value is ignored. Never set it if the origin is reachable directly. Also configure `reverse_proxy` in `settings.php` if you terminate TLS upstream. |
| Canonical site URL | Optional; pins payment URLs behind a rewriting proxy. Required for the Pro sync cron. |
| Retention | Days to keep hit rows (default 90). Rows hold daily-salted IP hashes, never IPs. |

The facilitator Authorization header, if you set one, lives in config: override it in `settings.php` (`$config['pay_per_crawl.settings']['facilitator_auth']`) if you export config to git.

## Verification

`tests/integration.sh` (set `APP_DIR` to a Drupal project with drush and this module installed) runs the 42-check HTTP matrix (human 200, GPTBot 402 with v2 header + v1 body + MPP header, HEAD 402, discovery 200, paid v1/v2/MPP through a mock facilitator, replay 402, underpayment 402) plus 12 Drupal checks (page-cache bypass, hits table, admin form and report render, form validation).

Results at v0.1.0: Drupal 10.6.19 on PHP 8.1 (SQLite): 42/42 + 12/12. Drupal 11.4.10 on PHP 8.3 (MariaDB): 42/42 + 12/12. Core unit tests: 156 checks.

Real paid calls through PayAI against the Laravel edition of the same core (same wire code), $0.01 each, receipts verified on `mainnet.base.org`:

| Rail | Transaction |
|---|---|
| x402 v1 | `0xfc7d0a33ab2baeb50f91109db26d4e882e627f66c0cb68b13363a8f7b09e3b68` |
| x402 v2 | `0xff1dfa134999dc4ad19bd8a9f9ae05e286e5c9e9714bc13c4979a3c073d7b63d` |

## License

GPL-2.0-or-later (as Drupal requires). Depends on the MIT-licensed [`forgemesh/pay-per-crawl-core`](https://github.com/forgemeshlabs/pay-per-crawl-php). Built by [ForgeMesh Labs](https://forgemesh.io) - hello@forgemesh.io
