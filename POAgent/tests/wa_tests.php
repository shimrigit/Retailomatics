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
wa_check(count($sent) === 1 && str_contains(wa_test_text($sent[0]), 'איזה ספק') && ($sent[0]['interactive']['type'] ?? '') !== 'cta_url',
    'method A: "hi" starts an order, no login link', wa_test_text($sent[0] ?? []));
WaSessionStore::clear(WA_TEST_FROM);
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

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Method A — chat search + order again');
poagent_access_set_wa_mode(WA_TEST_FROM, 'a');
WaSessionStore::clear(WA_TEST_FROM);
$all = [];   // every reply, for the limits check at the end
$say = function ($msg) use (&$all) { $r = wa_test_send($msg); $all = array_merge($all, $r); return $r; };
$tap = fn(string $id, string $kind = 'list_reply') => $say(wa_test_tap($id, '', $kind));
$btn = fn(string $id) => $tap($id, 'button_reply');
$state = fn() => wa_test_session()['state'] ?? null;
$cart = fn() => wa_test_session()['data']['cart'] ?? [];
$lastLog = function () { $l = poagent_bench_read_log(); return end($l); };

$long = null;
foreach ($suppliers as $s) if (mb_strlen($s) > 24) { $long = $s; break; }
if ($long) {
    $say('היי');
    $sent = $say(mb_substr($long, 0, 12));
    wa_check(str_contains(wa_test_text($sent[0]), $long) || in_array($state(), ['a_start', 'a_build'], true),
        "a supplier name longer than 24 ({$long}): the full name is in the row description");
    WaSessionStore::clear(WA_TEST_FROM);
}
$sent = $say('תרחיש S1');
$sent = $say('היי');
$rowIds = array_column($sent[0]['interactive']['action']['sections'][0]['rows'] ?? [], 'id');
wa_check(($sent[0]['interactive']['type'] ?? '') === 'list' && in_array('sup:0', $rowIds, true), '"hi" → "which supplier?" with my recent suppliers to tap');
$sent = $say('zzqqxx');
wa_check(str_contains(wa_test_text($sent[0]), 'לא נמצא ספק') && $state() === 'a_supplier', 'a supplier search with 0 results → says so, asks again');
$many = CatalogIndex::searchSuppliers('א');
$sent = $say('א');
$rows = $sent[0]['interactive']['action']['sections'][0]['rows'] ?? [];
wa_check(count($rows) === min(10, count($many)) && str_contains(wa_test_text($sent[0]), 'נמצאו ' . count($many)),
    'many results (' . count($many) . ') → a list of the first 10, "found N"');
$sent = $say($sup);   // exactly one supplier — and the test phone ordered from it before
wa_check($state() === 'a_start' && str_contains(wa_test_text($sent[0]), 'הזמן שוב'), 'one result → chosen; a previous PO → [הזמן שוב] [הזמנה חדשה]');
$btn('new');
wa_check($state() === 'a_build', '"new order" → product search');
$sent = $say($it[0]);   // full barcode → straight to quantity
wa_check($state() === 'a_qty' && str_contains(wa_test_text($sent[0]), $it[1]), 'a full barcode → "how many?" for that product');
$sent = $btn('qty:2');
wa_check(($cart()[$it[0]] ?? 0) === 2 && str_contains(wa_test_text($sent[0]), 'נוסף'), 'tap 2 → in the cart × 2');
$sent = $say($w[0]);
$results = wa_test_session()['data']['results'] ?? [];
if (count($results) >= 2) {
    $other1 = $results[0] === $it[0] ? $results[1] : $results[0];
    $n = array_search($other1, $results, true) + 1;
    $sent = $say("{$n}*3");
    wa_check(($cart()[$other1] ?? 0) === 3, "\"{$n}*3\" → result {$n}, quantity 3");
} else {
    $other1 = null;
    wa_check(false, "search \"{$w[0]}\" should give several results to test 3*2");
}
$sent = $say('עגלה');
wa_check($state() === 'a_cart' && count($sent[0]['interactive']['action']['sections'][0]['rows'] ?? []) === 2, '"עגלה" → the 2 lines to tap');
$tap("cart:{$it[0]}");
wa_check($state() === 'a_line', 'a line → [שנה כמות] [הסר] [חזרה]');
$sent = $btn('qtychg');
wa_check(str_contains(wa_test_text($sent[0]), 'כמות חדשה'), 'change quantity → "new quantity?"');
$say('7');
wa_check(($cart()[$it[0]] ?? 0) === 7, 'typed 7 → the line is now 7 (replaced, not added)');
$say('עגלה');
$tap("cart:{$other1}");
$btn('remove');
wa_check(!isset($cart()[$other1]) && count($cart()) === 1, 'remove → the line is gone');
$sent = $say('סיום');
$summary = wa_test_text($sent[0]);
wa_check($state() === 'a_confirm' && str_contains($summary, $it[1]) && str_contains($summary, '7 ×'), 'סיום → summary with the line and quantity');
wa_check(str_contains($summary, date('d/m/y', strtotime(poagent_wa_next_business_day()))), 'delivery date: next business day by default');
$future = new DateTimeImmutable('+5 days', new DateTimeZone('Asia/Jerusalem'));
$sent = $say($future->format('dmy'));
wa_check(str_contains(wa_test_text($sent[0]), $future->format('d/m/y')), 'a typed date (' . $future->format('dmy') . ') changes the delivery date — not taken as a login code');
$sent = $say('321399');
wa_check(!str_contains(wa_test_text($sent[0] ?? []), 'הקוד לא תקף') && $state() === 'a_confirm', 'an impossible date is not a login code either');
$sent = $btn('yes');
$created = POStore::lastPoFor(WA_TEST_FROM, $sup);
wa_check(str_contains(wa_test_text($sent[0]), "הזמנה {$created['unique_id']} נוצרה"), "[אשר] → \"✅ הזמנה {$created['unique_id']} נוצרה\"");
wa_check(($created['source'] ?? '') === 'wa_a' && count($created['items']) === 1 && $created['items'][0]['qty'] === 7
    && $created['delivery_date'] === $future->format('Y-m-d'), 'the PO: source wa_a, 1 line × 7, the chosen delivery date');
