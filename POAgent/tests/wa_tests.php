<?php
// wa_tests.php — the POAgent WhatsApp bot's tests (wa_harness.php: no real
// messages, no real data touched).   php POAgent/tests/wa_tests.php

require __DIR__ . '/wa_harness.php';

wa_test_section('Today\'s behaviour (a user with no benchmark method)');

$sent = wa_test_send('היי');
wa_check(count($sent) === 1 && ($sent[0]['interactive']['type'] ?? '') === 'cta_url', '"hi" → one login-link button',
    json_encode($sent, JSON_UNESCAPED_UNICODE));
wa_check(str_contains($sent[0]['interactive']['action']['parameters']['url'] ?? '', '/POAgent/m/?t='), 'the button opens POAgent/m/ with a token');
wa_check(($sent[0]['to'] ?? '') === WA_TEST_FROM, 'sent to the sender');
wa_check_limits($sent, '"hi"');

$sent = wa_test_send('123456');
wa_check(count($sent) === 1 && str_contains(wa_test_text($sent[0]), 'הקוד לא תקף'), 'a 6-digit number = a desktop login code (unknown → "invalid")');

$sent = wa_test_send('היי', WA_TEST_STRANGER);
wa_check($sent === [], 'a sender not on the allowlist gets nothing');

poagent_access_set_blocked(WA_TEST_FROM, true);
$sent = wa_test_send('היי');
wa_check(count($sent) === 1 && str_contains(wa_test_text($sent[0]), 'חסומה'), 'a blocked user is told so, no link');
poagent_access_set_blocked(WA_TEST_FROM, false);

