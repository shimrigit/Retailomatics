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
// The end of a barcode: at least its last 6 digits.
wa_check((CatalogIndex::searchItems($sup, substr($it[0], -6))[0]['barcode'] ?? '') === $it[0], 'the last 6 digits (' . substr($it[0], -6) . ') → that item first');
wa_check((CatalogIndex::searchItems($sup, substr($it[0], -9))[0]['barcode'] ?? '') === $it[0], 'the last 9 digits → that item too');
wa_check(!in_array($it[0], array_column(CatalogIndex::searchItems($sup, substr($it[0], -5)), 'barcode'), true), '5 digits are not a barcode end (too short)');
wa_check(!in_array($it[0], array_column(CatalogIndex::searchItems($sup, substr($it[0], 2, 7)), 'barcode'), true), 'digits from the middle of a barcode don\'t match it');
$pair = null;
foreach ($indexData as $sName => $sData) {
    $byTail = [];
    foreach ($sData['items'] as $x) if (strlen($x[0]) > 6) $byTail[substr($x[0], -6)][] = $x[0];
    foreach ($byTail as $t => $l) if (count($l) > 1) { $pair = [$sName, $t, $l]; break 2; }
}
if ($pair) {
    $got = array_slice(array_column(CatalogIndex::searchItems($pair[0], $pair[1]), 'barcode'), 0, count($pair[2]));
    wa_check(count(array_intersect($got, $pair[2])) === count($pair[2]), "two items ending in {$pair[1]} → both, first, to choose from");
}
$short = $shortSup = null;   // an item with a code of 4–6 digits, anywhere
foreach ($indexData as $sName => $sData) {
    foreach ($sData['items'] as $x) if (preg_match('/^\d{4,6}$/', $x[0])) { [$short, $shortSup] = [$x[0], $sName]; break 2; }
}
wa_check($short && (CatalogIndex::searchItems($shortSup, $short)[0]['barcode'] ?? '') === $short, "a short code ({$short}) is matched whole");
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
$menuIds = array_column(array_column($sent[0]['interactive']['action']['buttons'] ?? [], 'reply'), 'id');
wa_check(count($sent) === 1 && $menuIds === ['menu_po', 'menu_dn'], 'method A: "hi" → "what would you like?" [הזמנה חדשה] [תעודת משלוח], no login link',
    wa_test_text($sent[0] ?? []));
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
    $btn('menu_po');
    $sent = $say(mb_substr($long, 0, 12));
    wa_check(str_contains(wa_test_text($sent[0]), $long) || in_array($state(), ['a_start', 'a_build'], true),
        "a supplier name longer than 24 ({$long}): the full name is in the row description");
    WaSessionStore::clear(WA_TEST_FROM);
}
$sent = $say('תרחיש S1');
$say('היי');
$sent = $btn('menu_po');
$rowIds = array_column($sent[0]['interactive']['action']['sections'][0]['rows'] ?? [], 'id');
wa_check(($sent[0]['interactive']['type'] ?? '') === 'list' && in_array('sup:0', $rowIds, true), '[הזמנה חדשה] → "which supplier?" with my recent suppliers to tap');
$sent = $say('zzqqxx');
wa_check(str_contains(wa_test_text($sent[0]), 'לא נמצא ספק') && $state() === 'a_supplier', 'a supplier search with 0 results → says so, asks again');
$many = CatalogIndex::searchSuppliers('א');
$sent = $say('א');
$rows = $sent[0]['interactive']['action']['sections'][0]['rows'] ?? [];
wa_check(count($rows) === min(10, count($many)) && str_contains(wa_test_text($sent[0]), 'נמצאו ' . count($many)),
    'many results (' . count($many) . ') → a list of the first 10, "found N"');
$sent = $say($sup);   // exactly one supplier — and the test phone ordered from it before
wa_check($state() === 'a_start' && str_contains(wa_test_text($sent[0]), 'הזמן שוב'), 'one result → chosen; a previous PO → [הזמן שוב] [הזמנה חדשה]');
$sent = $btn('new');
wa_check($state() === 'a_build' && ($sent[0]['type'] ?? '') === 'text', '"new order" → product search (empty cart: no finish button yet)');
$sent = $say($it[0]);   // full barcode → straight to quantity
wa_check($state() === 'a_qty' && str_contains(wa_test_text($sent[0]), $it[1]), 'a full barcode → "how many?" for that product');
wa_check(($sent[0]['type'] ?? '') === 'text' && str_contains(wa_test_text($sent[0]), 'כתבו מספר'), 'the quantity is typed — no 1/2/5 buttons');
$sent = $say('2');
wa_check(($cart()[$it[0]] ?? 0) === 2 && str_contains(wa_test_text($sent[0]), 'נוסף'), 'typed 2 → in the cart × 2');
$ids = array_column(array_column($sent[0]['interactive']['action']['buttons'] ?? [], 'reply'), 'id');
wa_check($ids === ['done', 'cart'], 'after adding: [סיום והזמנה] [הצג עגלה] buttons');
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
$sent = $btn('cart');
$rowIds = array_column($sent[0]['interactive']['action']['sections'][0]['rows'] ?? [], 'id');
$doneIds = array_column(array_column($sent[1]['interactive']['action']['buttons'] ?? [], 'reply'), 'id');
wa_check($state() === 'a_cart' && count($rowIds) === 2 && !in_array('done', $rowIds, true), '[הצג עגלה] → the 2 lines to tap');
wa_check(count($sent) === 2 && $doneIds === ['done'], '…and right after it, a message with the [סיום והזמנה] button');
$tap("cart:{$it[0]}");
wa_check($state() === 'a_line', 'a line → [שנה כמות] [הסר] [חזרה]');
$sent = $btn('qtychg');
wa_check(str_contains(wa_test_text($sent[0]), 'הכמות החדשה') && ($sent[0]['type'] ?? '') === 'text', 'change quantity → "type the new quantity" (typed, no buttons)');
$say('7');
wa_check(($cart()[$it[0]] ?? 0) === 7, 'typed 7 → the line is now 7 (replaced, not added)');
$say('עגלה');
$tap("cart:{$other1}");
$btn('remove');
wa_check(!isset($cart()[$other1]) && count($cart()) === 1, 'remove → the line is gone');
$say('עגלה');
$sent = $btn('done');
$summary = wa_test_text($sent[0]);
wa_check($state() === 'a_confirm' && str_contains($summary, $it[1]) && str_contains($summary, '7 ×'), 'the cart\'s [סיום והזמנה] → summary with the line and quantity');
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

