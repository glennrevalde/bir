<?php
/** Shared list screen for record modules. Expects $moduleKey. */
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$mod = Records::module($moduleKey);
$t = $mod['table'];
$cid = (int)$client['id'];

// ---------------------------------------------------------------- bulk void
if (is_post() && input('action') === 'bulk_void') {
    Ctx::requirePerm('records.bulk');
    $ids = array_map('intval', (array)input('ids', array()));
    $reason = trim((string)input('reason'));
    if (!$ids || $reason === '') {
        flash('error', 'Select records and give a reason.');
    } else {
        $done = 0;
        $fail = array();
        foreach ($ids as $id) {
            try {
                Records::setVoid($mod, $id, true, 'Bulk: ' . $reason);
                $done++;
            } catch (RuntimeException $e) {
                $fail[] = '#' . $id . ': ' . $e->getMessage();
            }
        }
        flash($fail ? 'warning' : 'success', 'Voided ' . $done . ' records.' . ($fail ? ' Skipped: ' . implode('; ', array_slice($fail, 0, 3)) : ''));
    }
    redirect(url($moduleKey, array_intersect_key($_GET, array_flip(array('month', 'branch', 'q', 'status', 'kind')))));
}

$f = array('month' => (string)input('month', ''), 'branch' => input_int('branch'), 'q' => trim((string)input('q', '')), 'status' => (string)input('status', 'posted'), 'kind' => (string)input('kind', ''));
$where = array('t.client_id = ?');
$p = array($cid);
if (!empty($mod['fixed']['direction'])) {
    $where[] = 't.direction = ?';
    $p[] = $mod['fixed']['direction'];
}
if ($f['month'] !== '' && preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
    $where[] = 't.period_month = ?';
    $p[] = $f['month'];
}
if ($f['branch']) {
    $where[] = 't.branch_id = ?';
    $p[] = $f['branch'];
}
$scope = Ctx::branchScope();
if ($scope !== null) {
    $where[] = 't.branch_id IN (' . DB::in($scope) . ')';
    $p = array_merge($p, $scope ?: array(0));
}
if (in_array($f['status'], array('posted', 'void'), true)) {
    $where[] = 't.status = ?';
    $p[] = $f['status'];
}
if ($f['kind'] !== '' && $t === 'withholding_entries') {
    $where[] = 't.tax_kind = ?';
    $p[] = $f['kind'];
}
if ($f['q'] !== '') {
    $like = '%' . $f['q'] . '%';
    $digits = preg_replace('/\D/', '', $f['q']);
    $extra = '';
    foreach ($mod['fields'] as $fd) {
        if ($fd['type'] === 'text' && in_array($fd['key'], array('invoice_no', 'import_entry_no', 'reference_no'), true)) {
            $extra = ' OR t.' . $fd['key'] . ' LIKE ?';
            break;
        }
    }
    $where[] = '(cp.registered_name LIKE ? OR cp.last_name LIKE ? OR cp.first_name LIKE ?' . (strlen($digits) >= 3 ? ' OR cp.tin LIKE ?' : '') . $extra . ($t === 'withholding_entries' ? ' OR t.atc_code LIKE ?' : '') . ')';
    array_push($p, $like, $like, $like);
    if (strlen($digits) >= 3) {
        $p[] = '%' . $digits . '%';
    }
    if ($extra) {
        $p[] = $like;
    }
    if ($t === 'withholding_entries') {
        $p[] = $like;
    }
}
$sqlWhere = implode(' AND ', $where);
$sortKey = isset($mod['sort']) ? $mod['sort'] : $mod['date'];
$from = ' FROM ' . $t . ' t JOIN counterparties cp ON cp.id = t.counterparty_id JOIN branches b ON b.id = t.branch_id WHERE ' . $sqlWhere;