$l = $lastLog();
wa_check($l['outcome'] === 'created' && $l['po_id'] === $created['unique_id'] && $l['scenario'] === 'S1' && $l['edits'] === 2 && $l['lines'] === 1
    && $l['searches'] >= 5 && $l['zero_result_searches'] === 1, 'measured: created, scenario S1, 2 edits, searches counted', json_encode($l));
wa_check(!empty(wa_test_session()['data']['idle']), 'after the PO the conversation is parked');
$before = count(WaClient::$captured);
WaSessionStore::patchData(WA_TEST_FROM, ['last_inbound_at' => date('c', time() - 3600)]);
poagent_whatsapp_sweep_idle_sessions();
wa_check(count(WaClient::$captured) === $before, 'a parked conversation gets no "still there?"');

// Order again (after a PO the conversation is parked: the next message opens a new order)
$sent = $say('היי');
wa_check($state() === 'a_supplier' && str_contains(wa_test_text($sent[0]), 'איזה ספק'), 'the next message after a PO opens a new order');
$say($sup);
$sent = $btn('again');
wa_check($state() === 'a_confirm' && ($cart()[$it[0]] ?? 0) === 7, '"הזמן שוב" → the previous PO\'s lines, straight to the summary');
$btn('yes');
$again = POStore::lastPoFor(WA_TEST_FROM, $sup);
wa_check($again['unique_id'] !== $created['unique_id'] && $again['items'][0]['qty'] === 7, 'a new PO with the same lines');

// Cancel
$say('היי');
$say($sup);
$btn('new');
$say($it[0]);
$btn('qty:1');
$sent = $say('ביטול');
wa_check($state() === 'a_cancel' && str_contains(wa_test_text($sent[0]), 'לבטל'), 'ביטול with a full cart → "cancel the order?"');
$btn('cancel_no');
wa_check($state() === 'a_build' && count($cart()) === 1, '[המשך הזמנה] → back, cart kept');
$say('ביטול');
$sent = $btn('cancel_yes');
wa_check(str_contains(wa_test_text($sent[0]), 'ההזמנה בוטלה') && $state() === 'a_supplier' && $cart() === [], '[כן, לבטל] → cancelled, a new order starts');
wa_check($lastLog()['outcome'] === 'cancelled', 'measured as cancelled');
$sent = $say('ביטול');
wa_check($state() === 'a_supplier' && $lastLog()['outcome'] === 'cancelled', 'ביטול with nothing chosen → just starts over (nothing logged)');

// Odd input
$say($sup);
$btn('new');
$say($it[0]);
$sent = $say('0');
wa_check(str_contains(wa_test_text($sent[0]), 'כמות בין') && $state() === 'a_qty', 'quantity 0 when adding → "a quantity between 1 and 9999"');
$sent = $say('סיום');
wa_check($state() === 'a_build' && str_contains(wa_test_text($sent[0]), 'העגלה ריקה'), 'סיום with an empty cart → "the cart is empty"');
wa_check_limits($all, 'method A');
wa_check(count($all) > 30, 'every one of the ' . count($all) . ' method-A replies within WhatsApp\'s limits');

wa_test_done();