// Order again (after a PO the conversation is parked: the next message gets the first question)
$sent = $say('היי');
wa_check($state() === 'a_menu' && str_contains(wa_test_text($sent[0]), 'מה תרצו לעשות'), 'the next message after a PO → the first question again');
$btn('menu_po');
$say($sup);
$sent = $btn('again');
wa_check($state() === 'a_confirm' && ($cart()[$it[0]] ?? 0) === 7, '"הזמן שוב" → the previous PO\'s lines, straight to the summary');
$btn('yes');
$again = POStore::lastPoFor(WA_TEST_FROM, $sup);
wa_check($again['unique_id'] !== $created['unique_id'] && $again['items'][0]['qty'] === 7, 'a new PO with the same lines');

// Cancel
$say('היי');
$btn('menu_po');
$say($sup);
$btn('new');
$say($it[0]);
$say('1');
$sent = $say('ביטול');
wa_check($state() === 'a_cancel' && str_contains(wa_test_text($sent[0]), 'לבטל'), 'ביטול with a full cart → "cancel the order?"');
$btn('cancel_no');
wa_check($state() === 'a_build' && count($cart()) === 1, '[המשך הזמנה] → back, cart kept');
$say('ביטול');
$sent = $btn('cancel_yes');
wa_check(str_contains(wa_test_text($sent[0]), 'ההזמנה בוטלה') && $state() === 'a_done' && !empty(wa_test_session()['data']['idle']), '[כן, לבטל] → cancelled, the conversation is back at the start');
wa_check($lastLog()['outcome'] === 'cancelled', 'measured as cancelled');
$sent = $say('היי');
wa_check($state() === 'a_menu' && str_contains(wa_test_text($sent[0]), 'תעודת משלוח'), 'the next message after a cancel → the first question: an order or a delivery note');
$btn('menu_po');
$sent = $say('ביטול');
wa_check($state() === 'a_cancel' && str_contains(wa_test_text($sent[0]), 'לבטל את ההזמנה?'), 'ביטול with nothing chosen yet → still asks "cancel the order?"');
$btn('cancel_no');
wa_check($state() === 'a_supplier', '[המשך הזמנה] → back to "which supplier?"');

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

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Delivery note from WhatsApp (method A phones)');
WaSessionStore::clear(WA_TEST_FROM);
$all = [];
$img = function (string $mediaId, string $mime = 'image/jpeg', string $kind = 'image') use ($say) {
    WaClient::$fakeMedia[$mediaId] = ['bytes' => "fake-{$mediaId}", 'mime' => $mime];
    return $say(['type' => $kind, $kind => ['id' => $mediaId, 'mime_type' => $mime]]);
};
// An open PO of another user, to see "own first".
$othersPo = POStore::createPO('972500000999', $sup, [['barcode' => $it[0], 'name' => $it[1], 'qty' => 1, 'unit_price_agorot' => $it[2]]], 'אחר');
$say('היי');
$sent = $btn('menu_dn');
$rows = $sent[0]['interactive']['action']['sections'][0]['rows'] ?? [];
$ownPos = array_filter(POStore::listPOs(WA_TEST_FROM), fn($p) => in_array($p['status'], ['open', 'prcv'], true));
wa_check($state() === 'd_po' && count($rows) >= 2 && str_contains($rows[0]['description'], 'שלך')
    && str_contains(end($rows)['title'] . ' ' . wa_test_text($sent[0]), $othersPo['unique_id']),
    '[תעודת משלוח] → "which PO?": open POs, mine first, then others\'');
$target = POStore::loadByCoreName(wa_test_session()['data']['dn_map'][0]);
$sent = $tap('po:0');
wa_check($state() === 'd_pages' && str_contains(wa_test_text($sent[0]), 'צלמו'), "a PO chosen ({$target['unique_id']}) → \"photograph the delivery note\"");
$sent = $img('m1');
wa_check(str_contains(wa_test_text($sent[0]), 'עמוד 1 התקבל') && array_column(array_column($sent[0]['interactive']['action']['buttons'], 'reply'), 'id') === ['dn_done', 'dn_cancel'],
    'a photo → "page 1 received" [✅ סיום] [ביטול]');
$sent = $img('m2', 'image/png', 'document');
wa_check(str_contains(wa_test_text($sent[0]), 'עמוד 2 התקבל'), 'a second page (sent as a PNG document) → "page 2 received"');
$sent = $img('m3', 'application/pdf', 'document');
wa_check(str_contains(wa_test_text($sent[0]), 'PDF') && count(wa_test_session()['data']['dn_pages']) === 2, 'a PDF → "send it as a photo", not counted');
wa_check(DNStore::findPendingImage($target['core_name']) === null, 'nothing reaches the PO before סיום');
$sent = $btn('dn_done');
$pending = DNStore::findPendingImage($target['core_name']);
wa_check(str_contains(wa_test_text($sent[0]), 'נשמרה') && str_contains(wa_test_text($sent[0]), '2 עמודים'), '[סיום] → "saved — 2 pages"');
wa_check($pending && DNStore::pageFilenames($pending['image_filename']) === [$pending['image_filename'], $pending['dn_core_name'] . '_p2.png']
    && file_get_contents(DNStore::pagePaths($pending['image_path'])[1]) === 'fake-m2', 'one pending DN: page 1 + its _p2, in order (page 2 not a DN of its own)');
