<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$q = trim((string)input('q', ''));
$where = 'e.client_id = ? AND e.deleted_at IS NULL';
$p = array($client['id']);
if ($q !== '') {
    $where .= ' AND (e.last_name LIKE ? OR e.first_name LIKE ? OR e.tin LIKE ? OR e.employee_no LIKE ?)';
    array_push($p, '%' . $q . '%', '%' . $q . '%', '%' . preg_replace('/\D/', '', $q) . '%', '%' . $q . '%');
}
$rows = DB::all('SELECT e.*, b.branch_code, (SELECT COUNT(*) FROM compensation_records c WHERE c.employee_id = e.id AND c.status = \'posted\') AS comp_n
    FROM employees e LEFT JOIN branches b ON b.id = e.branch_id WHERE ' . $where . ' ORDER BY e.last_name, e.first_name LIMIT 500', $p);
View::header('Employees');
View::pageBar('Employees', View::clientContext(plural(count($rows), 'employee')),
    (Ctx::can('records.edit') ? '<a class="btn btn-primary" href="' . e(url('employee_form')) . '">' . View::icon('plus') . 'Add employee</a>' : '')
    . '<a class="btn" href="' . e(url('compensation')) . '">Compensation records</a>');
?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="employees">
    <div class="field grow"><label for="q">Name, TIN or employee no.</label><input id="q" name="q" type="search" value="<?= e($q) ?>"></div><button class="btn">Filter</button></form>
  <?php if (!$rows) { echo View::empty_state('No employees yet', 'Add employees here, or import annual compensation; the import creates employees from the rows.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Employee</th><th>TIN</th><th>No.</th><th>Branch</th><th>Region</th><th>Status</th><th class="num">Compensation years</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r) { ?>
      <tr><td data-label="Employee"><strong><?= e($r['last_name'] . ', ' . $r['first_name'] . ' ' . $r['middle_name']) ?></strong><?= $r['is_mwe'] ? '<span class="sub">Minimum wage earner</span>' : '' ?></td>
        <td data-label="TIN"><?= $r['tin'] ? Tin::boxes($r['tin'], $r['tin_branch']) : '<span class="st st-bad">No TIN</span>' ?></td>
        <td data-label="No."><?= e($r['employee_no']) ?></td><td data-label="Branch"><?= e($r['branch_code']) ?></td><td data-label="Region"><?= e($r['region_code']) ?></td>
        <td data-label="Status"><?= e($r['employment_status']) ?></td><td data-label="Compensation years" class="num"><?= (int)$r['comp_n'] ?></td>
        <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('employee_form', array('id' => $r['id']))) ?>">Open</a></td></tr>
    <?php } ?></tbody></table></div>
  <?php } ?>
</div>
<?php View::footer();