$sent = wa_test_send(['type' => 'reaction', 'reaction' => ['emoji' => '👍']]);
wa_check($sent === [], 'a reaction is ignored');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Webhook signature (WaSignature)');
require_once __DIR__ . '/../../whatsapp_app/lib/WaSignature.php';
$body = '{"object":"whatsapp_business_account"}';
$sig = 'sha256=' . hash_hmac('sha256', $body, 'secret-1');
wa_check(WaSignature::check($body, $sig, ['secret-1']) === true, 'signed with the App Secret → accepted');
wa_check(WaSignature::check($body, $sig, ['other', 'secret-1']) === true, 'any of several App Secrets (numbers in two Meta apps)');
wa_check(WaSignature::check($body . ' ', $sig, ['secret-1']) === false, 'body changed after signing → rejected');
wa_check(WaSignature::check($body, 'sha256=' . hash_hmac('sha256', $body, 'wrong'), ['secret-1']) === false, 'signed with another key → rejected');
wa_check(WaSignature::check($body, null, ['secret-1']) === false, 'no signature header → rejected');
wa_check(WaSignature::check($body, null, []) === null, 'no App Secret configured → can\'t check (let through, logged)');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('CatalogIndex — search');
wa_check(CatalogIndex::normalize('  קוקה-קולה  "זירו" 1.5 ל׳ ') === 'קוקהקולה זירו 15 ל', 'normalize: dashes, quotes, dots, geresh, spaces');
wa_check(CatalogIndex::normalize('שמן זית ארץ') === 'שמנ זית ארצ', 'normalize: final letters');
$suppliers = CatalogIndex::suppliers();
wa_check(count($suppliers) > 10, 'the index has the suppliers (' . count($suppliers) . ')');
// A real supplier and item to search for.
$sup = null;
foreach ($suppliers as $s) if (CatalogIndex::itemCount($s) >= 20 && mb_strlen($s) >= 6) { $sup = $s; break; }
$word = mb_substr($sup, 0, 4);
$found = CatalogIndex::searchSuppliers($word);
wa_check(in_array($sup, $found, true), "supplier by the start of its name (\"$word\" → $sup)");
wa_check(CatalogIndex::searchSuppliers($sup)[0] === $sup, 'the full name ranks first');
wa_check(CatalogIndex::searchSuppliers('zzqqxx') === [], 'nonsense → no supplier');
$items = CatalogIndex::searchItems($sup, 'zzqqxx');
wa_check($items === [], 'nonsense → no item');
// An item whose name has two words of 3+ letters, with a real barcode.
$indexData = (new ReflectionClass('CatalogIndex'))->getMethod('data')->invoke(null);
$pick = null;
foreach ($indexData[$sup]['items'] as $it) {
    $w = array_values(array_filter(explode(' ', $it[3]), fn($x) => mb_strlen($x) >= 3 && !ctype_digit($x)));
    if (count($w) >= 2 && preg_match('/^\d{7,}$/', $it[0])) { $pick = [$it, $w]; break; }
}
[$it, $w] = $pick;
$byBarcode = CatalogIndex::searchItems($sup, $it[0]);
wa_check(($byBarcode[0]['barcode'] ?? '') === $it[0], "full barcode → that item first ({$it[0]})");
$two = CatalogIndex::searchItems($sup, "{$w[1]} {$w[0]}");
wa_check(in_array($it[0], array_column($two, 'barcode'), true), "two words in another order (\"{$w[1]} {$w[0]}\") still find it");
wa_check(CatalogIndex::item($sup, '000' . $it[0])['barcode'] === $it[0], 'item(): leading zeros ignored');
$t = microtime(true);
for ($i = 0; $i < 20; $i++) CatalogIndex::searchItems($sup, $w[0]);
$ms = (microtime(true) - $t) / 20 * 1000;
wa_check($ms < 100, sprintf('an item search takes %.1f ms (< 100)', $ms));
$t = microtime(true);
CatalogIndex::searchSuppliers('א');
wa_check((microtime(true) - $t) * 1000 < 100, 'a supplier search < 100 ms');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('One PO path for all methods (poagent_wa_commit_cart)');
$fri = new DateTimeImmutable('2026-11-13', new DateTimeZone('Asia/Jerusalem'));   // a Friday
wa_check(poagent_wa_next_business_day($fri) === '2026-11-15', 'delivery date: Friday → Sunday (no Saturday)');
wa_check(poagent_wa_next_business_day($fri->modify('-1 day')) === '2026-11-13', 'delivery date: Thursday → Friday');
$zeroQty = $indexData[$sup]['items'][0][0] === $it[0] ? $indexData[$sup]['items'][1][0] : $indexData[$sup]['items'][0][0];
$r = poagent_wa_commit_cart(WA_TEST_FROM, 'בודק', $sup, [$it[0] => 3, '0000000000000' => 2, $zeroQty => 0], null, 'a');
$po = $r['po'];
wa_check(count($po['items']) === 1 && $po['items'][0]['qty'] === 3, 'one line, quantity 3 (a quantity of 0 is left out)');
wa_check($po['items'][0]['unit_price_agorot'] === $it[2] && $po['items'][0]['name'] === $it[1], 'name and price from the price list');
wa_check($r['skipped'] === ['0000000000000'], 'a barcode not in the price list is skipped and reported');
wa_check(($po['source'] ?? '') === 'wa_a' && $po['generator_id'] === WA_TEST_FROM && $po['generator_name'] === 'בודק', 'source wa_a, generator = the phone, name = the nickname');
wa_check($po['delivery_date'] === poagent_wa_next_business_day(), 'delivery date defaults to the next business day');
wa_check(str_starts_with($po['unique_id'], 'PO9'), 'the PO went to the test folder (test counter), not the real one');
try { poagent_wa_commit_cart(WA_TEST_FROM, 'בודק', $sup, ['0000000000000' => 1], null, 'a'); $threw = false; } catch (RuntimeException $e) { $threw = true; }
wa_check($threw, 'nothing left to order → no PO');
$other = $suppliers[0] === $sup ? $suppliers[1] : $suppliers[0];
sleep(1);   // POs are ordered by their creation second
poagent_wa_commit_cart(WA_TEST_FROM, 'בודק', $other, [$indexData[$other]['items'][0][0] => 1], null, 'b');
wa_check(POStore::recentSuppliersFor(WA_TEST_FROM) === [$other, $sup], 'recent suppliers, newest first');
wa_check(POStore::lastPoFor(WA_TEST_FROM, $sup)['unique_id'] === $po['unique_id'], 'last PO to a supplier');
wa_check(POStore::lastPoFor(WA_TEST_STRANGER, $sup) === null, 'another phone has no previous PO');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Method per user (users.php) and the bot');
poagent_access_set_wa_mode(WA_TEST_FROM, 'a');
wa_check(poagent_access_wa_mode(WA_TEST_FROM) === 'a', 'method A saved for the phone');
$sent = wa_test_send('היי');
wa_check(count($sent) === 1 && str_contains(wa_test_text($sent[0]), 'שיטה A') && ($sent[0]['interactive']['type'] ?? '') !== 'cta_url',
    'method A: no login link (A not built yet → says so)', wa_test_text($sent[0] ?? []));
