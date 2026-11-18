<?php
/**
 * InvoiceTrial/run.php — a trial: how much of a supplier document can the AI read from the
 * whole page, with no crop rectangles and no per-supplier prompt?
 *
 *   php InvoiceTrial/run.php            read every image in preProcessDir, build the report
 *   php InvoiceTrial/run.php --report   rebuild the report from what was already read
 *
 * In:   preProcessDir/*.jpg|jpeg|png  as they came (WhatsApp photos); left untouched
 *       preProcessDir/Trial/suppliers.json   the customer's supplier list [{code, name}]
 * Out:  preProcessDir/Trial/<supplier> <dd-mm-yy> <n>[ p<k>].<ext>   copies, named by what was read
 *       preProcessDir/Trial/_raw/<sha1>.<model>.json                 each answer (so a file is paid for once)
 *       preProcessDir/Trial/report.html                              photo next to what was read, to check by eye
 *
 * Every file is read by each model in MODELS; the first is the one the names and the
 * report go by, the others are shown where they disagree. Key: AIocr/config.json.
 * Nothing here is part of the invoice flow (harmonizedFlow) — it only measures.
 */

if (PHP_SAPI !== 'cli') { http_response_code(403); exit('CLI only'); }

// The models: --models=a,b on the command line (the first is the one shown, the second compared).
$modelsArg = current(preg_grep('/^--models=/', $argv)) ?: '--models=gpt-4.1-mini,gpt-4.1';
define('MODELS', explode(',', substr($modelsArg, 9)));
const PROMPT_VERSION = 'v2';   // part of the cache file name: a changed prompt reads again
// USD per million tokens [input, output] — list prices, for the cost estimate only.
// A model not listed here: the report shows its tokens and no cost.
const PRICES = ['gpt-4.1' => [2.00, 8.00], 'gpt-4.1-mini' => [0.40, 1.60]];
const PARALLEL = 6;

$root = realpath(__DIR__ . '/..');
$inDir = "$root/preProcessDir";
$outDir = "$inDir/Trial";
$rawDir = "$outDir/_raw";
@mkdir($rawDir, 0777, true);

$suppliers = json_decode((string) @file_get_contents("$outDir/suppliers.json"), true);
if (!is_array($suppliers) || !$suppliers) exit("Missing preProcessDir/Trial/suppliers.json\n");
$apiKey = json_decode((string) file_get_contents("$root/AIocr/config.json"), true)['openai_api_key'] ?? '';
if ($apiKey === '') exit("openai_api_key missing in AIocr/config.json\n");

$files = array_values(array_filter(glob("$inDir/*") ?: [], fn($f) => is_file($f) && preg_match('/\.(jpe?g|png)$/i', $f)));
natsort($files);
$files = array_values($files);
if (!$files) exit("No images in preProcessDir\n");

$supplierList = implode("\n", array_map(fn($s) => "{$s['code']} | {$s['name']}", $suppliers));
// Who the documents are addressed to (in the real flow: the shop being worked on) and the year they are from.
$customer = 'סופר ברנהרד בע"מ / סופר ברקת (דרך החורש 4, יהוד; ע.מ. 516683729)';
$year = date('Y');
$system = <<<PROMPT
You read a photo of ONE page of a document an Israeli supermarket got from a supplier (tax invoice, delivery note, credit / returns note), printed in Hebrew, and return what is printed on it as JSON.

Return exactly this shape:
{
  "doc_type": "invoice" | "delivery_note" | "credit_or_return" | "other",
  "doc_type_printed": "<the document's title as printed, e.g. חשבונית מס, תעודת משלוח>",
  "supplier_name_printed": "<the supplier's name as printed — the company that ISSUED the document, not the customer it is addressed to>",
  "supplier_vat_id": "<the supplier's עוסק מורשה / ח.פ. number, digits only, or null>",
  "supplier_code": "<the code from the supplier list below of the supplier that issued the document, or null when it is not in the list>",
  "supplier_match": "high" | "medium" | "low" | "none",
  "doc_number": "<the document's number as printed, or null>",
  "doc_date": "<the document's date, YYYY-MM-DD, or null>",
  "page_number": <number or null>, "page_count": <number or null>,
  "continues_from_previous_page": true | false,
  "items": [ { "code": "<barcode / item code as printed, or null>", "description": "<as printed>", "qty": <number or null>, "unit_price": <number or null>, "discount_pct": <number or null>, "line_total": <number or null> } ],
  "totals": { "before_discount": <number or null>, "discount": <number or null>, "before_vat": <number or null>, "vat": <number or null>, "total": <number or null> },
  "unreadable": [ "<what you could not read with confidence, and where>" ],
  "notes": "<anything a checker should know: handwriting on the page, a cut-off page, prices without VAT, etc.>"
}

