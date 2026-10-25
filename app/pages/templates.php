<?php
Ctx::requirePerm('records.view');
$types = Imports::types();
if ($dl = input('dl')) {
    if (!isset($types[$dl])) {
        deny('Unknown template.');
    }
    $sample = input('sample') === '1';
    $bytes = Imports::template($dl, $sample);
    send_bytes($bytes, $types[$dl]['code'] . '-v' . Imports::TEMPLATE_VERSION . ($sample ? '-sample' : '') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
View::header('Template library');
View::pageBar('Template library', 'Excel templates for every import type, version ' . e(Imports::TEMPLATE_VERSION));
?>
<div class="sheet">
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Template</th><th>Code</th><th class="num">Columns</th><th>Feeds</th><th></th></tr></thead>
    <tbody>
    <?php $feeds = array('sales' => 'Summary List of Sales', 'purchases' => 'Summary List of Purchases', 'importations' => 'Summary List of Importations',
        'withholding' => 'QAP, MAP, 1604-E, 1604-F, 2307, 2306', 'withheld_on_us' => 'SAWT', 'counterparties' => 'Directory', 'compensation' => '1604-C, 2316');
    foreach ($types as $k => $t) { ?>
      <tr><td data-label="Template"><strong><?= e($t['label']) ?></strong></td><td data-label="Code"><?= e($t['code']) ?></td><td data-label="Columns" class="num"><?= count($t['columns']) ?></td>
        <td data-label="Feeds"><?= e($feeds[$k]) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= e(url('templates', array('dl' => $k))) ?>"><?= View::icon('download') ?>Blank</a>
          <a class="btn btn-sm btn-quiet" href="<?= e(url('templates', array('dl' => $k, 'sample' => 1))) ?>">With sample rows</a></td></tr>
    <?php } ?>
    </tbody></table></div>
  <div class="sheet-body small muted">Each workbook has a locked header row on the Data sheet, a Fields sheet that explains every column and its allowed values, and an About sheet with the template code and version. TIN and date columns are formatted as Text so Excel keeps leading zeros.</div>
</div>
<?php View::footer();
