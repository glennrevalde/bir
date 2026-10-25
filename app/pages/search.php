<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$q = trim((string)input('q', ''));
$digits = preg_replace('/\D/', '', $q);
$res = array('cp' => array(), 'emp' => array(), 'files' => array(), 'inv' => array());
if ($q !== '') {
    $like = '%' . $q . '%';
    $tinLike = strlen($digits) >= 3 ? '%' . substr($digits, 0, 9) . '%' : '__none__';
    $res['cp'] = DB::all('SELECT * FROM counterparties WHERE client_id = ? AND deleted_at IS NULL AND (tin LIKE ? OR registered_name LIKE ? OR last_name LIKE ? OR first_name LIKE ?) ORDER BY registered_name, last_name LIMIT 30',
        array($client['id'], $tinLike, $like, $like, $like));
    $res['emp'] = DB::all('SELECT * FROM employees WHERE client_id = ? AND deleted_at IS NULL AND (tin LIKE ? OR last_name LIKE ? OR first_name LIKE ? OR employee_no LIKE ?) LIMIT 30',
        array($client['id'], $tinLike, $like, $like, $like));
    $res['files'] = DB::all('SELECT * FROM dat_files WHERE client_id = ? AND filename LIKE ? ORDER BY id DESC LIMIT 30', array($client['id'], $like));
    foreach (array('sales_entries' => 'sales', 'purchase_entries' => 'purchases') as $t => $m) {
        foreach (DB::all('SELECT t.id, t.invoice_no, t.invoice_date, t.status FROM ' . $t . ' t WHERE t.client_id = ? AND t.invoice_no LIKE ? LIMIT 15', array($client['id'], $like)) as $r) {
            $r['module'] = $m;
            $res['inv'][] = $r;
        }
    }
}
View::header('Search');
View::pageBar('Search', View::clientContext($q !== '' ? 'results for "' . e($q) . '"' : ''));
?>
<div class="sheet"><form class="filters" method="get"><input type="hidden" name="p" value="search">
  <div class="field grow"><label for="sq">TIN, name, invoice number or filename</label><input id="sq" name="q" type="search" value="<?= e($q) ?>" autofocus></div>
  <button class="btn btn-primary">Search</button></form>
  <?php if ($q === '') { ?><div class="empty"><p>Type at least part of a TIN or name.</p></div><?php } ?>
</div>
<?php if ($q !== '') { ?>
<div class="cols cols-2">
  <div class="sheet"><div class="sheet-head"><h2>Directory (<?= count($res['cp']) ?>)</h2></div>
    <ul class="queue"><?php foreach ($res['cp'] as $c) { ?><li><div class="q-main"><a href="<?= e(url('cp_form', array('id' => $c['id']))) ?>"><?= e(party_name($c)) ?></a><div class="q-sub"><?= e(Tin::display($c['tin'], $c['branch_code'])) ?></div></div></li><?php } ?>
    <?php if (!$res['cp']) { ?><li class="muted">No matches</li><?php } ?></ul></div>
  <div class="sheet"><div class="sheet-head"><h2>Employees (<?= count($res['emp']) ?>)</h2></div>
    <ul class="queue"><?php foreach ($res['emp'] as $c) { ?><li><div class="q-main"><a href="<?= e(url('employee_form', array('id' => $c['id']))) ?>"><?= e($c['last_name'] . ', ' . $c['first_name']) ?></a><div class="q-sub"><?= e(Tin::display($c['tin'])) ?></div></div></li><?php } ?>
    <?php if (!$res['emp']) { ?><li class="muted">No matches</li><?php } ?></ul></div>
  <div class="sheet"><div class="sheet-head"><h2>Files (<?= count($res['files']) ?>)</h2></div>
    <ul class="queue"><?php foreach ($res['files'] as $f) { ?><li><div class="q-main"><a href="<?= e(url('file_view', array('id' => $f['id']))) ?>"><?= e($f['filename']) ?></a><div class="q-sub"><?= e($f['period_label']) ?> <?= View::status($f['status']) ?></div></div></li><?php } ?>
    <?php if (!$res['files']) { ?><li class="muted">No matches</li><?php } ?></ul></div>
  <div class="sheet"><div class="sheet-head"><h2>Invoices (<?= count($res['inv']) ?>)</h2></div>
    <ul class="queue"><?php foreach ($res['inv'] as $r) { ?><li><div class="q-main"><a href="<?= e(url('record_form', array('m' => $r['module'], 'id' => $r['id']))) ?>"><?= e($r['invoice_no']) ?></a><div class="q-sub"><?= e(ucfirst($r['module'])) ?>, <?= e(fmt_date($r['invoice_date'])) ?></div></div></li><?php } ?>
    <?php if (!$res['inv']) { ?><li class="muted">No matches</li><?php } ?></ul></div>
</div>
<?php } ?>
<?php View::footer();
