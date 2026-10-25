<?php
Ctx::requirePerm('clients.view');
$rows = array();
foreach (Ctx::clients() as $c) {
    $c['branches'] = (int)DB::val('SELECT COUNT(*) FROM branches WHERE client_id = ?', array($c['id']));
    $c['files'] = (int)DB::val('SELECT COUNT(*) FROM dat_files WHERE client_id = ? AND status <> ?', array($c['id'], 'superseded'));
    $c['pending'] = (int)DB::val('SELECT COUNT(*) FROM dat_files WHERE client_id = ? AND status = ?', array($c['id'], 'pending_bir'));
    $rows[] = $c;
}
View::header('Clients and branches');
View::pageBar('Clients and branches', e(Ctx::org() ? Ctx::org()['name'] : ''), Ctx::can('clients.manage') ? '<a class="btn btn-primary" href="' . e(url('client_form')) . '">' . View::icon('plus') . 'Add client</a>' : '');
?>
<div class="sheet">
  <?php if (!$rows) { echo View::empty_state('No clients yet', 'Add the first taxpayer you prepare reports for.'); } else { ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Client</th><th>TIN</th><th>Type</th><th>RDO</th><th class="num">Branches</th><th class="num">Files</th><th class="num">Awaiting BIR validation</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $c) { ?>
    <tr class="<?= (int)$c['id'] === Ctx::clientId() ? 'hl' : '' ?>"><td data-label="Client"><strong><?= e(Ctx::clientName($c)) ?></strong><span class="sub"><?= e(trim($c['trade_name'] . ($c['status'] === 'archived' ? ($c['trade_name'] ? ', ' : '') . 'Archived' : ''))) ?></span></td>
      <td data-label="TIN"><?= Tin::boxes($c['tin']) ?></td><td data-label="Type"><?= $c['taxpayer_type'] === 'individual' ? 'Individual' : 'Non-individual' ?><span class="sub"><?= e(ucfirst(str_replace('_', ' ', $c['classification']))) ?>, <?= $c['vat_status'] === 'non_vat' ? 'non-VAT' : 'VAT' ?></span></td>
      <td data-label="RDO"><?= e($c['rdo_code']) ?></td><td data-label="Branches" class="num"><?= $c['branches'] ?></td><td data-label="Files" class="num"><?= $c['files'] ?></td><td data-label="Awaiting" class="num"><?= $c['pending'] ?></td>
      <td class="actions"><form method="post" action="<?= e(url('switch_client')) ?>" style="display:inline"><?= csrf_field() ?><input type="hidden" name="client_id" value="<?= (int)$c['id'] ?>"><button class="btn btn-sm btn-quiet">Work on</button></form>
        <a class="btn btn-sm btn-quiet" href="<?= e(url('client_form', array('id' => $c['id']))) ?>">Details</a></td></tr>
  <?php } ?></tbody></table></div>
  <?php } ?>
</div>
<?php View::footer();