wa_check(POStore::loadByCoreName($target['core_name'])['status'] === 'preocr', 'the PO now waits for the office\'s OCR (preocr)');
wa_check(glob(POAGENT_DNDIR . '/_incoming/*') === [], 'the waiting photos are cleared');
require_once __DIR__ . '/../lib/ui_common.php';
ob_start();
poagent_render_dn_pages($pending['image_filename'], 'x');
$html = ob_get_clean();
wa_check(substr_count($html, 'poagent-zoom-panel"') === 2 && str_contains($html, 'עמוד 2 מתוך 2'), 'the site shows both pages ("עמוד 2 מתוך 2")');

// A photo first, then the PO
$sent = $img('m4');
wa_check($state() === 'd_po' && str_contains(wa_test_text($sent[0]), 'קיבלתי את התמונה'), 'a photo with nothing in progress → kept, "which PO?"');
$dnMap = wa_test_session()['data']['dn_map'];
wa_check(!in_array($target['core_name'], $dnMap, true), 'a PO already waiting for OCR is not offered');
$sent = $say((string) (int) substr($othersPo['unique_id'], 2));
wa_check(count(wa_test_session()['data']['dn_map']) === 1, 'typing a PO number narrows the list to it');
$sent = $tap('po:0');
wa_check($state() === 'd_pages' && str_contains(wa_test_text($sent[0]), '1 עמוד'), 'the PO chosen → the photo already counts as page 1');
$sent = $btn('dn_cancel');
wa_check(str_contains(wa_test_text($sent[0]), 'בוטלה') && glob(POAGENT_DNDIR . '/_incoming/*') === [] && DNStore::findPendingImage($othersPo['core_name']) === null,
    '[ביטול] → nothing saved, the photo deleted');

// While ordering, a photo is not a delivery note
$say('היי');
$btn('menu_po');
$sent = $img('m5');
wa_check(str_contains(wa_test_text($sent[0]), 'באמצע הזמנה') && $state() === 'a_supplier', 'a photo in the middle of an order → "finish or cancel the order first"');
$say('ביטול');
// Users on the login link: a photo is ignored, as before
poagent_access_set_wa_mode(WA_TEST_FROM, '');
WaSessionStore::clear(WA_TEST_FROM);
$sent = $img('m6');
wa_check($sent === [], 'a phone on "קישור לאתר": a photo is ignored, as before');
wa_check_limits($all, 'delivery notes');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Method B — WhatsApp Flow: encryption and the endpoint');
require_once __DIR__ . '/../../whatsapp_app/flow_endpoint.php';   // wa_flow_handle(); the HTTP part runs only as its own script
[$priv, $pub] = FlowCrypto::keys();
$enc = FlowCrypto::encryptRequest(['version' => '3.0', 'action' => 'ping'], $pub);
$dec = FlowCrypto::decryptRequest($enc['body'], $priv);
$resp = FlowCrypto::encryptResponse(wa_flow_handle($dec['data']), $dec['key'], $dec['iv']);
wa_check(FlowCrypto::decryptResponse($resp, $enc['key'], $enc['iv']) === ['data' => ['status' => 'active']],
    'health check: a request encrypted like Meta\'s → decrypted → "active", encrypted back (IV flipped) and readable');
$bad = $enc['body'];
$bad['encrypted_aes_key'] = base64_encode(random_bytes(256));
try { FlowCrypto::decryptRequest($bad, $priv); $ok = false; } catch (RuntimeException $e) { $ok = true; }
wa_check($ok, 'a request encrypted for another key → refused (the endpoint answers 421)');
wa_check(wa_flow_handle(['action' => 'data_exchange', 'data' => ['error' => 'x', 'error_message' => 'y']]) === ['data' => ['acknowledged' => true]],
    'an error notification from the phone → acknowledged');

poagent_access_set_wa_mode(WA_TEST_FROM, 'b');
WaSessionStore::clear(WA_TEST_FROM);
$all = [];
$sent = $say('היי');
$p = $sent[0]['interactive']['action']['parameters'] ?? [];
wa_check(($sent[0]['interactive']['type'] ?? '') === 'flow' && $p['flow_id'] === 'TEST_FLOW_ID' && $p['flow_action'] === 'data_exchange'
    && $p['flow_message_version'] === '3' && $p['mode'] === 'draft', '"hi" → a Flow message (button "פתח הזמנה", opens through the endpoint)');
$token = $p['flow_token'];
$tk = poagent_bench_b_verify($token);
wa_check($tk && $tk['p'] === WA_TEST_FROM && $tk['r'] === wa_test_session()['data']['b_ref'], 'its flow_token: this phone + this order\'s ref, signed');
wa_check(poagent_bench_b_verify(substr($token, 0, -2) . 'xx') === null, 'a changed token → refused');
$ep = fn(string $action, ?string $screen = null, array $d = [], ?string $t = null) =>
    poagent_bench_b_endpoint(['version' => '3.0', 'action' => $action, 'screen' => $screen, 'data' => $d, 'flow_token' => $t ?? $token]);

