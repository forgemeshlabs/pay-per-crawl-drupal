#!/usr/bin/env bash
# Provisions a throwaway Drupal (already composer-installed with this module and drush, SQLite or
# PostgreSQL, site installed) in gate mode against a mock facilitator, then runs the HTTP matrix.
#   APP_DIR   Drupal project root (default ~/dev/ppc-testbed/drupal10)
#   PHP_BIN_DIR  directory whose `php` should run drush and the server (default: current PATH)
set -u
HERE=$(cd "$(dirname "$0")" && pwd)
APP_DIR=${APP_DIR:-$HOME/dev/ppc-testbed/drupal10}
[ -n "${PHP_BIN_DIR:-}" ] && export PATH="$PHP_BIN_DIR:$PATH"
APP_PORT=8422; MOCK_PORT=8423
export MOCK_LOG=$(mktemp)
cleanup(){ kill $APP_PID $MOCK_PID 2>/dev/null; fuser -k -n tcp $APP_PORT $MOCK_PORT >/dev/null 2>&1; rm -f "$MOCK_LOG"; }
trap cleanup EXIT
fuser -k -n tcp $APP_PORT $MOCK_PORT >/dev/null 2>&1; sleep 0.3
cd "$APP_DIR" || exit 2
D="vendor/bin/drush -y"
$D pm:install page_cache node pay_per_crawl >/dev/null 2>&1
$D config:set system.performance cache.page.max_age 3600 >/dev/null
$D config:set pay_per_crawl.settings mode gate >/dev/null
$D config:set pay_per_crawl.settings pay_to 0x13C3C28c64cc9d60eEb8a675F3fe27eBC6a4Cd09 >/dev/null
$D config:set pay_per_crawl.settings price_usd 0.01 >/dev/null
$D config:set pay_per_crawl.settings facilitator_url http://127.0.0.1:$MOCK_PORT >/dev/null
$D role:perm:add anonymous 'access content' >/dev/null
$D sql:query "DELETE FROM pay_per_crawl_hits" >/dev/null
$D php:eval '
if (!\Drupal\node\Entity\NodeType::load("page")) { $t = \Drupal\node\Entity\NodeType::create(["type" => "page", "name" => "Page"]); $t->save(); node_add_body_field($t); \Drupal::service("entity_display.repository")->getViewDisplay("node", "page", "default")->setComponent("body", ["type" => "text_default", "label" => "hidden"])->save(); }
if (!\Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["title" => "Paid test post ZZPAIDZZ"])) {
  \Drupal\node\Entity\Node::create(["type" => "page", "title" => "Paid test post ZZPAIDZZ", "status" => 1, "body" => ["value" => "Paid content body ZZPAIDZZ", "format" => "plain_text"]])->save();
}'
NID=$($D php:eval 'echo array_key_first(\Drupal::entityTypeManager()->getStorage("node")->loadByProperties(["title" => "Paid test post ZZPAIDZZ"]));' | tr -dc 0-9)
echo "nid=$NID" >&2
$D cr >/dev/null

MODE=${MOCK_MODE:-ok} php -S 127.0.0.1:$MOCK_PORT "$HERE/integration/mock-facilitator.php" >/dev/null 2>&1 & MOCK_PID=$!
(cd web && PHP_CLI_SERVER_WORKERS=4 exec php -S 127.0.0.1:$APP_PORT .ht.router.php >/tmp/ppc-drupal-server.log 2>&1) & APP_PID=$!
sleep 1.5
# Warm Drupal's page cache with a human visit so the bot checks below run against a cached page.
curl -s -o /dev/null -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/129.0 Safari/537.36' -H 'Accept: text/html' http://127.0.0.1:$APP_PORT/node/$NID
BASE_URL=http://127.0.0.1:$APP_PORT PAGE=/node/$NID CORE_SRC="$APP_DIR/vendor/forgemesh/pay-per-crawl-core/src" "$HERE/integration/matrix.sh"
RC=$?

