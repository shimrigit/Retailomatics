<?php
// build_catalog_index.php — builds lib/CatalogIndex.php's index ahead of time
// (reading all the price lists takes a while; the bot would otherwise do it on
// the first WhatsApp message after a change).   php POAgent/tools/build_catalog_index.php [--all]
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}
require_once __DIR__ . '/../lib/CatalogIndex.php';

$t = microtime(true);
$read = in_array('--all', $argv, true) ? CatalogIndex::refresh() : (function () {
    CatalogIndex::suppliers();   // loads — reads only new / changed price lists
    return null;
})();
$items = array_sum(array_map([CatalogIndex::class, 'itemCount'], CatalogIndex::suppliers()));
printf("%d suppliers, %d items%s · %.1fs · %s\n", count(CatalogIndex::suppliers()), $items, $read !== null ? " ($read read)" : '',
    microtime(true) - $t, POAGENT_CATALOG_INDEX_FILE);
