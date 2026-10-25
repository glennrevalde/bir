<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
$year = input_int('year', (int)date('Y') - 1);
$sched = (string)input('schedule', '');
$where = 'c.client_id = ? AND c.tax_year = ?';
$p = array($client['id'], $year);
if (in_array($sched, array('S1', 'S2'), true)) {
    $where .= ' AND c.schedule_code = ?';
    $p[] = $sched;
}
$rows = DB::all('SELECT c.*, e.last_name, e.first_name, e.middle_name, e.tin, e.tin_branch, b.branch_code FROM compensation_records c JOIN employees e ON e.id = c.employee_id
    JOIN branches b ON b.id = c.branch_id WHERE ' . $where . ' ORDER BY c.status, e.last_name, e.first_name', $p);
$years = DB::col('SELECT DISTINCT tax_year FROM compensation_records WHERE client_id = ? ORDER BY tax_year DESC', array($client['id']));
if (!in_array($year, $years)) {
    $years[] = $year;
}
$tot = array('gross' => 0, 'taxable' => 0, 'due' => 0, 'wh' => 0);
$issues = 0;
foreach ($rows as &$r) {
    $r['_issues'] = $r['status'] === 'posted' ? Checks::compArithmetic($r) : array();
    $issues += count($r['_issues']) ? 1 : 0;
    if ($r['status'] === 'posted') {
        $tot['gross'] += Money::fromDb($r['pres_gross']);
        $tot['taxable'] += Money::fromDb($r['taxable_comp_total']);
        $tot['due'] += Money::fromDb($r['tax_due']);
        $tot['wh'] += Money::fromDb($r['tax_withheld_adjusted']) + Money::fromDb($r['prev_tax_withheld']);
    }
}
unset($r);
View::header('Compensation');
View::pageBar('Compensation', View::clientContext('tax year ' . (int)$year),
    (Ctx::can('records.edit') ? '<a class="btn btn-primary" href="' . e(url('comp_form', array('year' => $year))) . '">' . View::icon('plus') . 'Add record</a>' : '')
    . (Ctx::can('imports.run') ? '<a class="btn" href="' . e(url('imports', array('type' => 'compensation'))) . '">Import compensation</a>' : '')
    . (Ctx::can('reports.generate') ? '<a class="btn" href="' . e(url('generate', array('form' => '1604C', 'year' => $year))) . '">Generate 1604-C</a>' : ''));
?>
<?php if ($issues) { ?><div class="notice notice-warning"><?= plural($issues, 'record') ?> have totals that do not add up. The 1604-C file is blocked until they are fixed.</div><?php } ?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="compensation">
    <div class="field"><label for="year">Tax year</label><select id="year" name="year"><?php rsort($years); foreach ($years as $y) { ?><option <?= (int)$y === $year ? 'selected' : '' ?>><?= (int)$y ?></option><?php } ?></select></div>
    <div class="field"><label for="schedule">Schedule</label><?= View::select('schedule', array('S1' => 'Schedule 1 (other than MWE)', 'S2' => 'Schedule 2 (MWE)'), $sched, 'id="schedule"', 'Both') ?></div>
    <button class="btn">Show</button></form>
  <div class="figures">
    <div><strong><?= count($rows) ?></strong><span>Records</span></div>
    <div><strong><?= Money::fmt($tot['gross']) ?></strong><span>Gross compensation</span></div>
    <div><strong><?= Money::fmt($tot['taxable']) ?></strong><span>Taxable compensation</span></div>
    <div><strong><?= Money::fmt($tot['due']) ?></strong><span>Tax due</span></div>
    <div><strong><?= Money::fmt($tot['wh']) ?></strong><span>Tax withheld</span></div>
  </div>
  <?php if (!$rows) { echo View::empty_state('No compensation records for ' . $year, 'Add one per employee, or import the annualization workbook.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Employee</th><th>Schedule</th><th>Branch</th><th class="num">Gross</th><th class="num">Non-taxable</th><th class="num">Taxable</th><th class="num">Tax due</th><th class="num">Withheld</th><th>Check</th><th></th></tr></thead>
    <tbody><?php foreach ($rows as $r) { ?>
      <tr class="<?= $r['status'] === 'void' ? 'void' : ($r['_issues'] ? 'hl' : '') ?>">
        <td data-label="Employee"><strong><?= e($r['last_name'] . ', ' . $r['first_name']) ?></strong><span class="sub"><?= e(Tin::display($r['tin'], $r['tin_branch'])) ?></span></td>
        <td data-label="Schedule"><?= e($r['schedule_code']) ?></td><td data-label="Branch"><?= e($r['branch_code']) ?></td>
        <td data-label="Gross" class="num"><?= Money::fmtDb($r['pres_gross']) ?></td><td data-label="Non-taxable" class="num"><?= Money::fmtDb($r['pres_nt_total']) ?></td>
        <td data-label="Taxable" class="num"><?= Money::fmtDb($r['taxable_comp_total']) ?></td><td data-label="Tax due" class="num"><?= Money::fmtDb($r['tax_due']) ?></td>
        <td data-label="Withheld" class="num"><?= Money::fmt(Money::fromDb($r['tax_withheld_adjusted']) + Money::fromDb($r['prev_tax_withheld'])) ?></td>
        <td data-label="Check"><?= $r['status'] === 'void' ? View::status('void') : ($r['_issues'] ? '<span class="st st-bad" title="' . e(implode("\n", $r['_issues'])) . '">' . count($r['_issues']) . ' issue' . (count($r['_issues']) > 1 ? 's' : '') . '</span>' : '<span class="st st-ok">Adds up</span>') ?></td>
        <td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('comp_form', array('id' => $r['id']))) ?>">Open</a></td></tr>
    <?php } ?></tbody></table></div>
  <?php } ?>
</div>
<?php View::footer();
