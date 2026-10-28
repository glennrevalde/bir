<?php
Ctx::requirePerm('records.view');
$types = Imports::types();
if ($dl = input('dl')) {
    if (!isset($types[$dl])) {
        deny('Unknown template.');
    }
    $sample = input('sample') === '1';
    $ver = isset($types[$dl]['version']) ? $types[$dl]['version'] : Imports::TEMPLATE_VERSION;
    // The SAWT template carries the active client's TIN and name and the month in its title lines.
    $bytes = Imports::template($dl, $sample, array('month' => Records::parseMonth((string)input('month'))));
    send_bytes($bytes, $types[$dl]['code'] . '-v' . $ver . ($sample ? '-sample' : '') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
View::header('Template library');
View::pageBar('Template library', 'Excel templates for every import type');
?>
<div class="sheet">
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Template</th><th>Code</th><th>Version</th><th class="num">Columns</th><th>Feeds</th><th></th></tr></thead>
    <tbody>
    <?php $feeds = array('sales' => 'Summary List of Sales', 'purchases' => 'Summary List of Purchases', 'importations' => 'Summary List of Importations',
        'withholding' => 'QAP, MAP, 1604-E, 1604-F, 2307, 2306', 'withheld_on_us' => 'SAWT', 'counterparties' => 'Directory', 'compensation' => '1604-C, 2316');
    foreach ($types as $k => $t) { ?>
      <tr><td data-label="Template"><strong><?= e($t['label']) ?></strong></td><td data-label="Code"><?= e($t['code']) ?></td>
        <td data-label="Version"><?= e(isset($t['version']) ? $t['version'] : Imports::TEMPLATE_VERSION) ?></td><td data-label="Columns" class="num"><?= count(Imports::templateColumns($t)) ?></td>
        <td data-label="Feeds"><?= e($feeds[$k]) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= e(url('templates', array('dl' => $k))) ?>"><?= View::icon('download') ?>Blank</a>
          <a class="btn btn-sm btn-quiet" href="<?= e(url('templates', array('dl' => $k, 'sample' => 1))) ?>">With sample rows</a></td></tr>
    <?php } ?>
    </tbody></table></div>
  <div class="sheet-body small muted prose">
    <p>Each workbook has a Fields sheet that explains every column and an About sheet with the template code and version. TIN and date columns are formatted as Text so Excel keeps leading zeros.</p>
    <p>Taxes we withheld has 17 columns ending at SCHEDULE (1 or 2). Its ATC column is a dropdown of the tax codes saved in the system, listed on its sheet ATC codes; download it again after adding codes under <a href="<?= e(url('atc')) ?>">Tax codes</a>.</p>
    <p>Sales follow the VAT sales workbook: its 17 columns, from CUSTOMER TIN to OUTPUT VAT, with GROSS TAXABLE and VAT RATE. Purchases follow the R_Purchases workbook: one sheet named R_Purchases with its 16 columns and formulas. Taxes withheld on us follow the SAWT printout: the title lines carry <?= Ctx::client() ? e(Ctx::clientName(Ctx::client())) . '\'s' : 'the active client\'s' ?> TIN and name and last month, followed by the header (1) to (8), open lines and the Grand Total. A SAWT exported from Taxes withheld on us imports back the same way.</p>
  </div>
</div>
<?php View::footer();