$sent = wa_test_send('אתר');
wa_check(($sent[0]['interactive']['type'] ?? '') === 'cta_url', '"אתר" still sends the login link');
$sent = wa_test_send('תרחיש s2');
wa_check(str_contains(wa_test_text($sent[0]), 'S2') && (wa_test_session()['data']['scenario'] ?? '') === 'S2', '"תרחיש S2" is recorded');
$sent = wa_test_send('123456');
wa_check(str_contains(wa_test_text($sent[0]), 'הקוד לא תקף'), 'a desktop code still works with no order in progress');
WaSessionStore::set(WA_TEST_FROM, 'poagent', 'a_build', ['bench' => poagent_bench_new('a')]);
wa_check(poagent_bench_takes_digits(wa_test_session()), 'while building an order, digits are a search…');
$sent = wa_test_send('כניסה 123456');
wa_check(str_contains(wa_test_text($sent[0]), 'הקוד לא תקף'), '…and "כניסה 123456" is still a desktop code');
poagent_access_set_wa_mode(WA_TEST_FROM, '');
WaSessionStore::clear(WA_TEST_FROM);
$sent = wa_test_send('היי');
wa_check(($sent[0]['interactive']['type'] ?? '') === 'cta_url', 'back to "קישור לאתר" → the login link again');
try { poagent_access_set_wa_mode(WA_TEST_FROM, 'x'); $threw = false; } catch (InvalidArgumentException $e) { $threw = true; }
wa_check($threw, 'an unknown method is refused');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Measurement log and idle sessions');
$bench = poagent_bench_new('a', 'S1');
$bench['started_at'] = time() - 95;
poagent_bench_note($bench, ['type' => 'interactive']);
poagent_bench_note($bench, ['type' => 'text']);
poagent_bench_finish(WA_TEST_FROM, $bench, 'created', 'PO90001', 4);
$log = poagent_bench_read_log();
$last = end($log);
wa_check($last['mode'] === 'a' && $last['scenario'] === 'S1' && $last['outcome'] === 'created' && $last['lines'] === 4, 'an attempt is logged');
wa_check($last['duration_s'] >= 95 && $last['taps'] === 1 && $last['typed_msgs'] === 1 && $last['inbound_msgs'] === 2, 'duration and counters');
WaSessionStore::set(WA_TEST_FROM, 'poagent', 'a_build', ['bench' => poagent_bench_new('a'), 'business_phone_number_id' => 'x',
    'last_inbound_at' => date('c', time() - 9 * 60)]);
$before = count(WaClient::$captured);
poagent_whatsapp_sweep_idle_sessions();
wa_check(count(WaClient::$captured) === $before, 'a benchmark conversation idle 9 minutes: no reminder yet (10)');
WaSessionStore::patchData(WA_TEST_FROM, ['last_inbound_at' => date('c', time() - 11 * 60)]);
poagent_whatsapp_sweep_idle_sessions();
$sent = array_slice(WaClient::$captured, $before);
wa_check(count($sent) === 1 && str_contains(wa_test_text($sent[0]), '5 דקות'), 'after 10 minutes: a reminder, "closes in 5 minutes"');
WaSessionStore::patchData(WA_TEST_FROM, ['reminder_sent_at' => date('c', time() - 6 * 60)]);
poagent_whatsapp_sweep_idle_sessions();
$log = poagent_bench_read_log();
wa_check(wa_test_session() === null && end($log)['outcome'] === 'abandoned', 'after 15: closed, logged as abandoned');

wa_test_done();
