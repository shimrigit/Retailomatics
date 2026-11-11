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
$say('1');
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

wa_test_done();
