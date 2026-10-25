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
    $extra = isset($mod['fields'][2]) && $mod['fields'][2]['type'] === 'text' ? ' OR t.' . $mod['fields'][2]['key'] . ' LIKE ?' : '';
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
$from = ' FROM ' . $t . ' t JOIN counterparties cp ON cp.id = t.counterparty_id JOIN branches b ON b.id = t.branch_id WHERE ' . $sqlWhere;

if (input('export') === 'csv') {
    $rows = DB::all('SELECT t.*, cp.tin, cp.branch_code AS cp_branch, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, b.branch_code' . $from . ' ORDER BY t.' . $mod['date'] . ', t.id', $p);
    $fh = fopen('php://temp', 'w+');
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
    . ' ORDER BY t.' . $mod['date'] . ' DESC, t.id DESC LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
$sumCols = array();
foreach ($mod['totals'] as $k) {
    $sumCols[] = 'SUM(t.' . $k . ') AS ' . $k;
}
$sums = DB::row('SELECT ' . implode(', ', $sumCols) . $from, $p);
$labels = array();
foreach ($mod['fields'] as $fd) {
    $labels[$fd['key']] = $fd;
}
$locked = $f['month'] !== '' ? Records::lockedPeriod($cid, $f['month'], $mod['lock_scope']) : null;

$actions = '';
if (Ctx::can('records.edit')) {
    $actions .= '<a class="btn btn-primary" href="' . e(url('record_form', array('m' => $moduleKey))) . '">' . View::icon('plus') . 'Add ' . e($mod['singular']) . '</a>';
}
$actions .= '<a class="btn" href="' . e(url($moduleKey, array_merge($f, array('export' => 'csv')))) . '">' . View::icon('download') . 'CSV</a>';
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
        <?php foreach ($mod['list'] as $k) { $fd = $labels[$k]; ?><th class="<?= $fd['type'] === 'amount' ? 'num' : '' ?>"><?= e($fd['label']) ?></th><?php } ?>
        <th>Branch</th><th>Source</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r) { ?>
        <tr class="<?= $r['status'] === 'void' ? 'void' : '' ?>">
          <?php if (Ctx::can('records.bulk')) { ?><td data-label="Select"><?php if ($r['status'] !== 'void') { ?><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select"><?php } ?></td><?php } ?>
          <?php foreach ($mod['list'] as $k) { $fd = $labels[$k]; ?>
            <td data-label="<?= e($fd['label']) ?>" class="<?= $fd['type'] === 'amount' ? 'num' : '' ?>"><?php
              if ($fd['type'] === 'counterparty') {
                  echo '<strong>' . e(party_name($r)) . '</strong><span class="sub">' . e(Tin::display($r['tin'], $r['cp_branch'])) . '</span>';
              } elseif ($fd['type'] === 'amount') {
                  echo Money::fmtDb($r[$k]);
              } elseif ($fd['type'] === 'date') {
                  echo e(fmt_date($r[$k], 'm/d/Y'));
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
          <td class="<?= $fd['type'] === 'amount' ? 'num' : '' ?>" data-label="<?= e($fd['label']) ?>"><?= $fd['type'] === 'amount' ? Money::fmtDb(isset($sums[$k]) ? $sums[$k] : 0) : ($i === 0 ? 'Totals, all pages' : '') ?></td>
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
