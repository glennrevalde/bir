<?php
/**
 * Sales entry in the VAT sales workbook layout: customer (CUSTOMER TIN, CUSTOMER BRANCH CODE, REGISTERED NAME,
 * LAST, FIRST and MIDDLE NAME, SUBSTREET, STREET, BARANGAY and DISTRICT, CITY, ZIP CODE), the client branch,
 * INVOICE NO and INVOICE DATE, then GROSS TAXABLE, EXEMPT SALES, ZERO RATED SALES, TAXABLE SALES, VAT RATE and
 * OUTPUT VAT, worked out the way Records::checkSales does.
 * Expects: $values, $errors, $vendor, $vendors, $canEdit, $row, $key, $client, $id, $isNew.
 */
$v = function ($k, $d = '') use ($values) {
    return isset($values[$k]) && $values[$k] !== null ? $values[$k] : $d;
};
$err = function ($k) use ($errors) {
    return isset($errors[$k]) ? '<span class="err">' . e($errors[$k]) . '</span>' : '';
};
$cls = function ($k, $extra = '') use ($errors) {
    return 'field' . ($extra ? ' ' . $extra : '') . (isset($errors[$k]) ? ' has-err' : '');
};
$dis = $canEdit ? '' : ' disabled';
$amt = function ($k) use ($v) {
    $raw = $v($k);
    if ($raw === '' || $raw === null) {
        return '';
    }
    $p = Money::parse($raw);
    return $p['ok'] ? Money::fmt($p['cents']) : $raw;
};
$rate = $v('vat_rate', '');
$rateShown = $rate === '' ? '12' : (Money::rateUnits($rate) !== null ? rtrim(rtrim(Money::rateFormat((int)Money::rateUnits($rate), 'pct2'), '0'), '.') : $rate);
// A saved sale shows its gross taxable (taxable sales + output VAT); a posted form keeps what was typed.
$gross = isset($values['gross_taxable']) ? $amt('gross_taxable') : ($row ? Money::fmt(Records::salesGross($row)) : '');
$fromDirectory = !empty($vendor['id']);
$branches = array();
foreach (Ctx::branches() as $b) {
    $branches[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
?>
<form method="post" class="sheet purchase-form sales-form" data-sales-form data-party-noun="customer" data-autosave="<?= e($key . '-' . ($id ?: 'new') . '-' . $client['id']) ?>" data-fresh="<?= $row || is_post() ? '0' : '1' ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <input type="hidden" name="counterparty_id" value="<?= $fromDirectory ? (int)$vendor['id'] : '' ?>" data-vendor-id>
  <script type="application/json" data-vendors><?= json_encode($vendors, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php if ($errors) { ?><div class="notice notice-error" style="margin:16px 20px 0">Fix the highlighted fields.</div><?php } ?>

  <section class="pf-section">
    <header class="pf-head"><h2>Customer</h2><p>Type the TIN first. A customer already in your directory fills in by itself; a new one is added when you save.</p></header>
    <div class="pf-grid pf-vendor">
      <div class="<?= $cls('v_tin', 'span-2') ?>"><label for="v_tin">Customer TIN</label>
        <input id="v_tin" name="v_tin" type="text" inputmode="numeric" autocomplete="off" maxlength="17" placeholder="000-000-000" value="<?= e($v('v_tin')) ?>" list="customer-tins" data-vendor-tin<?= $dis ?>>
        <datalist id="customer-tins"><?php foreach ($vendors as $vd) { if ($vd['tin']) { ?><option value="<?= e(Tin::display($vd['tin'], $vd['branch'])) ?>"><?= e($vd['name']) ?></option><?php } } ?></datalist>
        <span class="hint">Leave blank for a customer without a TIN.</span><?= $err('v_tin') ?></div>
      <div class="<?= $cls('v_branch') ?>"><label for="v_branch">Customer branch code</label>
        <input id="v_branch" name="v_branch" type="text" inputmode="numeric" maxlength="5" placeholder="0000" value="<?= e($v('v_branch')) ?>" data-vendor-branch<?= $dis ?>><?= $err('v_branch') ?></div>
      <p class="pf-vendor-note span-4" data-vendor-note aria-live="polite"><?php if ($fromDirectory) { ?>From your directory. <a href="<?= e(url('cp_form', array('id' => $vendor['id']))) ?>" target="_blank" rel="noopener">Edit customer details</a><?php } ?></p>
      <div class="<?= $cls('v_company', 'span-4') ?>"><label for="v_company">Registered name</label>
        <input id="v_company" name="v_company" type="text" maxlength="150" value="<?= e($v('v_company')) ?>" data-vendor-field<?= $dis ?>>
        <span class="hint">For a company or other non-individual. For a person, leave it blank and fill in the names below.</span><?= $err('v_company') ?></div>
      <div class="<?= $cls('v_last') ?>"><label for="v_last">Last name</label><input id="v_last" name="v_last" type="text" maxlength="60" value="<?= e($v('v_last')) ?>" data-vendor-field<?= $dis ?>><?= $err('v_last') ?></div>
      <div class="<?= $cls('v_first') ?>"><label for="v_first">First name</label><input id="v_first" name="v_first" type="text" maxlength="60" value="<?= e($v('v_first')) ?>" data-vendor-field<?= $dis ?>><?= $err('v_first') ?></div>
      <div class="<?= $cls('v_middle', 'span-2') ?>"><label for="v_middle">Middle name</label><input id="v_middle" name="v_middle" type="text" maxlength="60" value="<?= e($v('v_middle')) ?>" data-vendor-field<?= $dis ?>></div>
      <div class="<?= $cls('v_address1', 'span-2') ?>"><label for="v_address1">Substreet, street, barangay</label><input id="v_address1" name="v_address1" type="text" maxlength="150" value="<?= e($v('v_address1')) ?>" data-vendor-field<?= $dis ?>></div>
      <div class="<?= $cls('v_address2', 'span-2') ?>"><label for="v_address2">District, city, ZIP code</label><input id="v_address2" name="v_address2" type="text" maxlength="150" placeholder="CEBU CITY 6000" value="<?= e($v('v_address2')) ?>" data-vendor-field<?= $dis ?>></div>
    </div>
  </section>

  <section class="pf-section">
    <header class="pf-head"><h2>Invoice</h2><p>The branch of this client that issued the invoice. The invoice date sets the month of the sale.</p></header>
    <div class="pf-grid">
      <div class="<?= $cls('branch_id') ?>"><label for="f-branch_id">Client branch *</label><?= View::select('branch_id', $branches, $v('branch_id'), 'id="f-branch_id" required' . $dis) ?><?= $err('branch_id') ?></div>
      <div class="<?= $cls('invoice_no') ?>"><label for="f-invoice_no">Invoice no.</label><input id="f-invoice_no" name="invoice_no" type="text" maxlength="40" value="<?= e($v('invoice_no')) ?>"<?= $dis ?>><?= $err('invoice_no') ?></div>
      <div class="<?= $cls('invoice_date') ?>"><label for="f-invoice_date">Invoice date *</label><input id="f-invoice_date" name="invoice_date" type="date" value="<?= e($v('invoice_date') ? (Records::parseDate($v('invoice_date')) ?: $v('invoice_date')) : '') ?>" required<?= $dis ?>><?= $err('invoice_date') ?></div>
    </div>
  </section>

  <section class="pf-section">
    <header class="pf-head"><h2>Amounts</h2><p>Type the gross taxable (with VAT) or the taxable sales (net of VAT); the other and the output VAT work out as you type.</p></header>
    <div class="pf-ledger sl-ledger">
      <div class="<?= $cls('gross_taxable', 'sl-gross') ?>"><label for="f-gross_taxable">Gross taxable</label>
        <input id="f-gross_taxable" name="gross_taxable" type="text" inputmode="decimal" class="num" placeholder="0.00" value="<?= e($gross) ?>" data-sl="gross"<?= $dis ?>>
        <span class="hint">Taxable sales with VAT.</span><?= $err('gross_taxable') ?></div>
      <?php foreach (array('exempt_amount' => 'Exempt sales', 'zero_rated_amount' => 'Zero-rated sales') as $k => $label) { ?>
        <div class="<?= $cls($k) ?>"><label for="f-<?= $k ?>"><?= e($label) ?></label>
          <input id="f-<?= $k ?>" name="<?= $k ?>" type="text" inputmode="decimal" class="num" placeholder="0.00" value="<?= e($amt($k)) ?>" data-sl="<?= $k ?>"<?= $dis ?>><?= $err($k) ?></div>
      <?php } ?>
      <div class="<?= $cls('taxable_amount') ?>"><label for="f-taxable_amount">Taxable sales</label>
        <input id="f-taxable_amount" name="taxable_amount" type="text" inputmode="decimal" class="num" placeholder="Net of VAT" value="<?= e($amt('taxable_amount')) ?>" data-sl="taxable"<?= $dis ?>>
        <span class="hint">Net of VAT.</span><?= $err('taxable_amount') ?></div>
      <div class="<?= $cls('vat_rate', 'pf-rate') ?>"><label for="f-vat_rate">VAT rate (%)</label>
        <input id="f-vat_rate" name="vat_rate" type="text" inputmode="decimal" class="num" value="<?= e($rateShown) ?>" data-sl="rate"<?= $dis ?>><?= $err('vat_rate') ?></div>
      <div class="<?= $cls('output_vat') ?>"><label for="f-output_vat">Output VAT</label>
        <input id="f-output_vat" name="output_vat" type="text" inputmode="decimal" class="num" placeholder="Computed" value="<?= e($amt('output_vat')) ?>" data-sl="vat"<?= $dis ?>>
        <span class="hint" data-sl-hint>Taxable sales x rate.</span><?= $err('output_vat') ?></div>
      <div class="field pf-out pf-total sl-total"><span class="pf-out-label">Total sales, net of VAT</span><output class="num" data-sl-out="total">0.00</output><span class="hint">Exempt + zero-rated + taxable</span></div>
    </div>
    <details class="pf-more"<?= $v('description') ? ' open' : '' ?>>
      <summary>Description</summary>
      <div class="pf-grid"><div class="<?= $cls('description', 'span-4') ?>"><label for="f-description">Description</label><input id="f-description" name="description" type="text" maxlength="255" value="<?= e($v('description')) ?>"<?= $dis ?>></div></div>
    </details>
    <?php if ($row && $canEdit) { ?>
      <div class="pf-grid" style="margin-top:14px"><div class="field span-4"><label for="edit_reason">Reason for this change</label><input id="edit_reason" name="edit_reason" type="text" maxlength="255" placeholder="Kept in the revision history"></div></div>
    <?php } ?>
  </section>

  <?php if ($canEdit) { ?>
  <div class="sheet-foot">
    <?php if ($isNew) { ?><button class="btn" type="submit" name="again" value="1">Save and add another</button><?php } ?>
    <button class="btn btn-primary" type="submit"><?= $row ? 'Save changes' : 'Save sale' ?></button>
  </div>
  <?php } ?>
</form>