$r = $ep('INIT');
$opts = $r['data']['suppliers'];
wa_check($r['screen'] === 'SUPPLIER' && count($opts) === count($suppliers) && count($opts) <= 200, 'INIT → SUPPLIER with all ' . count($opts) . ' suppliers (Meta allows 200)');
wa_check(($opts[0]['description'] ?? '') !== '' && str_contains($opts[0]['description'], 'לאחרונה'), 'recent suppliers first, marked "הזמנתם לאחרונה"');
wa_check(max(array_map(fn($o) => mb_strlen($o['title']), $opts)) <= 30, 'every supplier title ≤ 30 characters (Meta\'s limit)');
$r = $ep('data_exchange', 'SUPPLIER', ['supplier' => '', 'from_last' => false]);
wa_check($r['screen'] === 'SUPPLIER' && ($r['data']['error_message'] ?? '') !== '', 'no supplier chosen → stays, with an error');
$supIdx = (string) array_search($sup, wa_test_session()['data']['sup_map'], true);
$r = $ep('data_exchange', 'SUPPLIER', ['supplier' => $supIdx, 'from_last' => true]);
wa_check($r['screen'] === 'PRODUCTS' && str_contains($r['data']['cart_summary'], 'נטענה ההזמנה הקודמת') && ($cart()[$it[0]] ?? 0) === 7,
    '"start from the previous order" → PRODUCTS with its lines in the cart');
wa_check($r['data']['has_results'] && $r['data']['results'][0]['id'] === $it[0], 'the dropdown shows the cart\'s lines, to change or remove');
$r = $ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => $w[0], 'item' => '', 'qty' => '']);
$resIds = array_column($r['data']['results'], 'id');
wa_check(count($resIds) >= 2 && str_contains($r['data']['cart_summary'], 'נמצאו'), "search \"{$w[0]}\" → results in the dropdown");
$new = $resIds[0] === $it[0] ? $resIds[1] : $resIds[0];
$r = $ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => '', 'item' => $new, 'qty' => '']);
wa_check(($r['data']['error_message'] ?? '') === 'כתבו כמות (0 עד 9999)', 'a product without a quantity → "write a quantity"');
$r = $ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => $w[0], 'item' => $new, 'qty' => '120']);
wa_check(($cart()[$new] ?? 0) === 120 && str_contains($r['data']['cart_summary'], 'נוסף'), 'product + quantity 120 → added (no 99 limit here)');
$ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => '', 'item' => $it[0], 'qty' => '3']);
wa_check(($cart()[$it[0]] ?? 0) === 3, 'a cart line + quantity 3 → replaced');
$ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => '', 'item' => $new, 'qty' => '0']);
wa_check(!isset($cart()[$new]), 'quantity 0 → removed');
$r = $ep('data_exchange', 'PRODUCTS', ['cmd' => 'go', 'q' => 'zzqqxx', 'item' => '', 'qty' => '']);
wa_check(str_contains($r['data']['error_message'] ?? '', 'לא נמצא') && array_column($r['data']['results'], 'id') === [$it[0]],
    'nothing found → an error; the dropdown is back to the cart\'s lines');
$emptyData = ['supplier' => $sup, 'cart' => []];
$r = poagent_bench_b_products_screen($emptyData);
wa_check(!$r['data']['has_results'] && $r['data']['results'] === [['id' => 'none', 'title' => '—']], 'empty cart, no search → the dropdown hidden (with its placeholder option)');
$r = $ep('BACK', 'PRODUCTS');
wa_check($r['screen'] === 'PRODUCTS', 'coming back to PRODUCTS (BACK) → refreshed');
$r = $ep('data_exchange', 'PRODUCTS', ['cmd' => 'confirm']);
$future = new DateTimeImmutable('+6 days', new DateTimeZone('Asia/Jerusalem'));
wa_check($r['screen'] === 'CONFIRM' && str_contains($r['data']['summary'], $it[1]) && $r['data']['default_date'] === poagent_wa_next_business_day()
    && $r['data']['cart_ref'] === $tk['r'], '"לסיכום ואישור" → CONFIRM: summary, delivery date default, the order\'s ref');
try { $ep('INIT', null, [], poagent_bench_b_token(WA_TEST_FROM, 'otherref')); $ok = false; } catch (WaFlowTokenError $e) { $ok = true; }
wa_check($ok, 'a token of another order (an old message\'s button) → refused (427)');

$nfm = fn(array $resp) => ['type' => 'interactive', 'interactive' => ['type' => 'nfm_reply',
    'nfm_reply' => ['name' => 'flow', 'body' => 'Sent', 'response_json' => json_encode($resp)]]];
$sent = $say($nfm(['flow_token' => $token, 'delivery_date' => $future->format('Y-m-d'), 'cart_ref' => $tk['r']]));
$bpo = POStore::lastPoFor(WA_TEST_FROM, $sup);
wa_check(str_contains(wa_test_text($sent[0] ?? []), "הזמנה {$bpo['unique_id']} נוצרה") && ($bpo['source'] ?? '') === 'wa_b',
    "the Flow sent (nfm_reply) → PO {$bpo['unique_id']} created, source wa_b");
wa_check(count($bpo['items']) === 1 && $bpo['items'][0]['qty'] === 3 && $bpo['delivery_date'] === $future->format('Y-m-d'), 'the PO: the kept cart, the chosen date');
$l = $lastLog();
wa_check($l['mode'] === 'b' && $l['outcome'] === 'created' && $l['searches'] === 2 && $l['zero_result_searches'] === 1 && $l['edits'] === 2,
    'measured: method b, created, searches, edits', json_encode($l));
