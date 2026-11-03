<?php
// PriorityTasks.php — creating a POAgent PO in Priority (step 2 of the
// Priority connection: Playwright, replacing the step-1 instruction files for
// Claude in Chrome).
//
// priority_create.php's "צור הזמנת רכש בפריוריטי" button calls createPo(),
// which runs C:\RetailAgents\Priority\create-po.js (Node + Playwright) on this
// machine. The script attaches to a Chrome the user opened in connection mode
// (port 9222, start-chrome-priority.bat) and logged into Priority by hand —
// it never logs in. It opens a new purchase order, enters the supplier and
// the lines (מק"ט = the price list's ברקוד column, quantity, the PO's
// delivery date — Priority requires one), leaves it as
// טיוטא and never types prices: Priority fills them and differences are
// reported.
//
// The Priority order number (PO26..., not the POAgent PO#####) is saved into
// the PO's JSON (POStore::setPriorityInfo); a PO that has one is never sent
// again. Each run leaves <core>_<time>_order.json / _result.json / _shot.png /
// _log.txt in PriorityOutbox/ for debugging.
//
// Debugging period: manual button only. Later: run on PO approval and move the
// Priority status from טיוטא to נשלחה.

require_once __DIR__ . '/SupplierStore.php';
require_once __DIR__ . '/POStore.php';

define('POAGENT_PRIORITY_OUTBOX', __DIR__ . '/../PriorityOutbox');
define('POAGENT_PRIORITY_SCRIPT_DIR', 'C:/RetailAgents/Priority');
define('POAGENT_PRIORITY_SCRIPT', POAGENT_PRIORITY_SCRIPT_DIR . '/create-po.js');
define('POAGENT_NODE_EXE', 'C:/Program Files/nodejs/node.exe');
define('POAGENT_PRIORITY_TIMEOUT_S', 240);

class PriorityTasks
{
    /**
     * The order as create-po.js expects it. Throws RuntimeException with the
     * error code as message: NO_DELIVERY_DATE (the PO has none — Priority
     * requires one per line) or NO_SUPPLIER_NUMBER (not in the price list).
     */
    public static function buildOrder(array $po): array
    {
        if (empty($po['delivery_date'])) {
            throw new RuntimeException('NO_DELIVERY_DATE');
        }
        $supplierNo = SupplierStore::getPrioritySupplierNumber($po['supplier_id']);
        if ($supplierNo === null) {
            throw new RuntimeException('NO_SUPPLIER_NUMBER');
        }
        $delivery = date('d/m/y', strtotime($po['delivery_date'])); // as typed in Priority (6 digits)
        $lines = [];
        foreach ($po['items'] as $item) {
            $lines[] = [
                'sku'      => (string) $item['barcode'], // the Priority export's ברקוד column holds the מק"ט
                'name'     => $item['name'],
                'qty'      => (int) $item['qty'],
                'price'    => $item['unit_price_agorot'] / 100,
                'delivery' => $delivery,
            ];
        }
        return [
            'poagentPo'    => $po['unique_id'],
            'coreName'     => $po['core_name'],
            'supplier'     => $supplierNo,
            'supplierName' => $po['supplier_id'],
            'lines'        => $lines,
        ];
    }

