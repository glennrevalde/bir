<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$q = trim((string)input('q', ''));
$role = (string)input('role', '');
$where = 'client_id = ? AND deleted_at IS NULL';
$p = array($client['id']);
if ($q !== '') {
    $digits = preg_replace('/\D/', '', $q);
    $where .= ' AND (registered_name LIKE ? OR last_name LIKE ? OR first_name LIKE ?' . (strlen($digits) >= 3 ? ' OR tin LIKE ?' : '') . ')';
    array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . $q . '%');
    if (strlen($digits) >= 3) {
        $p[] = '%' . $digits . '%';
    }
}
if (in_array($role, array('supplier', 'customer', 'payee', 'payor'), true)) {
    $where .= ' AND is_' . $role . ' = 1';
}
$filter = input('filter', '');
if ($filter === 'no_tin') {
    $where .= ' AND tin IS NULL';
}
$total = (int)DB::val('SELECT COUNT(*) FROM counterparties WHERE ' . $where, $p);
$per = 50;
$page = max(1, input_int('page', 1));
$rows = DB::all('SELECT c.*, (SELECT COUNT(*) FROM withholding_entries w WHERE w.counterparty_id = c.id AND w.status = \'posted\') AS wh_n FROM counterparties c WHERE ' . str_replace('client_id', 'c.client_id', $where)
    . ' ORDER BY COALESCE(c.registered_name, c.last_name), c.first_name LIMIT ' . $per . ' OFFSET ' . (($page - 1) * $per), $p);
View::header('Customers, suppliers, payees', array('page' => 'directory'));
View::pageBar('Customers, suppliers, payees', View::clientContext(plural($total, 'entry', 'entries')),
    (Ctx::can('records.edit') ? '<a class="btn btn-primary" href="' . e(url('cp_form')) . '">' . View::icon('plus') . 'Add entry</a>' : '')
    . (Ctx::can('imports.run') ? '<a class="btn" href="' . e(url('imports', array('type' => 'counterparties'))) . '">Import directory</a>' : ''));
?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="directory">
    <div class="field grow"><label for="q">Name or TIN</label><input id="q" name="q" type="search" value="<?= e($q) ?>"></div>
    <div class="field"><label for="role">Role</label><?= View::select('role', array('supplier' => 'Suppliers', 'customer' => 'Customers', 'payee' => 'Payees', 'payor' => 'Payors'), $role, 'id="role"', 'All roles') ?></div>
    <div class="field"><label for="filter">Show</label><?= View::select('filter', array('no_tin' => 'Missing TIN only'), $filter, 'id="filter"', 'Everyone') ?></div>
    <button class="btn">Filter</button>
  </form>
  <?php if (!$rows) { echo View::empty_state('No entries found', 'Add customers, suppliers and payees here, or let imports create them from your spreadsheets.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Name</th><th>TIN</th><th>Type</th><th>Roles</th><th>Default ATC</th><th class="num">Withholding rows</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) {
        $roles = array();
        foreach (array('supplier', 'customer', 'payee', 'payor') as $x) {
            if ($r['is_' . $x]) {
                $roles[] = ucfirst($x);
            }
        } ?>
      <tr class="<?= $r['tin'] ? '' : 'hl' ?>">
        <td data-label="Name"><strong><?= e(party_name($r)) ?></strong><span class="sub"><?= e(trim($r['address1'] . ' ' . $r['address2'])) ?></span></td>
        <td data-label="TIN"><?= $r['tin'] ? Tin::boxes($r['tin'], $r['branch_code']) : '<span class="st st-wait">No TIN</span>' ?></td>
        <td data-label="Type"><?= $r['entity_type'] === 'individual' ? 'Individual' : 'Non-individual' ?></td>
        <td data-label="Roles"><?= e(implode(', ', $roles)) ?></td>
        <td data-label="Default ATC"><?= e($r['default_atc']) ?></td>
        <td data-label="Withholding rows" class="num"><?= (int)$r['wh_n'] ?></td>
        <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('cp_form', array('id' => $r['id']))) ?>">Open</a></td>
      </tr>
    <?php } ?>
    </tbody></table></div>
  <?= View::pager($total, $page, $per, array('q' => $q, 'role' => $role, 'filter' => $filter)) ?>
  <?php } ?>
</div>
<?php View::footer();
