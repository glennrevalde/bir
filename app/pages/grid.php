<?php
Ctx::requirePerm('records.edit');
$client = Ctx::requireClient();
$key = in_array(input('m'), array('withholding', 'sales', 'purchases', 'withheld_on_us'), true) ? input('m') : 'withholding';
$mod = Records::module($key);

if (is_post()) {
    $rows = json_decode((string)input('rows'), true);
    if (!is_array($rows) || count($rows) > 500) {
        json_out(array('ok' => false, 'error' => 'Send between 1 and 500 rows.'));
    }
    $results = array();
    foreach ($rows as $r) {
        $rowNo = (int)$r['_row'];
        try {
            list($data, $errors) = Records::validate($mod, $r, $client['id']);
            if ($errors) {
                $results[] = array('row' => $rowNo, 'ok' => false, 'error' => implode('; ', $errors));
                continue;
            }
            $hash = Records::rowHash($mod, $data);
            // Purchases without an invoice number, and SAWT lines without a 2307 reference, may repeat
            // (the same amount twice in a month).
            $mayRepeat = !empty($mod['repeatable']) && empty($data[isset($mod['repeat_key']) ? $mod['repeat_key'] : 'invoice_no']);
            if (!$mayRepeat && DB::val('SELECT id FROM ' . $mod['table'] . ' WHERE client_id = ? AND row_hash = ? AND status = ?', array($client['id'], $hash, 'posted'))) {
                $results[] = array('row' => $rowNo, 'ok' => false, 'error' => 'An identical record already exists');
                continue;
            }
            $id = Records::save($mod, $data);
            $results[] = array('row' => $rowNo, 'ok' => true, 'id' => $id);
        } catch (RuntimeException $e) {
            $results[] = array('row' => $rowNo, 'ok' => false, 'error' => $e->getMessage());
        }
    }
    json_out(array('ok' => true, 'results' => $results));
}

