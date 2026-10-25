<?php
Ctx::requirePerm('certificates.issue');
Ctx::requirePerm('records.edit');
$client = Ctx::requireClient();
$ho = Ctx::headOffice();
$year = input_int('year', (int)date('Y'));
$q = max(1, min(4, input_int('q', max(1, (int)ceil(((int)date('n') - 1) / 3)))));
$errors = array();
if (is_post()) {
    try {
        $branch = Ctx::branchAllowed(input_int('branch_id'));
        if (!$branch) {
            throw new RuntimeException('Choose a branch.');
        }
        $cpId = input_int('counterparty_id');
        if (!$cpId) {
            list($pd, $pe) = Records::counterpartyValidate($_POST, $client['id']);
            if ($pe) {
                throw new RuntimeException('Payee: ' . implode('; ', $pe));
            }
            $pd['is_payee'] = 1;
            $cpId = DB::insert('counterparties', array_merge($pd, array('organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'status' => 'active', 'source' => 'manual',
                'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now())));
        }
        $months = quarter_months($year, $q);
        $mod = Records::module('withholding');
        $toSave = array();
        foreach ((array)input('lines', array()) as $li => $line) {
            if (trim((string)$line['atc']) === '') {
                continue;
            }
            foreach ($months as $mi => $m) {
                $amt = isset($line['m' . $mi]) ? trim($line['m' . $mi]) : '';
                if ($amt === '' || Money::parse($amt)['cents'] === 0) {
                    continue;
                }
                list($d, $e) = Records::validate($mod, array('branch_id' => $branch['id'], 'counterparty_id' => $cpId, 'tax_kind' => $line['kind'], 'atc_code' => $line['atc'],
                    'payment_date' => last_day($m), 'nature_of_payment' => 'Quick 2307 entry', 'income_amount' => $amt, 'tax_rate' => '', 'tax_withheld' => '',
                    'rate_exception_note' => '', 'schedule_tag' => 'A', 'reference_no' => '2307-Q' . $q), $client['id']);
                if ($e) {
                    throw new RuntimeException('Line ' . ($li + 1) . ', ' . month_label($m) . ': ' . implode('; ', $e));
                }
                $toSave[] = $d;
            }
        }
        if (!$toSave) {
            throw new RuntimeException('Enter at least one ATC with an amount.');
        }
        $certId = DB::tx(function () use ($toSave, $mod, $client, $branch, $cpId, $months) {
            foreach ($toSave as $d) {
                Records::save($mod, $d);
            }
            $payload = Certificates::buildWithholding('2307', $client, $branch, $cpId, $months[0] . '-01', last_day($months[2]));
            return Certificates::issue($payload, array('signatory_id' => input_int('signatory_id') ?: null, 'paper' => input('paper', 'A4'), 'branch_id' => $branch['id']));
        });
        flash('success', 'Saved ' . plural(count($toSave), 'withholding record') . ' and issued the certificate.');
        redirect(url('cert_view', array('id' => $certId)));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}
$payees = DB::all('SELECT id, tin, branch_code, registered_name, last_name, first_name, middle_name FROM counterparties WHERE client_id = ? AND deleted_at IS NULL AND is_payee = 1 ORDER BY registered_name, last_name', array($client['id']));
$po = array();
foreach ($payees as $p) {
    $po[$p['id']] = party_name($p) . ' (' . ($p['tin'] ? Tin::display($p['tin'], $p['branch_code']) : 'no TIN') . ')';
}
$branchOpts = array();
foreach (Ctx::branches() as $b) {
    $branchOpts[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
$sig = array();
foreach (DB::all('SELECT * FROM signatories WHERE client_id = ? AND status = ? ORDER BY is_default DESC', array($client['id'], 'active')) as $s) {
    $sig[$s['id']] = $s['full_name'];
}
$months = quarter_months($year, $q);
$atcs = Records::atcOptions('EWT', $months[0] . '-01');
$atcOpts = array();
foreach ($atcs as $a) {
    $atcOpts[$a['atc_code']] = $a['atc_code'] . ' ' . Money::rateFormat(Money::rateUnits($a['rate']), 'pct2') . '%';
}
View::header('Quick 2307 entry');
View::pageBar('Quick 2307 entry', View::clientContext('records first, then the certificate'));
?>
<form method="post" class="sheet" data-autosave="cert-quick-<?= (int)$client['id'] ?>" data-fresh="<?= is_post() ? '0' : '1' ?>"><?= csrf_field() ?>
  <div class="sheet-body">
    <fieldset><legend>Period and payor</legend><div class="form-grid">
      <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= (int)$year ?>"></div>
      <div class="field"><label for="q">Quarter</label><?= View::select('q', array(1 => 'Q1', 2 => 'Q2', 3 => 'Q3', 4 => 'Q4'), $q, 'id="q"') ?></div>
      <div class="field"><label for="branch_id">Branch</label><?= View::select('branch_id', $branchOpts, input_int('branch_id', $ho ? (int)$ho['id'] : 0), 'id="branch_id"') ?></div>
      <div class="field"><label for="signatory_id">Signatory</label><?= View::select('signatory_id', $sig, key($sig), 'id="signatory_id"', 'None') ?></div>
      <div class="field"><label for="paper">Paper</label><?= View::select('paper', array('A4' => 'A4', 'LETTER' => 'Letter', 'FOLIO' => 'Long / folio'), 'A4', 'id="paper"') ?></div>
    </div></fieldset>
    <fieldset><legend>Payee</legend><div class="form-grid">
      <div class="field half"><label for="counterparty_id">Existing payee</label><?= View::select('counterparty_id', $po, input('counterparty_id'), 'id="counterparty_id"', 'New payee (fill in below)') ?></div>
      <div class="field"><label for="entity_type">Type</label><?= View::select('entity_type', array('non_individual' => 'Non-individual', 'individual' => 'Individual'), input('entity_type', 'non_individual'), 'id="entity_type"') ?></div>
      <div class="field"><label for="tin">TIN</label><input id="tin" name="tin" type="text" value="<?= e(input('tin', '')) ?>"></div>
      <div class="field"><label for="branch_code">Branch code</label><input id="branch_code" name="branch_code" type="text" value="<?= e(input('branch_code', '0000')) ?>"></div>
      <div class="field half"><label for="registered_name">Registered name</label><input id="registered_name" name="registered_name" type="text" value="<?= e(input('registered_name', '')) ?>"></div>
      <div class="field"><label for="last_name">Last name</label><input id="last_name" name="last_name" type="text" value="<?= e(input('last_name', '')) ?>"></div>
      <div class="field"><label for="first_name">First name</label><input id="first_name" name="first_name" type="text" value="<?= e(input('first_name', '')) ?>"></div>
      <div class="field"><label for="middle_name">Middle name</label><input id="middle_name" name="middle_name" type="text" value="<?= e(input('middle_name', '')) ?>"></div>
      <div class="field half"><label for="address1">Address</label><input id="address1" name="address1" type="text" value="<?= e(input('address1', '')) ?>"></div>
      <div class="field"><label for="address2">City</label><input id="address2" name="address2" type="text" value="<?= e(input('address2', '')) ?>"></div>
      <div class="field"><label for="zip_code">ZIP code</label><input id="zip_code" name="zip_code" type="text" value="<?= e(input('zip_code', '')) ?>"></div>
    </div></fieldset>
    <fieldset><legend>Income payments (tax is computed from each ATC)</legend>
      <div class="table-wrap"><table class="table"><thead><tr><th>Kind</th><th>ATC</th><?php foreach ($months as $m) { ?><th class="num"><?= e(month_label($m)) ?></th><?php } ?></tr></thead><tbody>
      <?php for ($i = 0; $i < 4; $i++) { $l = isset($_POST['lines'][$i]) ? $_POST['lines'][$i] : array(); ?>
        <tr><td><?= View::select('lines[' . $i . '][kind]', array('EWT' => 'EWT', 'GVAT' => 'Gov VAT', 'GPT' => 'Gov PT'), isset($l['kind']) ? $l['kind'] : 'EWT', 'aria-label="Kind"') ?></td>
          <td><?= View::select('lines[' . $i . '][atc]', $atcOpts, isset($l['atc']) ? $l['atc'] : '', 'aria-label="ATC"', 'Choose') ?></td>
          <?php foreach ($months as $mi => $m) { ?><td><input class="num" name="lines[<?= $i ?>][m<?= $mi ?>]" inputmode="decimal" value="<?= e(isset($l['m' . $mi]) ? $l['m' . $mi] : '') ?>" aria-label="<?= e(month_label($m)) ?>"></td><?php } ?></tr>
      <?php } ?></tbody></table></div>
    </fieldset>
  </div>
  <div class="sheet-foot"><button class="btn btn-primary"><?= View::icon('stamp') ?>Save records and issue 2307</button></div>
</form>
<?php View::footer();