Rules:
- Copy numbers digit by digit as printed, including minus signs. Never calculate, complete or round a number. Not printed or not readable → null, and say so in "unreadable".
- Every item row of the table, in order, none skipped and none added. Keep all the keys in every item.
- "qty": the number of units ordered/supplied in the row (if there are both cartons and units columns, the units; mention the other in "notes").
- "unit_price": the price of one unit as printed in the row; "line_total": the row's total as printed.
- "totals.total": the document's final amount to pay. If the page has no totals (a middle page), all nulls.
- Supplier and customer: the supplier is the company in the letterhead / logo at the top. The customer is the supermarket the document is addressed to — its name comes after "לכבוד" / "שם לקוח" and is {$customer}. The customer is NEVER the supplier, and its ע.מ. is never supplier_vat_id. If the supplier appears only as a logo, read the name from the logo.
- Dates are printed day/month/year (dd/mm/yy or dd/mm/yyyy), and these documents are from {$year}: "09/11/26" is {$year}-11-09. "doc_date" is the document's own date (תאריך / תאריך חשבונית / תאריך אסמכתא) — not a due or value date (תאריך ערך, לתשלום עד) and not the print time.
- Before answering, count the rows of the table on the page and check that "items" has that many; if the page prints a row count ("סה"כ שורות", "פריטים"), it must agree.
- A printed "total" that is a count of units (delivery notes without prices) is not money: leave totals.total null and say so in "notes".
- "supplier_match": how sure you are that supplier_code is the issuer — "high" when the printed name clearly is that list entry, "none" when no entry fits (then supplier_code is null). Do not force a match.

The customer's supplier list (code | name):
{$supplierList}
PROMPT;

// ── read ────────────────────────────────────────────────────────────────────
$rawFile = fn(string $file, string $model) => $rawDir . '/' . sha1_file($file) . ".{$model}." . PROMPT_VERSION . ".json";
if (!in_array('--report', $argv, true)) {
    foreach (MODELS as $model) {
        $todo = array_values(array_filter($files, fn($f) => !is_file($rawFile($f, $model))));
        echo "$model: " . count($todo) . ' of ' . count($files) . " files to read\n";
        foreach (array_chunk($todo, PARALLEL) as $batch) {
            $mh = curl_multi_init();
            $handles = [];
            foreach ($batch as $f) {
                $mime = preg_match('/\.png$/i', $f) ? 'image/png' : 'image/jpeg';
                $payload = [
                    'model' => $model,
                    'messages' => [
                        ['role' => 'system', 'content' => $system],
                        ['role' => 'user', 'content' => [
                            ['type' => 'text', 'text' => 'Read this page:'],
                            ['type' => 'image_url', 'image_url' => ['url' => "data:$mime;base64," . base64_encode(file_get_contents($f)), 'detail' => 'high']],
                        ]],
                    ],
                    'response_format' => ['type' => 'json_object'],
                ];
                // The gpt-5 / o families take other parameters than gpt-4.
                $payload += preg_match('/^(gpt-5|o\d)/', $model)
                    ? ['max_completion_tokens' => 16000, 'reasoning_effort' => 'low']
                    : ['max_tokens' => 8000, 'temperature' => 0.0];
                $ch = curl_init('https://api.openai.com/v1/chat/completions');
                curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_TIMEOUT => 240,
                    CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
                    CURLOPT_POSTFIELDS => json_encode($payload)]);
                curl_multi_add_handle($mh, $ch);
                $handles[] = [$ch, $f];
            }
            do {
                curl_multi_exec($mh, $running);
                curl_multi_select($mh, 1.0);
            } while ($running > 0);
            foreach ($handles as [$ch, $f]) {
                $res = json_decode((string) curl_multi_getcontent($ch), true);
                $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $seconds = round(curl_getinfo($ch, CURLINFO_TOTAL_TIME), 1);
                curl_multi_remove_handle($mh, $ch);
                $data = json_decode((string) ($res['choices'][0]['message']['content'] ?? ''), true);
                if ($status !== 200 || !is_array($data)) {
                    echo '  FAILED ' . basename($f) . ": HTTP $status " . mb_substr((string) ($res['error']['message'] ?? 'bad JSON'), 0, 200) . "\n";
                    continue;
                }
                file_put_contents($rawFile($f, $model), json_encode(['file' => basename($f), 'model' => $model, 'seconds' => $seconds,
                    'usage' => $res['usage'] ?? [], 'finish' => $res['choices'][0]['finish_reason'] ?? '', 'data' => $data],
                    JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
                echo '  ok ' . basename($f) . " ({$seconds}s)\n";
            }
            curl_multi_close($mh);
        }
    }
}

// ── gather ──────────────────────────────────────────────────────────────────
$byCode = array_column($suppliers, 'name', 'code');
$pages = [];
foreach ($files as $f) {
    $p = ['file' => $f, 'read' => []];
    foreach (MODELS as $model) {
        $raw = json_decode((string) @file_get_contents($rawFile($f, $model)), true);
        if ($raw) $p['read'][$model] = $raw;
    }
    if (isset($p['read'][MODELS[0]])) $pages[] = $p;
}
if (!$pages) exit("Nothing was read\n");

$num = fn($v) => is_numeric($v) ? (float) $v : null;
$safe = fn(string $s) => trim(preg_replace('/\s+/u', ' ', preg_replace('/[\\\\\/:*?"<>|״׳\']+/u', '', $s)));

// Documents: pages with the same supplier and document number are one document.
$docs = [];
$nameByVat = [];   // a supplier not in the list: one name for all its documents, by its ע.מ. (the name is read a little differently each time)
foreach ($pages as $p) {
    $d = $p['read'][MODELS[0]]['data'];
    $code = (string) ($d['supplier_code'] ?? '');
    $inList = $code !== '' && isset($byCode[$code]) && ($d['supplier_match'] ?? '') === 'high';   // only a sure match names the file
    $supplier = $inList ? $byCode[$code] : (trim((string) ($d['supplier_name_printed'] ?? '')) ?: 'ספק לא מזוהה');
    $vat = preg_replace('/\D/', '', (string) ($d['supplier_vat_id'] ?? ''));
    if (!$inList && $vat !== '') $supplier = $nameByVat[$vat] ??= $supplier;
    $number = trim((string) ($d['doc_number'] ?? ''));
    $key = $number !== '' ? "$supplier|$number" : 'file|' . basename($p['file']);
    $docs[$key] ??= ['supplier' => $supplier, 'in_list' => $inList, 'code' => $inList ? $code : '', 'number' => $number,
        'date' => (string) ($d['doc_date'] ?? ''), 'pages' => []];
    if ($docs[$key]['date'] === '' && !empty($d['doc_date'])) $docs[$key]['date'] = (string) $d['doc_date'];
    $docs[$key]['pages'][] = $p;
}
// Names: <supplier> <dd-mm-yy> <n>, n counting the documents of that supplier and date.
foreach (glob("$outDir/*.{jpg,jpeg,png,JPG,JPEG,PNG}", GLOB_BRACE) ?: [] as $old) @unlink($old);   // the copies of the last run
$counter = [];
foreach ($docs as &$doc) {
    usort($doc['pages'], fn($a, $b) => [($a['read'][MODELS[0]]['data']['page_number'] ?? 99), $a['file']] <=> [($b['read'][MODELS[0]]['data']['page_number'] ?? 99), $b['file']]);
    $t = strtotime($doc['date']);
    $base = $safe($doc['supplier'] . ($doc['in_list'] ? '' : ' (לא ברשימה)')) . ' ' . ($t ? date('d-m-y', $t) : 'ללא תאריך');
    $n = $counter[$base] = ($counter[$base] ?? 0) + 1;
    foreach ($doc['pages'] as $i => &$p) {
        $ext = strtolower(pathinfo($p['file'], PATHINFO_EXTENSION));
        $p['copy'] = "$base $n" . (count($doc['pages']) > 1 ? ' p' . ($i + 1) : '') . ".$ext";
        copy($p['file'], "$outDir/{$p['copy']}");
    }
    unset($p);
    $doc['name'] = "$base $n";
}
unset($doc);

// ── checks ──────────────────────────────────────────────────────────────────
/** The page's lines with a flag each, and how the lines' sum sits against the printed totals. */
function trial_checks(array $d, callable $num): array
{
    $sum = 0.0;
    $bad = [];
    $complete = true;
    foreach ($d['items'] ?? [] as $i => $it) {
        [$q, $pr, $lt, $disc] = [$num($it['qty'] ?? null), $num($it['unit_price'] ?? null), $num($it['line_total'] ?? null), $num($it['discount_pct'] ?? null)];
        if ($lt === null) { $complete = false; continue; }
        $sum += $lt;
        if ($q !== null && $pr !== null) {
            $calc = $q * $pr * (1 - ($disc ?? 0) / 100);
            if (abs(abs($calc) - abs($lt)) > max(0.06, abs($lt) * 0.002)) $bad[$i] = round($calc, 2);
        }
    }
    $t = $d['totals'] ?? [];
    $match = null;
    foreach (['before_discount', 'before_vat', 'total'] as $k) {
        $v = $num($t[$k] ?? null);
        if ($v !== null && abs(abs($v) - abs($sum)) <= 0.06) { $match = $k; break; }
    }
    return ['sum' => round($sum, 2), 'bad_lines' => $bad, 'sum_matches' => $match, 'has_totals' => $num($t['total'] ?? null) !== null, 'complete' => $complete];
}

// ── report ──────────────────────────────────────────────────────────────────
$h = fn($v) => htmlspecialchars((string) $v, ENT_QUOTES);
$fmt = fn($v) => $v === null || $v === '' ? '<span class="nul">—</span>' : $h(is_numeric($v) ? rtrim(rtrim(number_format((float) $v, 2, '.', ','), '0'), '.') : $v);
$cost = function (array $raw): float {
    [$in, $out] = PRICES[$raw['model']] ?? [0, 0];   // unknown price: 0, shown as "?"
    return (($raw['usage']['prompt_tokens'] ?? 0) * $in + ($raw['usage']['completion_tokens'] ?? 0) * $out) / 1e6;
};
$typeHe = ['invoice' => 'חשבונית', 'delivery_note' => 'תעודת משלוח', 'credit_or_return' => 'זיכוי / החזרה', 'other' => 'אחר'];
$totals = array_fill_keys(MODELS, ['cost' => 0.0, 'seconds' => 0.0, 'pages' => 0, 'in' => 0, 'out' => 0]);
$summary = '';
$body = '';
$stats = ['docs' => count($docs), 'pages' => count($pages), 'in_list' => 0, 'lines' => 0, 'bad_lines' => 0, 'sum_ok' => 0, 'with_totals' => 0, 'differ' => 0];
$di = 0;
foreach ($docs as $doc) {
    $di++;
    if ($doc['in_list']) $stats['in_list']++;
    $docBad = 0;
    $docLines = 0;
    $sumState = [];
    $pagesHtml = '';
    foreach ($doc['pages'] as $p) {
        $main = $p['read'][MODELS[0]];
        $d = $main['data'];
        $other = $p['read'][MODELS[1]]['data'] ?? null;
        foreach ($p['read'] as $m => $raw) {
            $totals[$m]['cost'] += $cost($raw);
            $totals[$m]['seconds'] += $raw['seconds'];
            $totals[$m]['pages']++;
            $totals[$m]['in'] += $raw['usage']['prompt_tokens'] ?? 0;
            $totals[$m]['out'] += $raw['usage']['completion_tokens'] ?? 0;
        }
        $c = trial_checks($d, $num);
        $docLines += count($d['items'] ?? []);
        $docBad += count($c['bad_lines']);
        if ($c['has_totals']) $sumState[] = $c['sum_matches'] !== null;
        // A cell; marked when the second model read something else.
        $cell = function ($v, $o, bool $has, string $cls = '') use ($fmt, $h, $num, &$stats) {
            $diff = $has && (is_numeric($v) && is_numeric($o) ? abs((float) $v - (float) $o) > 0.005 : trim((string) $v) !== trim((string) $o));
            if ($diff) $stats['differ']++;
            return '<td class="' . $cls . ($diff ? ' diff' : '') . '"' . ($diff ? ' title="' . $h(MODELS[1] . ': ' . ($o === null ? '—' : $o)) . '"' : '') . '>' . $fmt($v)
                . ($diff ? '<div class="alt">' . $h(MODELS[1]) . ': ' . $fmt($o) . '</div>' : '') . '</td>';
        };
        $rows = '';
        foreach ($d['items'] ?? [] as $i => $it) {
            $o = $other['items'][$i] ?? null;
            $has = $other !== null;
            $rows .= '<tr' . (isset($c['bad_lines'][$i]) ? ' class="bad"' : '') . '><td class="n">' . ($i + 1) . '</td>'
                . $cell($it['code'] ?? null, $o['code'] ?? null, $has, 'code')
                . $cell($it['description'] ?? null, $o['description'] ?? null, false, 'desc')
                . $cell($it['qty'] ?? null, $o['qty'] ?? null, $has, 'num')
                . $cell($it['unit_price'] ?? null, $o['unit_price'] ?? null, $has, 'num')
                . $cell($it['discount_pct'] ?? null, $o['discount_pct'] ?? null, $has, 'num')
                . $cell($it['line_total'] ?? null, $o['line_total'] ?? null, $has, 'num')
                . '<td class="chk">' . (isset($c['bad_lines'][$i]) ? '⚠ כמות×מחיר = ' . $fmt($c['bad_lines'][$i]) : '') . '</td></tr>';
        }
        if ($other !== null && count($other['items'] ?? []) !== count($d['items'] ?? [])) {
            $rows .= '<tr class="bad"><td colspan="8">⚠ ' . $h(MODELS[1]) . ' קרא ' . count($other['items'] ?? []) . ' שורות, ' . $h(MODELS[0]) . ' קרא ' . count($d['items'] ?? []) . '</td></tr>';
        }
        $tot = '';
        foreach (['before_discount' => 'לפני הנחה', 'discount' => 'הנחה', 'before_vat' => 'לפני מע"מ', 'vat' => 'מע"מ', 'total' => 'סה"כ לתשלום'] as $k => $label) {
            $tot .= "<tr><th>$label</th>" . $cell($d['totals'][$k] ?? null, $other['totals'][$k] ?? null, $other !== null, 'num') . '</tr>';
        }
        $sumLine = 'סכום השורות שנקראו: <b>' . $fmt($c['sum']) . '</b> — '
            . ($c['sum_matches'] ? '<span class="ok">✔ שווה ל"' . ['before_discount' => 'לפני הנחה', 'before_vat' => 'לפני מע"מ', 'total' => 'סה"כ לתשלום'][$c['sum_matches']] . '" המודפס</span>'
                : ($c['has_totals'] ? '<span class="warn">⚠ לא שווה לאף סכום מודפס</span>' : '<span class="nul">אין סכומים בעמוד</span>'));
        $head = '';
        foreach ([['סוג', $typeHe[$d['doc_type'] ?? ''] ?? ($d['doc_type'] ?? ''), $typeHe[$other['doc_type'] ?? ''] ?? ($other['doc_type'] ?? '')],
                  ['כותרת מודפסת', $d['doc_type_printed'] ?? null, null],
                  ['ספק (מודפס)', $d['supplier_name_printed'] ?? null, null],
                  ['ע.מ / ח.פ ספק', $d['supplier_vat_id'] ?? null, $other['supplier_vat_id'] ?? null],
                  ['ספק ברשימה', isset($byCode[$d['supplier_code'] ?? '']) ? $d['supplier_code'] . ' ' . $byCode[$d['supplier_code']] . ' (' . ($d['supplier_match'] ?? '') . ')' : 'לא ברשימה',
                      $other === null ? null : (isset($byCode[$other['supplier_code'] ?? '']) ? $other['supplier_code'] . ' ' . $byCode[$other['supplier_code']] . ' (' . ($other['supplier_match'] ?? '') . ')' : 'לא ברשימה')],
                  ['מספר תעודה', $d['doc_number'] ?? null, $other['doc_number'] ?? null],
                  ['תאריך', $d['doc_date'] ?? null, $other['doc_date'] ?? null],
                  ['עמוד', ($d['page_number'] ?? '') !== '' && $d['page_number'] !== null ? $d['page_number'] . ' / ' . ($d['page_count'] ?? '?') : null, null]] as [$label, $v, $o]) {
            $head .= "<tr><th>$label</th>" . $cell($v, $o, $other !== null && $o !== null) . '</tr>';
        }
        $notes = '';
        if (!empty($d['unreadable'])) $notes .= '<p class="warn">לא נקרא בוודאות: ' . $h(implode(' · ', (array) $d['unreadable'])) . '</p>';
        if (!empty($d['notes'])) $notes .= '<p class="note">הערות: ' . $h($d['notes']) . '</p>';
        $pagesHtml .= '<div class="page"><div class="img"><a href="' . $h(rawurlencode($p['copy'])) . '" target="_blank"><img loading="lazy" src="' . $h(rawurlencode($p['copy'])) . '"></a>'
            . '<div class="fn">' . $h($p['copy']) . '<br><span class="nul">מקור: ' . $h(basename($p['file'])) . '</span></div></div>'
            . '<div class="data"><table class="head">' . $head . '</table>'
            . '<table class="items"><tr><th>#</th><th>קוד</th><th>תיאור</th><th>כמות</th><th>מחיר</th><th>הנחה %</th><th>סה"כ שורה</th><th>בדיקה</th></tr>' . $rows . '</table>'
            . '<p class="sum">' . $sumLine . '</p><table class="tot">' . $tot . '</table>' . $notes . '</div></div>';
    }
    $stats['lines'] += $docLines;
    $stats['bad_lines'] += $docBad;
    if ($sumState) { $stats['with_totals']++; if (!in_array(false, $sumState, true)) $stats['sum_ok']++; }
    $first = $doc['pages'][0]['read'][MODELS[0]]['data'];
    $lastTotals = end($doc['pages'])['read'][MODELS[0]]['data']['totals'] ?? [];
    $state = $docBad ? '<span class="warn">⚠ ' . $docBad . ' שורות</span>' : '<span class="ok">✔</span>';
    $sumCell = !$sumState ? '<span class="nul">—</span>' : (in_array(false, $sumState, true) ? '<span class="warn">⚠</span>' : '<span class="ok">✔</span>');
    $summary .= "<tr><td>$di</td><td><a href=\"#d$di\">" . $h($doc['name']) . '</a></td><td>' . ($doc['in_list'] ? '<span class="ok">' . $h($doc['code']) . '</span>' : '<span class="warn">לא ברשימה</span>')
        . '</td><td>' . $h($typeHe[$first['doc_type'] ?? ''] ?? '') . '</td><td>' . $fmt($doc['number']) . '</td><td>' . $fmt($doc['date']) . '</td><td>' . count($doc['pages'])
        . "</td><td>$docLines</td><td class=\"num\">" . $fmt($lastTotals['total'] ?? null) . "</td><td>$state</td><td>$sumCell</td></tr>";
    $body .= "<section id=\"d$di\"><h2>$di. " . $h($doc['name']) . '</h2>' . $pagesHtml . '</section>';
}
$modelRows = '';
foreach ($totals as $m => $t) {
    if (!$t['pages']) continue;
    $modelRows .= '<tr><td>' . $h($m) . ($m === MODELS[0] ? ' (מוצג)' : ' (להשוואה)') . "</td><td>{$t['pages']}</td><td>" . (isset(PRICES[$m]) ? '$' . number_format($t['cost'], 3) : '?') . '</td><td>' . (isset(PRICES[$m]) ? '$' . number_format($t['cost'] / $t['pages'], 4) : '?')
        . '</td><td>' . number_format($t['in'] / $t['pages']) . ' / ' . number_format($t['out'] / $t['pages'])
        . '</td><td>' . round($t['seconds'] / $t['pages'], 1) . ' שנ׳</td></tr>';
}
$html = '<!doctype html><html lang="he" dir="rtl"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>ניסוי קריאת תעודות</title><style>
body{font-family:Segoe UI,Arial,sans-serif;margin:0;padding:16px 24px;background:#f4f5f7;color:#1c2330;font-size:14px}
h1{font-size:22px;margin:0 0 4px}h2{font-size:17px;margin:0 0 10px}
section{background:#fff;border:1px solid #d9dde3;border-radius:8px;padding:14px;margin:18px 0}
table{border-collapse:collapse}th,td{border:1px solid #d9dde3;padding:4px 8px;text-align:right;vertical-align:top}
th{background:#eef1f5;font-weight:600;white-space:nowrap}
.sumtab{background:#fff;width:100%}.sumtab td,.sumtab th{padding:6px 8px}
.page{display:flex;gap:16px;align-items:flex-start;margin-bottom:18px;flex-wrap:wrap}
.img{flex:0 0 42%;min-width:300px;position:sticky;top:8px}.img img{width:100%;border:1px solid #c8cdd5;border-radius:4px}
.fn{font-size:12px;margin-top:4px;direction:rtl}
.data{flex:1 1 480px;min-width:320px;overflow-x:auto}
.head{margin-bottom:10px}.items{width:100%;margin-bottom:8px}.tot{margin-top:6px}
.num,.code,.n{direction:ltr;text-align:left;white-space:nowrap;font-variant-numeric:tabular-nums}
.desc{min-width:160px}
tr.bad td{background:#fde8e6}.chk{color:#b3261e;white-space:nowrap;font-size:12px}
td.diff{background:#fff4c2}.alt{font-size:11px;color:#7a5b00;direction:ltr;text-align:left}
.ok{color:#1b7a3a;font-weight:600}.warn{color:#b3261e;font-weight:600}.nul{color:#8a93a0;font-weight:400}
.note{color:#44506a}.sum{margin:6px 0}.legend span{display:inline-block;padding:2px 8px;border-radius:4px;margin-inline-end:8px}
a{color:#1a56b0}
</style><h1>ניסוי: קריאת תעודות ספקים מתמונה שלמה</h1>
<p>' . $stats['pages'] . ' תמונות · ' . $stats['docs'] . ' תעודות · ' . $stats['in_list'] . ' זוהו כספק מהרשימה · ' . $stats['lines'] . ' שורות, מהן ' . $stats['bad_lines']
    . ' שבהן כמות×מחיר לא שווה לסה"כ השורה · סכום השורות שווה לסכום מודפס ב-' . $stats['sum_ok'] . ' מתוך ' . $stats['with_totals'] . ' תעודות עם סכומים · '
    . $stats['differ'] . ' תאים ששני המודלים קראו אחרת. נבנה ' . date('d/m/Y H:i') . '.</p>
<p class="legend"><span style="background:#fde8e6">שורה שהחשבון שלה לא מסתדר</span><span style="background:#fff4c2">תא שהמודל השני קרא אחרת (הערך שלו מתחת)</span> הבדיקות הן חשבון על מה שנקרא — הן לא יודעות מה באמת מודפס; את זה רק העין רואה.</p>
<table class="sumtab"><tr><th>#</th><th>שם הקובץ החדש</th><th>ספק ברשימה</th><th>סוג</th><th>מספר</th><th>תאריך</th><th>עמודים</th><th>שורות</th><th>סה"כ</th><th>חשבון שורות</th><th>סכום מול מודפס</th></tr>' . $summary . '</table>
<h2 style="margin-top:18px">מודלים</h2><table class="sumtab" style="width:auto"><tr><th>מודל</th><th>עמודים</th><th>עלות כוללת</th><th>עלות לעמוד</th><th>טוקנים לעמוד (קלט / פלט)</th><th>זמן ממוצע לעמוד</th></tr>' . $modelRows . '</table>'
    . $body . '</html>';
file_put_contents("$outDir/report.html", $html);
echo "\n" . json_encode($stats) . "\n";
foreach ($docs as $doc) echo '  ' . $doc['name'] . ' — ' . count($doc['pages']) . " page(s)\n";
echo "Report: preProcessDir/Trial/report.html\n";
