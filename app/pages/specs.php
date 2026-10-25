<?php
Ctx::requirePerm('reference.view');
$rows = DB::all('SELECT s.*, (SELECT COUNT(*) FROM spec_fields f JOIN spec_records r ON r.id = f.spec_record_id WHERE r.spec_id = s.id) AS fields,
    (SELECT COUNT(*) FROM spec_fields f JOIN spec_records r ON r.id = f.spec_record_id WHERE r.spec_id = s.id AND f.confidence IN (\'analog\', \'low\')) AS weak,
    (SELECT COUNT(*) FROM dat_files d WHERE d.spec_id = s.id) AS files, f.name AS form_name FROM layout_specs s LEFT JOIN form_types f ON f.code = s.form_code
    ORDER BY f.sort_order, s.schedule_code, s.variant_key, s.effective_from DESC');
$counts = array('approved' => 0, 'pending_verification' => 0, 'draft' => 0);
foreach ($rows as $r) {
    if (isset($counts[$r['status']])) {
        $counts[$r['status']]++;
    }
}
View::header('File layouts');
View::pageBar('File layouts', 'Every DAT byte comes from these rows; each field shows its evidence');
?>
<div class="sheet"><div class="figures">
  <div><strong><?= $counts['approved'] ?></strong><span>Approved with validator evidence</span></div>
  <div><strong><?= $counts['pending_verification'] ?></strong><span>Sourced, pending verification</span></div>
  <div><strong><?= $counts['draft'] ?></strong><span>Unsourced drafts (research pending)</span></div>
</div>
<div class="sheet-body small">Research checkpoint <?= e(sys_config('research_checkpoint', '')) ?>. The official field tables (RMC 25-2024 and RMC 15-2025 Annex A) were not obtainable as text; layouts were seeded from the sources named on each one. Approve a layout only after the official validator accepts a file made with it.</div></div>
<div class="sheet"><div class="table-wrap"><table class="table table-stack"><thead><tr><th>Form</th><th>Layout</th><th>Filename pattern</th><th>Evidence</th><th class="num">Fields</th><th class="num">Files</th><th>Status</th><th></th></tr></thead><tbody>
<?php foreach ($rows as $r) { ?>
  <tr class="<?= $r['status'] === 'retired' ? 'void' : '' ?>"><td data-label="Form"><strong><?= e($r['form_code'] . ' ' . $r['schedule_code']) ?></strong><?= $r['variant_key'] ? '<span class="sub">Variant ' . e($r['variant_key']) . '</span>' : '' ?><span class="sub"><?= e($r['form_name']) ?></span></td>
    <td data-label="Layout">#<?= (int)$r['id'] ?> <?= e($r['version_label']) ?><span class="sub">From <?= e(fmt_date($r['effective_from'], 'm/d/Y')) ?><?= $r['effective_to'] ? ' to ' . e(fmt_date($r['effective_to'], 'm/d/Y')) : '' ?></span></td>
    <td data-label="Filename" class="mono small"><?= e($r['filename_pattern']) ?></td>
    <td data-label="Evidence" class="conf conf-<?= e($r['confidence']) ?>"><?= e(Specs::confidenceLabel($r['confidence'])) ?><?= (int)$r['weak'] ? '<span class="sub">' . (int)$r['weak'] . ' weak fields</span>' : '' ?></td>
    <td data-label="Fields" class="num"><?= (int)$r['fields'] ?></td><td data-label="Files" class="num"><?= (int)$r['files'] ?></td>
    <td data-label="Status"><?= View::status($r['status']) ?></td><td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('spec_view', array('id' => $r['id']))) ?>">Open</a></td></tr>
<?php } ?></tbody></table></div></div>
<?php View::footer();