if (input('export') === 'sawt' && $moduleKey === 'withheld_on_us') {
    // The month in the SAWT printout layout: title lines, header (1) to (8), one line per payor and ATC, Grand Total.
    if ($f['month'] === '' || !preg_match('/^\d{4}-\d{2}$/', $f['month'])) {
        flash('info', 'Choose a month first, then export it in the SAWT layout.');
        redirect(url($moduleKey, array_intersect_key($f, array_flip(array('branch', 'q', 'status')))));
    }
    $lines = Imports::sawtLines($cid, $f['branch'] ?: null, $f['month']);
    Audit::log('record.export', $t, null, 'Exported ' . count($lines) . ' SAWT lines for ' . $f['month']);
    send_bytes(Imports::sawtWorkbook($client, null, $f['month'], $lines, false), 'SAWT-' . $client['tin'] . '-' . str_replace('-', '', $f['month']) . '.xlsx',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
if (input('export') === 'csv') {
    $rows = DB::all('SELECT t.*, cp.tin, cp.branch_code AS cp_branch, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, cp.address1 AS cp_address1, cp.address2 AS cp_address2, b.branch_code' . $from . ' ORDER BY t.' . $sortKey . ', t.id', $p);
    $fh = fopen('php://temp', 'w+');
    if ($moduleKey === 'purchases') {
        // Same 16 columns as the R_Purchases workbook, then the month, branch and reference columns.
        fputcsv($fh, array('Vendor_TIN', 'companyName', 'lastName', 'firstName', 'middleName', 'address1', 'address2', 'exempt', 'zeroRated', 'services', 'capitalGoods',
            'otherThancapitalGoods', 'taxableNetofVat', 'vatRate', 'inputVat', 'totalPurchases', 'month', 'branch', 'invoiceNo', 'invoiceDate', 'nonCreditableVat', 'status', 'id'));
        foreach ($rows as $r) {
            fputcsv($fh, array($r['tin'], $r['registered_name'], $r['last_name'], $r['first_name'], $r['middle_name'], $r['cp_address1'], $r['cp_address2'],
                $r['exempt_amount'], $r['zero_rated_amount'], $r['services_amount'], $r['capital_goods_amount'], $r['other_goods_amount'],
                Money::toPlain(Records::purchaseTaxable($r)), rtrim(rtrim($r['vat_rate'], '0'), '.'), $r['input_vat'], Money::toPlain(Records::purchaseTotal($r)),
                $r['period_month'], $r['branch_code'], $r['invoice_no'], $r['invoice_date'], $r['non_creditable_vat'], $r['status'], $r['id']));
        }
        rewind($fh);
        Audit::log('record.export', $t, null, 'Exported ' . count($rows) . ' ' . $mod['title'] . ' rows to CSV');
        send_bytes("\xEF\xBB\xBF" . stream_get_contents($fh), 'R_Purchases-' . $client['tin'] . '-' . date('Ymd') . '.csv', 'text/csv');
    }
    if ($moduleKey === 'sales') {
        // The 17 columns of the VAT sales workbook, then the description, month and status.
        fputcsv($fh, array('CUSTOMER TIN', 'CUSTOMER BRANCH CODE', 'REGISTERED NAME', 'LAST NAME', 'FIRST NAME', 'MIDDLE NAME', 'SUBSTREET, STREET, BARANGAY', 'DISTRICT, CITY, ZIP CODE',
            'CLIENT BRANCH CODE', 'INVOICE NO', 'INVOICE DATE', 'GROSS TAXABLE', 'EXEMPT SALES', 'ZERO RATED SALES', 'TAXABLE SALES', 'VAT RATE', 'OUTPUT VAT', 'DESCRIPTION', 'MONTH', 'STATUS', 'ID'));
        foreach ($rows as $r) {
            fputcsv($fh, array($r['tin'] ? Tin::display($r['tin']) : '', $r['cp_branch'], $r['registered_name'], $r['last_name'], $r['first_name'], $r['middle_name'], $r['cp_address1'], $r['cp_address2'],
                $r['branch_code'], $r['invoice_no'], $r['invoice_date'] ? date('m/d/Y', strtotime($r['invoice_date'])) : '', Money::toPlain(Records::salesGross($r)), $r['exempt_amount'], $r['zero_rated_amount'],
                $r['taxable_amount'], rtrim(rtrim($r['vat_rate'], '0'), '.'), $r['output_vat'], $r['description'], $r['period_month'], $r['status'], $r['id']));
        }
        rewind($fh);
        Audit::log('record.export', $t, null, 'Exported ' . count($rows) . ' ' . $mod['title'] . ' rows to CSV');
        send_bytes("\xEF\xBB\xBF" . stream_get_contents($fh), 'VAT-Sales-' . $client['tin'] . '-' . date('Ymd') . '.csv', 'text/csv');
    }
    if ($moduleKey === 'withheld_on_us') {
        // The SAWT columns (1) to (8), then the month and the reference columns.
        fputcsv($fh, array('SEQ NO', 'TAXPAYER IDENTIFICATION NUMBER', 'CORPORATION (Registered Name)', 'INDIVIDUAL (Last Name, First Name, Middle Name)', 'ATC CODE',
            'NATURE OF PAYMENT', 'AMOUNT OF INCOME PAYMENT', 'TAX RATE', 'AMOUNT OF TAX WITHHELD', 'month', 'branch', 'paymentDate', 'certificateReference', 'note', 'status', 'id'));
        foreach ($rows as $i => $r) {
            $ind = $r['registered_name'] ? '' : trim($r['last_name'] . ', ' . $r['first_name'] . ($r['middle_name'] ? ', ' . $r['middle_name'] : ''), ', ');
            fputcsv($fh, array($i + 1, $r['tin'] ? Tin::display($r['tin'], $r['cp_branch']) : '', (string)$r['registered_name'], $ind, $r['atc_code'], $r['nature_of_payment'],
                $r['income_amount'], Money::rateFormat(Money::rateUnits($r['tax_rate']), 'pct2'), $r['tax_withheld'], $r['period_month'], $r['branch_code'], $r['payment_date'],
                $r['reference_no'], $r['rate_exception_note'], $r['status'], $r['id']));
        }
        rewind($fh);
        Audit::log('record.export', $t, null, 'Exported ' . count($rows) . ' ' . $mod['title'] . ' rows to CSV');
        send_bytes("\xEF\xBB\xBF" . stream_get_contents($fh), 'SAWT-lines-' . $client['tin'] . '-' . date('Ymd') . '.csv', 'text/csv');
    }
    $cols = array('id', 'branch_code', 'tin', 'cp_branch', 'registered_name', 'last_name', 'first_name', 'middle_name');
    foreach ($mod['fields'] as $fd) {
        if (!in_array($fd['type'], array('branch', 'counterparty'), true)) {
            $cols[] = $fd['key'];
        }
    }
    $cols = array_merge($cols, array('period_month', 'status', 'source'));
    fputcsv($fh, $cols);
    foreach ($rows as $r) {
        $line = array();
        foreach ($cols as $c) {
            $line[] = isset($r[$c]) ? $r[$c] : '';
        }
        fputcsv($fh, $line);
    }
    rewind($fh);
    Audit::log('record.export', $t, null, 'Exported ' . count($rows) . ' ' . $mod['title'] . ' rows to CSV');
    send_bytes("\xEF\xBB\xBF" . stream_get_contents($fh), $moduleKey . '-' . $client['tin'] . '-' . date('Ymd') . '.csv', 'text/csv');
}

$total = (int)DB::val('SELECT COUNT(*)' . $from, $p);
$per = 50;
$page = max(1, input_int('page', 1));
$rows = DB::all('SELECT t.*, cp.tin, cp.branch_code AS cp_branch, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, cp.entity_type, b.branch_code' . $from
    . ' ORDER BY t.' . $sortKey . ' DESC, t.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$sumCols = array();
foreach ($mod['totals'] as $k) {
    $sumCols[] = 'SUM(t.' . $k . ') AS ' . $k;
}
$sums = DB::row('SELECT ' . implode(', ', $sumCols) . $from, $p);
$labels = array();
foreach ($mod['fields'] as $fd) {
    $labels[$fd['key']] = $fd;
}
foreach (isset($mod['computed']) ? $mod['computed'] : array() as $ck => $cd) {
    $labels[$ck] = array('key' => $ck, 'label' => $cd['label'], 'type' => 'amount', 'sum' => $cd['sum']);
}
$cell = function ($r, $k) use ($labels) {
    $fd = $labels[$k];
    if (isset($fd['sum'])) {
        $c = 0;
        foreach ($fd['sum'] as $part) {
            $c += Money::fromDb(isset($r[$part]) ? $r[$part] : 0);
        }
        return $c;
    }
    return Money::fromDb(isset($r[$k]) ? $r[$k] : 0);
};
$locked = $f['month'] !== '' ? Records::lockedPeriod($cid, $f['month'], $mod['lock_scope']) : null;

$actions = '';
if (Ctx::can('records.edit')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('record_form', array('m' => $moduleKey))) . '">' . View::icon('plus') . 'Add ' . e($mod['singular']) . '</a>';
}
$actions .= '<a class="btn" href="' . e(url($moduleKey, array_merge($f, array('export' => 'csv')))) . '">' . View::icon('download') . 'CSV</a>';
if ($moduleKey === 'withheld_on_us') {
    $actions .= '<a class="btn" href="' . e(url($moduleKey, array_merge($f, array('export' => 'sawt')))) . '"' . ($f['month'] === '' ? ' title="Choose a month in the filters first"' : '') . '>' . View::icon('sheet') . 'SAWT layout</a>';
}
View::header($mod['title'], array('page' => $moduleKey));
View::pageBar($mod['title'], View::clientContext(e($mod['help'])), $actions);
?>
<?php if ($locked) { ?><div class="notice notice-warning"><?= View::icon('lock') ?> <?= e(month_label($f['month'])) ?> is locked: <?= e($locked['reason']) ?></div><?php } ?>
<div class="sheet">
  <form class="filters" method="get">
    <input type="hidden" name="p" value="<?= e($moduleKey) ?>">
    <div class="field"><label for="f-month">Month</label><input id="f-month" type="month" name="month" value="<?= e($f['month']) ?>"></div>
    <div class="field"><label for="f-branch">Branch</label><select id="f-branch" name="branch"><option value="">All branches</option>
      <?php foreach (Ctx::branches() as $b) { ?><option value="<?= (int)$b['id'] ?>" <?= $f['branch'] === (int)$b['id'] ? 'selected' : '' ?>><?= e($b['branch_code'] . ' ' . $b['name']) ?></option><?php } ?></select></div>
    <?php if ($t === 'withholding_entries' && $moduleKey === 'withholding') { ?>
      <div class="field"><label for="f-kind">Kind</label><?= View::select('kind', $labels['tax_kind']['options'], $f['kind'], 'id="f-kind"', 'All kinds') ?></div>
    <?php } ?>
    <div class="field"><label for="f-status">Status</label><?= View::select('status', array('posted' => 'Posted', 'void' => 'Void', 'all' => 'All'), $f['status'], 'id="f-status"') ?></div>
    <div class="field grow"><label for="f-q">Name, TIN or number</label><input id="f-q" type="search" name="q" value="<?= e($f['q']) ?>"></div>
    <button class="btn">Filter</button>
  </form>
  <?php if (!$rows) { ?>
    <?= View::empty_state('No ' . strtolower($mod['title']) . ' match', 'Change the filters, add a record, or import a spreadsheet.',
        Ctx::can('imports.run') ? '<a class="btn" href="' . e(url('imports')) . '">Import a spreadsheet</a>' : '') ?>
  <?php } else { ?>
  <form method="post" data-prompt="<?= Ctx::can('records.bulk') ? 'Reason for voiding the selected records' : '' ?>">
    <?= csrf_field() ?><input type="hidden" name="action" value="bulk_void">
    <div class="table-wrap"><table class="table table-stack">
      <thead><tr>
        <?php if (Ctx::can('records.bulk')) { ?><th><input type="checkbox" data-check-all="ids[]" aria-label="Select all"></th><?php } ?>
        <?php foreach ($mod['list'] as $k) { $fd = $labels[$k]; ?><th class="<?= $fd['type'] === 'amount' || $fd['type'] === 'rate' ? 'num' : '' ?>"><?= e($fd['label']) ?></th><?php } ?>
        <th>Branch</th><th>Source</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr class="<?= $r['status'] === 'void' ? 'void' : '' ?>">
          <?php if (Ctx::can('records.bulk')) { ?><td data-label="Select"><?php if ($r['status'] !== 'void') { ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select"><?php } ?></td><?php } ?>
          <?php foreach ($mod['list'] as $k) { $fd = $labels[$k]; ?>
            <td data-label="<?= e($fd['label']) ?>" class="<?= $fd['type'] === 'amount' || $fd['type'] === 'rate' ? 'num' : '' ?>"><?php
              if ($fd['type'] === 'counterparty') {
                  echo '<strong>' . e(party_name($r)) . '</strong><span class="sub">' . e(Tin::display($r['tin'], $r['cp_branch'])) . '</span>';
              } elseif ($fd['type'] === 'amount') {
                  echo Money::fmt($cell($r, $k));
              } elseif ($fd['type'] === 'month') {
                  echo e(month_label($r[$k]));
              } elseif ($fd['type'] === 'date') {
                  echo e(fmt_date($r[$k], 'm/d/Y'));
              } elseif ($fd['type'] === 'rate') {
                  echo $r[$k] === null || $r[$k] === '' ? '' : e(Money::rateFormat((int)Money::rateUnits($r[$k]), 'pct2'));
              } elseif ($k === 'nature_of_payment') {
                  echo '<span class="clamp-2 small" title="' . e($r[$k]) . '">' . e($r[$k]) . '</span>';
              } elseif ($fd['type'] === 'select') {
                  echo e($r[$k]);
              } else {
                  echo e($r[$k]);
              } ?></td>
          <?php } ?>
          <td data-label="Branch"><?= e($r['branch_code']) ?></td>
          <td data-label="Source" class="small muted"><?= e($r['source']) ?><?= $r['status'] === 'void' ? '<span class="sub">' . e($r['void_reason']) . '</span>' : '' ?></td>
          <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('record_form', array('m' => $moduleKey, 'id' => $r['id']))) ?>">Open</a></td>
        </tr>
      <?php } ?>
      </tbody>
      <tfoot><tr>
        <?php if (Ctx::can('records.bulk')) { ?><td></td><?php } ?>
        <?php foreach ($mod['list'] as $i => $k) { $fd = $labels[$k]; ?>
          <td class="<?= $fd['type'] === 'amount' ? 'num' : '' ?>" data-label="<?= e($fd['label']) ?>"><?= $fd['type'] === 'amount' ? Money::fmt($cell($sums ?: array(), $k)) : ($i === 0 ? 'Totals, all pages' : '') ?></td>
        <?php } ?>
        <td></td><td></td><td></td>
      </tr></tfoot>
    </table></div>
    <div class="sheet-foot" style="justify-content:space-between">
      <?php if (Ctx::can('records.bulk')) { ?><button class="btn btn-danger btn-sm" type="submit">Void selected</button><?php } else { echo '<span></span>'; } ?>
      <?= View::pager($total, $page, $per, $f) ?>
    </div>
  </form>
  <?php } ?>
</div>
<?php View::footer();