$sent = $say($nfm(['flow_token' => $token, 'delivery_date' => $future->format('Y-m-d'), 'cart_ref' => $tk['r']]));
wa_check($sent === [] && POStore::lastPoFor(WA_TEST_FROM, $sup)['unique_id'] === $bpo['unique_id'], 'the same completion again → no second PO');
try { $ep('INIT'); $ok = false; } catch (WaFlowTokenError $e) { $ok = true; }
wa_check($ok, 'after the PO, that form\'s button no longer opens it');
$sent = $say('היי');
$t2 = $sent[0]['interactive']['action']['parameters']['flow_token'] ?? '';
wa_check($t2 !== $token && str_contains(wa_test_text($sent[0]), 'הזמנת רכש חדשה'), 'the next message → a new form for a new order');
$sent = $say('עוד משהו');
wa_check(($sent[0]['interactive']['action']['parameters']['flow_token'] ?? '') === $t2 && str_contains(wa_test_text($sent[0]), 'הטופס פתוח'),
    'a message while a form is open → the same form again, cart kept');
WaSessionStore::clear(WA_TEST_FROM);
WaClient::$captureFailTypes = ['interactive'];   // Meta refuses the Flow message (#139000 Blocked by Integrity)
$sent = $say('היי');
WaClient::$captureFailTypes = [];
wa_check(count($sent) === 2 && ($sent[1]['type'] ?? '') === 'text' && str_contains(wa_test_text($sent[1]), 'לא זמין כרגע')
    && wa_test_session() === null, 'Meta refuses the form → the user is told so (not silence), no order left open');
wa_check_limits($all, 'method B');

// The Flow JSON itself: Meta's rules we can check here.
$flow = json_decode((string) file_get_contents(__DIR__ . '/../whatsapp/flows/po_order.flow.json'), true);
wa_check(is_array($flow) && $flow['version'] === '7.3', 'po_order.flow.json is valid JSON');
$limits = ['TextInput' => 20, 'Dropdown' => 20, 'Footer' => 35, 'EmbeddedLink' => 25, 'OptIn' => 120, 'DatePicker' => 40];
$errs = [];
$ids = array_column($flow['screens'], 'id');
foreach ($flow['screens'] as $s) {
    foreach ($s['data'] ?? [] as $k => $f) if (!array_key_exists('__example__', $f)) $errs[] = "{$s['id']}.$k has no __example__";
    $types = array_column($s['layout']['children'], 'type');
    if (!empty($s['terminal']) && !in_array('Footer', $types, true)) $errs[] = "{$s['id']} terminal without Footer";
    if (count(array_keys($types, 'EmbeddedLink')) > 2) $errs[] = "{$s['id']} more than 2 EmbeddedLinks";
    foreach ($s['layout']['children'] as $c) {
        $text = $c['label'] ?? $c['text'] ?? '';
        if (isset($limits[$c['type']]) && !str_contains($text, '${') && mb_strlen($text) > $limits[$c['type']]) $errs[] = "{$s['id']} {$c['type']} \"{$text}\" > {$limits[$c['type']]}";
        foreach ([$c['on-click-action'] ?? null] as $a) if ($a && $a['name'] === 'complete' && empty($s['terminal'])) $errs[] = "{$s['id']}: complete on a non-terminal screen";
    }
}
foreach ($flow['routing_model'] as $from => $tos) foreach ($tos as $to) {
    if (array_search($to, $ids, true) <= array_search($from, $ids, true)) $errs[] = "route $from → $to is not forward";
}
wa_check($errs === [], 'Flow JSON: labels within limits, data examples, forward routes only, terminal screen has a Footer', implode('; ', $errs));

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Method D — the order typed as a free list');
$p = poagent_bench_d_parse("בצל 5\n- מלפפון x12\n2. קולה 1.5 6\nעגבניה\n\nחלב - 3 יח'\nקולה 1.5\nגזר 0");
wa_check(array_column($p, 'qty') === [5, 12, 6, null, 3, null, null] && $p[2]['text'] === 'קולה 1.5' && $p[1]['text'] === 'מלפפון' && $p[4]['text'] === 'חלב',
    'parse: the quantity is the last number on the line; bullets dropped; "קולה 1.5" / no number / 0 = no quantity', json_encode($p, JSON_UNESCAPED_UNICODE));
wa_check(poagent_bench_d_is_list("בצל 5") && poagent_bench_d_is_list("בצל\nגזר") && !poagent_bench_d_is_list('בצל') && !poagent_bench_d_is_list('קולה 1.5'),
    'one line with a quantity, or several lines = a list; one line without = a search');
// A supplier with a word that fits 2–5 products (a conflict), one that fits more, and a product of its own.
$dSup = $amb = $amb2 = $wide = null;
foreach (array_merge([$sup], $suppliers) as $s) {
    $amb = $amb2 = $wide = null;
    $seen = [];
    foreach ($indexData[$s]['items'] as $row) {
        foreach (explode(' ', $row[3]) as $w) {
            if (mb_strlen($w) < 3 || ctype_digit($w) || isset($seen[$w])) continue;
            $seen[$w] = true;
            $n = count(poagent_bench_d_match($s, $w));
            if ($n >= 2 && $n <= POAGENT_BENCH_D_MAX_OPTIONS) { if ($amb === null) $amb = $w; elseif ($amb2 === null) $amb2 = $w; }
            if ($n > POAGENT_BENCH_D_MAX_OPTIONS && $wide === null) $wide = $w;
        }
    }
    if ($amb !== null && $amb2 !== null && $wide !== null) { $dSup = $s; break; }
}
wa_check($dSup !== null, "a supplier with two conflict words and a too-wide one ({$dSup}: \"{$amb}\", \"{$amb2}\", \"{$wide}\")");
$ambOpts = array_column(poagent_bench_d_match($dSup, $amb), 'barcode');
$amb2Opts = array_column(poagent_bench_d_match($dSup, $amb2), 'barcode');
foreach ($indexData[$dSup]['items'] as $one) if (!in_array($one[0], array_merge($ambOpts, $amb2Opts), true)) break;   // [barcode, name, ...] — by its barcode: one product