    /**
     * Runs create-po.js for one PO and records the outcome in the PO.
     * Returns ['ok' => bool, 'code' => string, 'message' => string,
     *          'result' => array|null, 'shot' => path|null, 'log' => string].
     * code: OK, or the script's errorCode (CHROME_NOT_RUNNING,
     * PRIORITY_NOT_OPEN, PRIORITY_MANY_TABS, PRIORITY_NOT_LOGGED_IN, STOPPED,
     * ERROR), or BUSY / NOT_FOUND / ALREADY_CREATED / NO_DELIVERY_DATE /
     * NO_SUPPLIER_NUMBER / TIMEOUT / SCRIPT_FAILED.
     * $retryPartial: allow a new attempt when an earlier one stopped half-way
     * (that partial Priority order stays and is kept in the PO's history).
     */
    public static function createPo(string $coreName, bool $retryPartial = false): array
    {
        $fail = fn(string $code, string $message = '') =>
            ['ok' => false, 'code' => $code, 'message' => $message, 'result' => null, 'shot' => null, 'log' => ''];

        if (!is_dir(POAGENT_PRIORITY_OUTBOX)) {
            mkdir(POAGENT_PRIORITY_OUTBOX, 0777, true);
        }
        // One Priority run at a time — the script drives the single Priority tab.
        $lock = fopen(POAGENT_PRIORITY_OUTBOX . '/.run.lock', 'c');
        if (!$lock || !flock($lock, LOCK_EX | LOCK_NB)) {
            return $fail('BUSY');
        }

        try {
            $po = POStore::loadByCoreName($coreName);
            if (!$po) {
                return $fail('NOT_FOUND');
            }
            $previous = $po['priority'] ?? null;
            if (!empty($previous['po_number']) && (!empty($previous['complete']) || !$retryPartial)) {
                return $fail('ALREADY_CREATED', $previous['po_number']);
            }
            try {
                $order = self::buildOrder($po);
            } catch (RuntimeException $e) {
                return $fail($e->getMessage());
            }

            $base = POAGENT_PRIORITY_OUTBOX . '/' . $coreName . '_' . date('ymd-His');
            $orderPath = "{$base}_order.json";
            $resultPath = "{$base}_result.json";
            $shotPath = "{$base}_shot.png";
            $logPath = "{$base}_log.txt";
            poagent_write_json_atomic($orderPath, $order);

            set_time_limit(POAGENT_PRIORITY_TIMEOUT_S + 30);
            $proc = proc_open(
                [POAGENT_NODE_EXE, POAGENT_PRIORITY_SCRIPT, $orderPath, '--result', $resultPath, '--shot', $shotPath],
                [0 => ['pipe', 'r'], 1 => ['file', $logPath, 'w'], 2 => ['redirect', 1]],
                $pipes,
                POAGENT_PRIORITY_SCRIPT_DIR
            );
            if (!is_resource($proc)) {
                return $fail('SCRIPT_FAILED', 'Could not start node');
            }
            fclose($pipes[0]);
            $deadline = time() + POAGENT_PRIORITY_TIMEOUT_S;
            $timedOut = false;
            while (proc_get_status($proc)['running']) {
                if (time() > $deadline) {
                    proc_terminate($proc);
                    $timedOut = true;
                    break;
                }
                usleep(300000);
            }
            proc_close($proc);

            $log = is_file($logPath) ? (string) file_get_contents($logPath) : '';
            $result = is_file($resultPath) ? json_decode((string) file_get_contents($resultPath), true) : null;
            $shot = is_file($shotPath) ? $shotPath : null;

            // Whatever happened, if Priority gave an order number, record it —
            // a partial order exists there and must not be created again blindly.
            $priorityPo = $result['header']['poNumber'] ?? null;
            if ($priorityPo) {
                $info = [
                    'po_number'         => $priorityPo,
                    'complete'          => !empty($result['ok']),
                    'status'            => $result['totals']['status'] ?? ($result['header']['status'] ?? null),
                    'supplier_number'   => $order['supplier'],
                    'supplier_name'     => $result['header']['supplierName'] ?? null,
                    'total_before_vat'  => $result['totals']['totalBeforeDiscount'] ?? null,
                    'price_differences' => self::priceDifferences($result['lines'] ?? []),
                    // Lines whose מק"ט Priority doesn't have — left out of the
                    // Priority order (the run goes on without them).
                    'skipped_lines'     => array_map(fn($s) => [
                        'sku'    => $s['sku'],
                        'name'   => $s['name'] ?? '',
                        'reason' => $s['reason'] ?? '',
                    ], $result['skipped'] ?? []),
                    'error'             => !empty($result['ok']) ? null : ($result['error'] ?? 'unknown'),
                    'created_at'        => date('c'),
                    'run'               => basename($base),
                ];
                if ($previous) {
                    $info['earlier_attempts'] = array_merge($previous['earlier_attempts'] ?? [], [array_diff_key($previous, ['earlier_attempts' => 1])]);
                }
                POStore::setPriorityInfo($coreName, $info);
            }

            if ($timedOut) {
                return ['ok' => false, 'code' => 'TIMEOUT', 'message' => '', 'result' => $result, 'shot' => $shot, 'log' => $log];
            }
            if (!$result) {
                return ['ok' => false, 'code' => 'SCRIPT_FAILED', 'message' => '', 'result' => null, 'shot' => $shot, 'log' => $log];
            }
            return [
                'ok'      => !empty($result['ok']),
                'code'    => !empty($result['ok']) ? 'OK' : ($result['errorCode'] ?? 'ERROR'),
                'message' => $result['error'] ?? '',
                'result'  => $result,
                'shot'    => $shot,
                'log'     => $log,
            ];
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    /**
     * "סמן כהושלמה ידנית בפריוריטי": the user finished the order by hand in
     * Priority (a partial run, or lines skipped as not in Priority). Records
     * who, when and what they changed — from here on the Priority order may
     * differ from the POAgent PO (lines added/removed/changed by hand), which
     * matters when comparing delivery notes (VS) and for future automation
     * (status נשלחה, goods reception), which must go by the Priority order.
     */
    public static function markCompletedManually(string $coreName, string $by, string $note): bool
    {
        $po = POStore::loadByCoreName($coreName);
        $priority = $po['priority'] ?? null;
        if (empty($priority['po_number'])) {
            return false;
        }
        $priority['manual'] = [
            'by'   => $by,
            'at'   => date('c'),
            'note' => mb_substr(trim($note), 0, 1000),
            // what the state was when marked: 'partial' or 'missing'
            'was'  => empty($priority['complete']) ? 'partial' : 'missing',
        ];
        $priority['complete'] = true;
        POStore::setPriorityInfo($coreName, $priority);
        return true;
    }

    /** Lines whose Priority price differs from the POAgent price (nothing is changed in Priority). */
    private static function priceDifferences(array $lines): array
    {
        $diffs = [];
        foreach ($lines as $line) {
            if (!empty($line['priceDiffers'])) {
                $diffs[] = [
                    'sku'            => $line['sku'],
                    'name'           => $line['description'] ?? '',
                    'poagent_price'  => $line['orderPrice'],
                    'priority_price' => $line['priorityPrice'],
                ];
            }
        }
        return $diffs;
    }
}