$role = $key === 'sales' ? 'customer' : ($key === 'purchases' ? 'supplier' : ($key === 'withheld_on_us' ? 'payor' : 'payee'));
$parties = DB::all('SELECT id, tin, branch_code, registered_name, last_name, first_name, middle_name FROM counterparties WHERE client_id = ? AND deleted_at IS NULL AND status = ?
    ORDER BY is_' . $role . ' DESC, registered_name, last_name LIMIT 5000', array($client['id'], 'active'));
$branchOpts = '';
$ho = Ctx::headOffice();
foreach (Ctx::branches() as $b) {
    $branchOpts .= '<option value="' . (int)$b['id'] . '"' . ($ho && (int)$ho['id'] === (int)$b['id'] ? ' selected' : '') . '>' . e($b['branch_code']) . '</option>';
}
$cols = array();
foreach ($mod['fields'] as $f) {
    if (in_array($f['key'], array('description', 'nature_of_payment', 'rate_exception_note'), true)) {
        continue;
    }
    if (isset($mod['fixed'][$f['key']])) {
        continue;
    }
    $cols[] = $f;
}
View::header('Grid entry');
View::pageBar('Grid entry', View::clientContext('spreadsheet-style entry'),
    '<form method="get" class="btn-row"><input type="hidden" name="p" value="grid">' . View::select('m', array('withholding' => 'Taxes we withheld', 'withheld_on_us' => 'Taxes withheld on us', 'sales' => 'Sales', 'purchases' => 'Purchases'), $key, 'data-autosubmit aria-label="Record type"') . '</form>');
?>
<datalist id="cp-list"><?php foreach ($parties as $p) { $label = party_name($p) . ' | ' . ($p['tin'] ? Tin::display($p['tin'], $p['branch_code']) : 'no TIN'); ?><option value="<?= e($label) ?>" data-id="<?= (int)$p['id'] ?>"></option><?php } ?></datalist>
<?php if ($mod['table'] === 'withholding_entries') { ?><datalist id="atc-list"><?php foreach (Records::atcOptions() as $a) { ?><option value="<?= e($a['atc_code']) ?>"><?= e($a['tax_kind'] . ' ' . Money::rateFormat(Money::rateUnits($a['rate']), 'pct2') . '% ' . $a['description']) ?></option><?php } ?></datalist><?php } ?>
<div class="sheet">
  <div class="sheet-head"><div><h2><?= e($mod['title']) ?></h2><p><?= $key === 'sales' ? 'Columns follow the VAT sales workbook. Pick the customer by name or TIN, and leave output VAT blank to compute it from the taxable sales and rate. To start from gross amounts with VAT, use Add sales entry or the import.' : ($key === 'purchases' ? 'Columns follow the R_Purchases sheet. Pick the vendor by name or TIN, choose the month, and leave input VAT blank to compute it from the rate.' : ($key === 'withheld_on_us' ? 'Columns follow the SAWT. Pick the payor by name or TIN, choose the month, and leave rate and tax blank to take them from the ATC.' : 'Type a name or TIN in the party column and pick from the list. Enter moves down; Ctrl+Enter saves. Leave rate and tax blank to compute them from the ATC.')) ?></p></div>
    <div class="btn-row"><span class="small muted" data-grid-status></span><button type="button" class="btn" data-grid-add><?= View::icon('plus') ?>Add row</button><button type="button" class="btn btn-primary" data-grid-save>Save rows</button></div></div>
  <div class="table-wrap">
    <table class="table grid-entry" data-grid="<?= e(url('grid', array('m' => $key))) ?>">
      <thead><tr><?php foreach ($cols as $c) { ?><th class="<?= $c['type'] === 'amount' || $c['type'] === 'rate' ? 'num' : '' ?>"><?= e($c['type'] === 'counterparty' ? 'Party (name or TIN)' : $c['label']) ?></th><?php } ?><th>Result</th></tr></thead>
      <tbody></tbody>
    </table>
  </div>
</div>
<template id="grid-row-template"><tr>
<?php foreach ($cols as $c) {
    $k = $c['key'];
    echo '<td>';
    if ($c['type'] === 'branch') {
        echo '<select data-col="' . $k . '" aria-label="Branch">' . $branchOpts . '</select>';
    } elseif ($c['type'] === 'counterparty') {
        echo '<input data-col="party" list="cp-list" aria-label="Party" style="min-width:240px">';
    } elseif ($c['type'] === 'select') {
        echo str_replace('<select', '<select data-col="' . $k . '" aria-label="' . e($c['label']) . '"', View::select('', $c['options'], $k === 'schedule_tag' ? 'A' : 'EWT', ''));
    } elseif ($c['type'] === 'atc') {
        echo '<input data-col="atc_code" list="atc-list" aria-label="ATC" style="width:90px">';
    } elseif ($c['type'] === 'date') {
        echo '<input data-col="' . $k . '" type="date" aria-label="' . e($c['label']) . '">';
    } elseif ($c['type'] === 'month') {
        $dm = date('Y-m', strtotime('first day of last month'));
        echo '<input data-col="' . $k . '" type="month" value="' . e($dm) . '" data-default="' . e($dm) . '" aria-label="' . e($c['label']) . '">';
    } elseif ($c['type'] === 'amount' || $c['type'] === 'rate') {
        echo '<input data-col="' . $k . '" class="num" inputmode="decimal" aria-label="' . e($c['label']) . '"' . ($k === 'vat_rate' ? ' value="12" data-default="12"' : '') . ($k === 'input_vat' || $k === 'output_vat' || ($k === 'tax_withheld' && $key === 'withheld_on_us') ? ' placeholder="Auto"' : '') . ' style="width:' . ($c['type'] === 'rate' ? '70' : '120') . 'px">';
    } else {
        echo '<input data-col="' . $k . '" aria-label="' . e($c['label']) . '" style="width:120px">';
    }
    echo '</td>';
} ?>
<td><span class="msg"></span></td></tr></template>
<?php View::footer();
