<?php
Ctx::requirePerm('records.view');
$types = Imports::types();
if ($dl = input('dl')) {
    if (!isset($types[$dl])) {
        deny('Unknown template.');
    }
    $sample = input('sample') === '1';
    $t = $types[$dl];
    $variant = null;
    if (!empty($t['variants'])) {
        $variant = isset($t['variants'][input('v')]) ? input('v') : key($t['variants']);
        $t = array_merge($t, $t['variants'][$variant]);
    }
    $ver = isset($t['version']) ? $t['version'] : Imports::TEMPLATE_VERSION;
    // The SAWT and 1604-E templates carry the active client's TIN and name and the month or year in their title lines.
    $bytes = Imports::template($dl, $sample, array('month' => Records::parseMonth((string)input('month')), 'variant' => $variant));
    send_bytes($bytes, $t['code'] . '-v' . $ver . ($sample ? '-sample' : '') . '.xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
}
// One row per downloadable template: a type with variants (the SAWT for 1702Q and for 2550Q) gives one row each.
$rows = array();
foreach ($types as $k => $t) {
    if (!empty($t['variants'])) {
        foreach ($t['variants'] as $vk => $v) {
            $rows[] = array('key' => $k, 'v' => $vk, 'label' => $v['label'], 'code' => $v['code'], 'version' => $v['version'], 'feeds' => $v['feeds'], 'cols' => count(Imports::templateColumns($t)));
        }
        continue;
    }
    $rows[] = array('key' => $k, 'v' => null, 'label' => $t['label'], 'code' => $t['code'], 'version' => isset($t['version']) ? $t['version'] : Imports::TEMPLATE_VERSION,
        'feeds' => isset($t['feeds']) ? $t['feeds'] : '', 'cols' => count(Imports::templateColumns($t)));
}
$client = Ctx::client();
View::header('Template library');
View::pageBar('Template library', 'Excel templates for every import type');
?>
<div class="sheet">
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Template</th><th>Code</th><th>Version</th><th class="num">Columns</th><th>Feeds</th><th></th></tr></thead>
    <tbody>
    <?php foreach ($rows as $r) { $q = array('dl' => $r['key']) + ($r['v'] ? array('v' => $r['v']) : array()); ?>
      <tr><td data-label="Template"><strong><?= e($r['label']) ?></strong></td><td data-label="Code"><?= e($r['code']) ?></td>
        <td data-label="Version"><?= e($r['version']) ?></td><td data-label="Columns" class="num"><?= (int)$r['cols'] ?></td>
        <td data-label="Feeds"><?= e($r['feeds']) ?></td>
        <td class="actions"><a class="btn btn-sm" href="<?= e(url('templates', $q)) ?>"><?= View::icon('download') ?>Blank</a>
          <a class="btn btn-sm btn-quiet" href="<?= e(url('templates', $q + array('sample' => 1))) ?>">With sample rows</a></td></tr>
    <?php } ?>
    </tbody></table></div>
  <div class="sheet-body small muted prose">
    <p>Each workbook has a Fields sheet that explains every column and an About sheet with the template code and version. TIN and date columns are formatted as Text so Excel keeps leading zeros.</p>
    <p>Taxes we withheld has 17 columns ending at SCHEDULE: 1 for payees subject to withholding, 2 for payees exempt from expanded withholding or for fringe benefits, 3 for payees exempt from final tax. Choose the ATC from its dropdown and KIND, NATURE OF PAYMENT and TAX RATE fill in from the sheet ATC codes; TAX WITHHELD is worked out from the income. The dropdown lists the tax codes saved in the system, so download the template again after adding codes under <a href="<?= e(url('atc')) ?>">Tax codes</a>.</p>
    <p>Sales follow the VAT sales workbook: its 17 columns, from CUSTOMER TIN to OUTPUT VAT, with GROSS TAXABLE and VAT RATE. Purchases follow the R_Purchases workbook: one sheet named R_Purchases with its 16 columns and formulas, where totalPurchases is the services, capital goods and other than capital goods amounts plus their input VAT (with exempt and zero-rated).</p>
    <p>There is a SAWT template for each return the SAWT is attached to: 1700, 1701, 1701Q, 1702, 1702Q and 2550Q. Each follows the SAWT printout of the BIR Alphalist module v7.4. The title lines carry the form, <?= $client ? e(Ctx::clientName($client)) . '\'s' : 'the active client\'s' ?> TIN and name, and the last month of the latest quarter or year ended; then come the header (1) to (8), 50 open lines and the Grand Total.</p>
    <p>ATC CODE is a dropdown. Choose a code and NATURE OF PAYMENT and TAX RATE fill in from the sheet ATC codes, and the tax withheld from the income you type. The 1700, 1701 and 1701Q templates list the codes for individual payees (WI), 1702 and 1702Q the codes for non-individuals (WC), and 2550Q the VAT withholding codes (WV), all from <a href="<?= e(url('atc')) ?>">Tax codes</a>. Every SAWT template uploads as Taxes withheld on us, and a SAWT exported from that page imports back the same way.</p>
    <p>The 1604-E template follows the Schedule 3 printout of the BIR module: BIR FORM 1604E - SCHEDULE 3, AS OF DECEMBER 31 of the year, the TIN and withholding agent's name, then one line per payee and ATC with the year's income, rate and tax. ATC CODE is a dropdown of the expanded withholding codes, and RATE OF TAX fills in from it. Its lines are saved as year totals of taxes we withheld, dated December 31: they go into the 1604-E Schedule 3 and stay out of the December QAP. Use it for a year whose payments are not already recorded month by month, or the 1604-E counts them twice.</p>
  </div>
</div>
<?php View::footer();