// Forgiving search (CatalogIndex::searchItemsLoose): singular / plural, one letter off.
$st = fn(string $w) => CatalogIndex::stem(CatalogIndex::normalize($w));
wa_check($st('עגבניות') === $st('עגבניה') && $st('מלפפונים') === $st('מלפפון') && $st('שקיות') === $st('שקית') && $st('מים') === 'מימ',
    'stem: plural and singular meet (עגבניות / עגבניה, מלפפונים / מלפפון); a short word is left whole');
wa_check(CatalogIndex::searchItemsLoose($dSup, $one[0]) === CatalogIndex::searchItems($dSup, $one[0]), 'digits (a barcode): no forgiving, the same as the plain search');
$slip = null;   // a word of a product's name with one letter left out, that the plain search does not find
foreach ($indexData[$dSup]['items'] as $row) {
    foreach (explode(' ', $row[3]) as $w) {
        if (mb_strlen($w) < 6 || preg_match('/\d/', $w)) continue;
        $typed = mb_substr($w, 0, 2) . mb_substr($w, 3);
        if (CatalogIndex::searchItems($dSup, $typed) === []) { $slip = [$typed, $row[0], $w]; break 2; }
    }
}
$loose = $slip ? array_column(CatalogIndex::searchItemsLoose($dSup, $slip[0]), 'barcode') : [];
wa_check($slip !== null && in_array($slip[1], $loose, true), 'a letter left out ("' . ($slip[0] ?? '') . '" for "' . ($slip[2] ?? '') . '") still finds the product');
$strictAmb = array_column(CatalogIndex::searchItems($dSup, $amb), 'barcode');
wa_check(array_slice(array_column(CatalogIndex::searchItemsLoose($dSup, $amb), 'barcode'), 0, count($strictAmb)) === $strictAmb,
    'what the plain search finds comes first, in its order');
wa_check(CatalogIndex::searchItemsLoose($dSup, 'zzqqxx') === [], 'nonsense still finds nothing');

poagent_access_set_wa_mode(WA_TEST_FROM, 'd');
WaSessionStore::clear(WA_TEST_FROM);
$all = [];
$say('היי');
$btn('menu_po');
$sent = $say($dSup);
if ($state() === 'a_supplier') {   // several suppliers by that name: tap ours
    $i = array_search($dSup, wa_test_session()['data']['sup_map'], true);
    $sent = $tap("sup:{$i}");
}
if ($state() === 'a_start') $sent = $btn('new');
wa_check($state() === 'a_build' && str_contains(wa_test_text(end($sent)), 'מוצר בכל שורה'), 'the supplier chosen → "write the order — a product per line, the quantity at the end"');
wa_check((wa_test_session()['data']['bench']['mode'] ?? '') === 'd', 'the attempt is measured as method D');

// One list: a product of its own, two conflicts, a too-wide word, nonsense, a line with no quantity.
$sent = $say("{$one[0]} 3\n{$amb} 5\n{$amb2} 2\n{$wide} 4\nzzqqxx 7\n{$amb}");
$txt = implode("\n", array_map('wa_test_text', $sent));
wa_check(($cart()[$one[0]] ?? 0) === 3 && str_contains($txt, 'נכנסו 1'), 'a line that fits one product → in the cart with its quantity');
wa_check(str_contains($txt, 'לא ברור איזה מוצר נדרש') && str_contains($txt, "{$wide} 4"), 'more than ' . POAGENT_BENCH_D_MAX_OPTIONS . ' products → "not clear which product — write more details"');
wa_check(str_contains($txt, 'אין מוצר ברשימה שעונה על התיאור הזה') && str_contains($txt, 'zzqqxx 7'), 'no product → "no product in the list fits this description"');
wa_check(str_contains($txt, 'חסרה כמות'), 'a line with no number at the end → "the quantity is missing"');
// Every id a reply can be tapped with (reply buttons and list rows), and the message carrying one.
$tapIds = function (array $sent): array {
    $ids = [];
    foreach ($sent as $p) {
        foreach ($p['interactive']['action']['buttons'] ?? [] as $b) $ids[] = $b['reply']['id'];
        foreach ($p['interactive']['action']['sections'] ?? [] as $s) foreach ($s['rows'] as $r) $ids[] = $r['id'];
    }
    return $ids;
};
$ids = $tapIds($sent);
$picks = array_values(array_filter($ids, fn($x) => str_starts_with($x, 'pick:')));
$conflictMsgs = array_values(array_filter($sent, fn($p) => str_contains(wa_test_text($p), '* × ')));
wa_check($state() === 'a_resolve' && count($conflictMsgs) === 2 && count($picks) === count($ambOpts) + count($amb2Opts)
    && str_contains(wa_test_text($conflictMsgs[0]), "*{$amb}* × 5") && str_contains(wa_test_text($conflictMsgs[1]), "*{$amb2}* × 2"),
    'both conflicts sent together, a message each: what was written, and under it the products to tap');
$expect = count($ambOpts) <= 3 ? 'button' : 'list';
wa_check(($conflictMsgs[0]['interactive']['type'] ?? '') === $expect,
    count($ambOpts) . " options → a {$expect} message");
wa_check(count(array_filter($ids, fn($x) => str_starts_with($x, 'skip:'))) >= 1 || str_contains($txt, 'כתבו: דלג'), 'a way to skip with each conflict');
// A tap on a product of the first conflict.
$firstPick = array_values(array_filter($picks, fn($x) => str_ends_with($x, ":{$ambOpts[0]}")))[0];
$sent = $say(wa_test_tap($firstPick, '', $expect === 'button' ? 'button_reply' : 'list_reply'));
wa_check(($cart()[$ambOpts[0]] ?? 0) === 5 && $state() === 'a_resolve' && str_contains(wa_test_text($sent[0]), 'נשארה שורה אחת'),
    'a tap on a product → in the cart with the line\'s quantity; "one line left to choose"');