pass=0; fail=0
ok(){ if [ "$1" = "$2" ]; then pass=$((pass+1)); else fail=$((fail+1)); echo "FAIL: $3 (got '$1' want '$2')"; fi; }
UA_G='Mozilla/5.0 AppleWebKit/537.36 (KHTML, like Gecko; compatible; GPTBot/1.2; +https://openai.com/gptbot)'
ok "$(curl -s -D - -o /dev/null -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36' -H 'Accept: text/html' http://127.0.0.1:$APP_PORT/node/$NID | grep -ci '^x-drupal-cache: hit')" 1 "precondition: the page really is in Drupal's page cache"
ok "$(curl -s -o /dev/null -I -w '%{http_code}' -A "$UA_G" http://127.0.0.1:$APP_PORT/node/$NID)" 402 "cached page is still gated for a bot (page-cache bypass check)"
ok "$(curl -s -o /dev/null -w '%{http_code}' -A 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 Chrome/129.0 Safari/537.36' -H 'Accept: text/html' http://127.0.0.1:$APP_PORT/node/$NID)" 200 "cached page still served to humans"
ok "$($D sql:query "SELECT COUNT(*) FROM pay_per_crawl_hits WHERE status='paid'")" 3 "three paid rows (v2, v1, MPP)"
ok "$($D sql:query "SELECT SUM(amount_units) FROM pay_per_crawl_hits WHERE status='paid'")" 30000 "revenue 30000 units"
ok "$($D sql:query "SELECT COUNT(*) FROM pay_per_crawl_hits WHERE ip_hash=''")" 0 "every hit carries a hashed source"
# Admin pages render for a logged-in admin.
LOGIN=$($D uli --uri=http://127.0.0.1:$APP_PORT 2>/dev/null | tail -1)
CJ=$(mktemp); curl -s -L -c $CJ -b $CJ -o /dev/null "$LOGIN"
ok "$(curl -s -b $CJ -o "$MOCK_LOG.s" -w '%{http_code}' http://127.0.0.1:$APP_PORT/admin/config/services/pay-per-crawl)" 200 "settings form renders"
grep -q 'Wallet address' "$MOCK_LOG.s"; ok $? 0 "settings form shows wallet field"
ok "$(curl -s -b $CJ -o "$MOCK_LOG.r" -w '%{http_code}' http://127.0.0.1:$APP_PORT/admin/reports/pay-per-crawl)" 200 "report renders"
grep -q 'GPTBot' "$MOCK_LOG.r"; ok $? 0 "report lists GPTBot"
grep -q '0xabab' "$MOCK_LOG.r"; ok $? 0 "report lists the settlement tx"
# Settings form submit round trip (validation + save).
TOK=$(curl -s -b $CJ http://127.0.0.1:$APP_PORT/admin/config/services/pay-per-crawl | grep -o 'name="form_token" value="[^"]*"' | sed 's/.*value="//;s/"//')
BID=$(curl -s -b $CJ http://127.0.0.1:$APP_PORT/admin/config/services/pay-per-crawl | grep -o 'name="form_build_id" value="[^"]*"' | head -1 | sed 's/.*value="//;s/"//')
SUB=$(curl -s -b $CJ -o /dev/null -w '%{http_code}' --data-urlencode "form_token=$TOK" --data-urlencode "form_build_id=$BID" --data-urlencode "form_id=pay_per_crawl_settings_form" --data-urlencode "mode=gate" --data-urlencode "pay_to=not-a-wallet" --data-urlencode "price_usd=0.02" --data-urlencode "op=Save configuration" http://127.0.0.1:$APP_PORT/admin/config/services/pay-per-crawl)
ok "$($D config:get pay_per_crawl.settings pay_to | tr -d "'" | sed 's/.*: //')" "0x13C3C28c64cc9d60eEb8a675F3fe27eBC6a4Cd09" "invalid wallet rejected by form validation (config unchanged)"
rm -f "$CJ" "$MOCK_LOG.s" "$MOCK_LOG.r"
echo "drupal pass=$pass fail=$fail"
exit $((RC + fail))
