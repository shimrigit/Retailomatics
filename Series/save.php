<?php
// Saves the decisions of one series card (view.php): the sub-series letter or
// "לא מסדרה", and the fixed price, per barcode; rewrites price_fixes.json.
ini_set('memory_limit', '1G');
require_once __DIR__ . '/common.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header('Location: index.php'); exit; }
$shop = seriesRequestShop();
$data = seriesLoad($shop);
$base = (int) ($_POST['base'] ?? 0);
$show = preg_replace('/[^a-z]/', '', (string) ($_POST['show'] ?? 'conflicts'));
$series = null;
foreach ($data['result']['series'] ?? [] as $s) if ((int) $s['id'] === $base) { $series = $s; break; }
if (!$series) { header('Location: view.php?shop=' . urlencode($shop)); exit; }

$assign = (array) ($_POST['assign'] ?? []);
$fix    = (array) ($_POST['fix'] ?? []);
$errors = [];
foreach ($series['items'] as $it) {
    $bc = $it['barcode'];
    $d  = $data['decisions'][$bc] ?? [];
    $a  = (string) ($assign[$bc] ?? '');
    $d['assign'] = in_array($a, SERIES_LETTERS, true) || $a === 'none' ? $a : '';
    $f = trim(str_replace(',', '.', (string) ($fix[$bc] ?? '')));
    if ($f === '' || abs((float) $f - $it['price']) < 0.001) {
        unset($d['fixPrice'], $d['fixAt']);
    } elseif (is_numeric($f) && (float) $f > 0) {
        if (!isset($d['fixPrice']) || abs($d['fixPrice'] - (float) $f) > 0.001) $d['fixAt'] = date('Y-m-d H:i:s');
        $d['fixPrice'] = round((float) $f, 2);
    } else {
        $errors[] = $it['name'] . ': "' . $f . '" אינו מחיר';
    }
    if ($d['assign'] === '' && !isset($d['fixPrice']) && !isset($d['rename'])) unset($data['decisions'][$bc]);
    else $data['decisions'][$bc] = $d;
}
seriesSave($shop, $data);
seriesWritePriceFixes($shop, $data);
header('Location: view.php?shop=' . urlencode($shop) . '&show=' . urlencode($show) . ($errors ? '&error=' . urlencode(implode(' · ', $errors)) : '') . '#s' . $base);