$sent = $say(wa_test_tap($firstPick, '', 'button_reply'));
wa_check(($cart()[$ambOpts[0]] ?? 0) === 5 && str_contains(wa_test_text($sent[0]), 'כבר טופלה'), 'the same tap again → "already done", nothing added twice');
$sent = $say('99');
wa_check($state() === 'a_resolve' && str_contains(wa_test_text($sent[0]), 'אין אפשרות מספר 99'), 'a number that is not an option → says so');
$second = count($ambOpts) + 1;   // the first option of the second conflict — typed, instead of a tap
$sent = $say("{$second}");
$txt = implode("\n", array_map('wa_test_text', $sent));
wa_check(($cart()[$amb2Opts[0]] ?? 0) === 2, "typing the option's number (\"{$second}\") works too", json_encode([$cart(), $ambOpts, $amb2Opts, $one[0], $state()]));
wa_check($state() === 'a_build' && str_contains($txt, '3 שורות לא נכנסו') && str_contains($txt, 'סיום והזמנה'),
    'lines still not in → "write them again", with the [סיום והזמנה] button', $txt);
// Written again, now clear: nothing open → the summary.
$sent = $say("{$one[1]} 4");
$txt = implode("\n", array_map('wa_test_text', $sent));
$oneBc = poagent_bench_d_match($dSup, $one[1]);
wa_check(count($oneBc) > 1 || ($state() === 'a_confirm' && str_contains($txt, 'סיכום הזמנה') && str_contains($txt, 'אשר')),
    'a list with nothing open → straight to the summary with [אשר] [ערוך] [בטל]');
if ($state() === 'a_resolve') $say('1');
wa_check($state() === 'a_confirm', 'in the summary');
// From the summary: more lines, a conflict skipped, then editing through the cart.
$sent = $say("{$amb} 9");
$skipIds = array_values(array_filter($tapIds($sent), fn($x) => str_starts_with($x, 'skip:')));
wa_check($state() === 'a_resolve' && count($sent) === 1 && str_contains(wa_test_text($sent[0]), 'שורה אחת מתאימה') && count($skipIds) === 1,
    'a line typed at the summary is read too; one conflict = one message with its products and [דלג]');
$before = $cart();
$sent = $btn($skipIds[0]);
wa_check($cart() === $before && $state() === 'a_confirm', '[דלג] → the line is dropped, back to the summary');
$sent = $btn('edit');
wa_check($state() === 'a_cart', '[ערוך] → the cart: quantities and products can be changed as in method A');
$tap("cart:{$one[0]}");
$btn('qtychg');
$say('8');
wa_check(($cart()[$one[0]] ?? 0) === 8, 'a quantity changed from the cart');
$sent = $say($amb);   // one line, no quantity: method A's search
wa_check($state() === 'a_build' && ($sent[0]['interactive']['type'] ?? '') === 'list', 'one product with no quantity → the search list, as in method A');
$btn('done');
$sent = $btn('yes');
$po = POStore::listPOs()[0] ?? null;
wa_check(str_contains(wa_test_text($sent[0]), 'נוצרה') && $lastLog()['mode'] === 'd' && $lastLog()['outcome'] === 'created',
    '[אשר] → the PO is created; the measurement log says method D');
// Cancelling in the middle of the conflicts.
$say('היי');
$btn('menu_po');
$say($dSup);
if ($state() === 'a_supplier') $tap('sup:' . array_search($dSup, wa_test_session()['data']['sup_map'], true));
if ($state() === 'a_start') $btn('new');
$say("{$amb} 2");
$say('ביטול');
$sent = $btn('cancel_no');
wa_check($state() === 'a_resolve' && str_contains(wa_test_text($sent[0]), "*{$amb}*"), 'ביטול → [המשך הזמנה] in the middle of the conflicts → the conflicts again');
$say('ביטול');
$btn('cancel_yes');
wa_check(empty(wa_test_session()['data']['pending']) && $state() === 'a_done', 'cancelled → back at the start, no conflicts left over');
wa_check_limits($all, 'method D');
// Method A is as it was: a line ending with a number is a search there, not a list.
poagent_access_set_wa_mode(WA_TEST_FROM, 'a');
WaSessionStore::clear(WA_TEST_FROM);
$say('היי');
$btn('menu_po');
$say($dSup);
if ($state() === 'a_supplier') $tap('sup:' . array_search($dSup, wa_test_session()['data']['sup_map'], true));
if ($state() === 'a_start') $btn('new');
$sent = $say("{$amb} 5");
wa_check(in_array($state(), ['a_build', 'a_qty'], true) && empty(wa_test_session()['data']['pending']) && $cart() === [], 'method A unchanged: "word 5" is a search there, not a list');
wa_check($say(['type' => 'audio', 'audio' => ['id' => 'aud1', 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]) === [],
    'a voice message from a phone that is not on method E: ignored, as before');

// ─────────────────────────────────────────────────────────────────────────────
wa_test_section('Method E — the order dictated in a voice message');
poagent_access_set_wa_mode(WA_TEST_FROM, 'e');
WaSessionStore::clear(WA_TEST_FROM);
$all = [];
WaClient::$fakeMedia['aud1'] = ['bytes' => 'OggS-fake', 'mime' => 'audio/ogg'];
$heard = [];   // what the (faked) recording says
VoiceOrder::$fake = function (string $bytes, string $mime) use (&$heard) {
    if ($heard === 'fail') throw new RuntimeException('test: the service is down');
    return $heard;
};
$voice = fn(string $id = 'aud1') => $say(['type' => 'audio', 'audio' => ['id' => $id, 'mime_type' => 'audio/ogg; codecs=opus', 'voice' => true]]);
$say('היי');
$sent = $voice();
wa_check(str_contains(wa_test_text($sent[0]), 'בחרו "הזמנה חדשה"') && $state() === 'a_menu', 'a recording before an order was started → "choose a new order first"');
$sent = $btn('menu_po');
wa_check(str_contains(wa_test_text($sent[0]), 'הקליטו את שם הספק'), '"which supplier?" in method E: record the name, or type it');
// The supplier's name in a recording.
$sent = $voice();   // nothing heard
wa_check(str_contains(wa_test_text($sent[0]), 'לא הצלחתי לשמוע את שם הספק') && $state() === 'a_supplier', 'a recording with nothing heard → "record again, or type part of the name"');
$heard = ['transcript' => 'זזקקקסס.'];
$sent = $voice();
wa_check(str_contains(wa_test_text($sent[0]), 'שמעתי: זזקקקסס') && str_contains(wa_test_text($sent[1]), 'לא נמצא ספק') && $state() === 'a_supplier',
    'a name that is no supplier → what was heard, "no such supplier"');
// Heard with a letter missing in its longest word, and "הספק" in front: still that supplier.
$nameWords = explode(' ', $dSup);
usort($nameWords, fn($x, $y) => mb_strlen($y) <=> mb_strlen($x));
$offName = str_replace($nameWords[0], mb_substr($nameWords[0], 0, 2) . mb_substr($nameWords[0], 3), $dSup);
wa_check(CatalogIndex::searchSuppliers($offName) === [] && in_array($dSup, CatalogIndex::searchSuppliersLoose($offName), true),
    "forgiving supplier search: \"{$offName}\" finds {$dSup} (the plain search does not)");
$heard = ['transcript' => "הספק {$offName}."];
$sent = $voice();
if ($state() === 'a_supplier') $sent = $tap('sup:' . array_search($dSup, wa_test_session()['data']['sup_map'], true));
wa_check((wa_test_session()['data']['supplier'] ?? '') === $dSup, 'the supplier\'s name in a recording, a letter off → that supplier is chosen');
$sent = $state() === 'a_start' ? $btn('new') : $sent;
wa_check($state() === 'a_build' && str_contains(wa_test_text(end($sent)), 'הקליטו הודעה קולית'), 'the supplier chosen → "record a voice message with the order"');

$heard = 'fail';
$sent = $voice();
wa_check(str_contains(wa_test_text($sent[0]), 'לא הצלחתי לפענח') && $state() === 'a_build', 'the transcription fails → "record again, or write the order"; the order stays open');
$sent = $voice('no-such-media');
wa_check(str_contains(wa_test_text($sent[0]), 'לא הצלחתי לקבל את ההקלטה'), 'the recording cannot be downloaded → says so');
$heard = ['transcript' => 'אה שלום מה נשמע', 'lines' => []];
$sent = $voice();
wa_check(str_contains(wa_test_text($sent[0]), 'לא זיהיתי מוצרים') && str_contains(wa_test_text($sent[0]), 'אה שלום'), 'a recording with no products → what was heard, "record again"');

// A real order: a product of its own, a conflict, nonsense, a product with no quantity.
$heard = ['transcript' => 'x', 'lines' => [['text' => $one[1], 'qty' => 3], ['text' => $amb, 'qty' => 5], ['text' => 'zzqqxx', 'qty' => 7], ['text' => $amb2, 'qty' => null]]];
$oneHits = poagent_bench_d_match($dSup, $one[1]);
$sent = $voice();
$txt = implode("\n", array_map('wa_test_text', $sent));
wa_check(str_contains(wa_test_text($sent[0]), 'הבנתי') && str_contains(wa_test_text($sent[0]), "{$amb} — 5") && str_contains(wa_test_text($sent[0]), 'zzqqxx — 7'),
    'the bot writes back what it understood, product and quantity per line');
wa_check(count($oneHits) !== 1 || ($cart()[$oneHits[0]['barcode']] ?? 0) === 3, 'a dictated product that fits one product → in the cart');
wa_check($state() === 'a_resolve' && str_contains($txt, "*{$amb}* × 5") && str_contains($txt, 'אין מוצר ברשימה') && str_contains($txt, 'חסרה כמות'),
    'from there it is method D: the conflict to tap, "no such product", "the quantity is missing"');
$pick = array_values(array_filter($tapIds($sent), fn($x) => str_ends_with($x, ":{$ambOpts[0]}")))[0];
// Another recording in the middle of the conflicts is read too.
$heard = ['transcript' => 'x', 'lines' => [['text' => $amb2, 'qty' => 2]]];
$sent = $voice();
wa_check($state() === 'a_resolve' && count(wa_test_session()['data']['pending']) >= 2, 'another recording while conflicts are open → its lines join them');
$say(wa_test_tap($pick, '', 'button_reply'));
wa_check(($cart()[$ambOpts[0]] ?? 0) === 5, 'a tap settles a dictated line');
$say('דלג');
$sent = $say("{$amb2} 1");   // typing works in method E as well
wa_check($state() === 'a_resolve', 'a typed list is read in method E too');
$say('דלג');
if ($state() !== 'a_confirm') $btn('done');
$sent = $btn('yes');
wa_check(str_contains(wa_test_text($sent[0]), 'נוצרה') && $lastLog()['mode'] === 'e' && $lastLog()['outcome'] === 'created',
    '[אשר] → the PO is created; the measurement log says method E');
wa_check_limits($all, 'method E');
VoiceOrder::$fake = null;
poagent_access_set_wa_mode(WA_TEST_FROM, '');
WaSessionStore::clear(WA_TEST_FROM);

wa_test_done();
