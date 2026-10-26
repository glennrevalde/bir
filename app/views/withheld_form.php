<?php
/**
 * Taxes withheld on us in the SAWT columns: (2) payor TIN with branch, (3) corporation registered name,
 * (4) individual last, first and middle name, (5) ATC, nature of payment, (6) income payment, (7) tax rate,
 * (8) tax withheld; plus the month the SAWT is filed for, and the optional payment date and 2307 reference.
 * Expects: $values, $errors, $vendor, $vendors, $atcs, $canEdit, $row, $key, $client, $id, $isNew.
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
$rate = $v('tax_rate', '');
$rateShown = $rate === '' ? '' : (Money::rateUnits($rate) !== null ? rtrim(rtrim(Money::rateFormat((int)Money::rateUnits($rate), 'pct2'), '0'), '.') : $rate);
$fromDirectory = !empty($vendor['id']);
$branches = array();
foreach (Ctx::branches() as $b) {
    $branches[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
$col = function ($n) {
    return ' <span class="col-no">(' . $n . ')</span>';
};
$month = $v('period_month') ? Records::parseMonth($v('period_month')) : '';
$atcNow = strtoupper((string)$v('atc_code'));
// One option per code: the approved version when the registry also holds a newer one pending review.
$byCode = array();
foreach ($atcs as $a) {
    if (!isset($byCode[$a['atc_code']]) || ($byCode[$a['atc_code']]['status'] !== 'approved' && $a['status'] === 'approved')) {
        $byCode[$a['atc_code']] = $a;
    }
}
$atcs = array_values($byCode);
?>
<form method="post" class="sheet purchase-form sawt-form" data-sawt-form data-party-noun="payor" data-autosave="<?= e($key . '-' . ($id ?: 'new') . '-' . $client['id']) ?>" data-fresh="<?= $row || is_post() ? '0' : '1' ?>">
  <?= csrf_field() ?><input type="hidden" name="action" value="save">
  <input type="hidden" name="counterparty_id" value="<?= $fromDirectory ? (int)$vendor['id'] : '' ?>" data-vendor-id>
  <script type="application/json" data-vendors><?= json_encode($vendors, JSON_HEX_TAG | JSON_HEX_AMP) ?></script>
  <?php if ($errors) { ?><div class="notice notice-error" style="margin:16px 20px 0">Fix the highlighted fields.</div><?php } ?>

  <section class="pf-section">
    <header class="pf-head"><h2>Payor</h2><p>The customer who withheld the tax. Type the TIN first; a payor already in your directory fills in by itself, and a new one is added when you save.</p></header>
    <div class="pf-grid pf-vendor">
      <div class="<?= $cls('v_tin', 'span-2') ?>"><label for="v_tin">Taxpayer identification number<?= $col(2) ?></label>
        <input id="v_tin" name="v_tin" type="text" inputmode="numeric" autocomplete="off" maxlength="17" placeholder="000-000-000-0000" value="<?= e($v('v_tin')) ?>" list="payor-tins" data-vendor-tin<?= $dis ?>>
        <datalist id="payor-tins"><?php foreach ($vendors as $vd) { if ($vd['tin']) { ?><option value="<?= e(Tin::display($vd['tin'], $vd['branch'])) ?>"><?= e($vd['name']) ?></option><?php } } ?></datalist>
        <?= $err('v_tin') ?></div>
      <div class="<?= $cls('v_branch') ?>"><label for="v_branch">Branch code</label>
        <input id="v_branch" name="v_branch" type="text" inputmode="numeric" maxlength="5" placeholder="0000" value="<?= e($v('v_branch')) ?>" data-vendor-branch<?= $dis ?>>
        <span class="hint">Or end the TIN with it.</span><?= $err('v_branch') ?></div>
      <p class="pf-vendor-note span-4" data-vendor-note aria-live="polite"><?php if ($fromDirectory) { ?>From your directory. <a href="<?= e(url('cp_form', array('id' => $vendor['id']))) ?>" target="_blank" rel="noopener">Edit payor details</a><?php } ?></p>
      <div class="<?= $cls('v_company', 'span-4') ?>"><label for="v_company">Corporation, registered name<?= $col(3) ?></label>
        <input id="v_company" name="v_company" type="text" maxlength="150" value="<?= e($v('v_company')) ?>" data-vendor-field<?= $dis ?>>
        <span class="hint">For a corporation or other non-individual. For a person, leave it blank and fill in the names below.</span><?= $err('v_company') ?></div>
      <fieldset class="pf-names span-4">
        <legend>Individual<?= $col(4) ?></legend>
        <div class="<?= $cls('v_last') ?>"><label for="v_last">Last name</label><input id="v_last" name="v_last" type="text" maxlength="60" value="<?= e($v('v_last')) ?>" data-vendor-field<?= $dis ?>><?= $err('v_last') ?></div>
        <div class="<?= $cls('v_first') ?>"><label for="v_first">First name</label><input id="v_first" name="v_first" type="text" maxlength="60" value="<?= e($v('v_first')) ?>" data-vendor-field<?= $dis ?>><?= $err('v_first') ?></div>
        <div class="<?= $cls('v_middle') ?>"><label for="v_middle">Middle name</label><input id="v_middle" name="v_middle" type="text" maxlength="60" value="<?= e($v('v_middle')) ?>" data-vendor-field<?= $dis ?>></div>
      </fieldset>
    </div>
  </section>

  <section class="pf-section">
    <header class="pf-head"><h2>Month</h2><p>The SAWT lists what was withheld for the month. The payment date and the 2307 reference are optional.</p></header>
    <div class="pf-grid">
      <div class="<?= $cls('branch_id') ?>"><label for="f-branch_id">Branch *</label><?= View::select('branch_id', $branches, $v('branch_id'), 'id="f-branch_id" required' . $dis) ?><?= $err('branch_id') ?></div>
      <div class="<?= $cls('period_month') ?>"><label for="f-period_month">Month of payment *</label>
        <input id="f-period_month" name="period_month" type="month" value="<?= e($month ?: $v('period_month')) ?>" required<?= $dis ?>><?= $err('period_month') ?></div>
      <div class="<?= $cls('payment_date') ?>"><label for="f-payment_date">Date of payment</label><input id="f-payment_date" name="payment_date" type="date" value="<?= e($v('payment_date') ? (Records::parseDate($v('payment_date')) ?: $v('payment_date')) : '') ?>"<?= $dis ?>><?= $err('payment_date') ?></div>
      <div class="<?= $cls('reference_no') ?>"><label for="f-reference_no">2307 reference</label><input id="f-reference_no" name="reference_no" type="text" maxlength="40" value="<?= e($v('reference_no')) ?>"<?= $dis ?>><?= $err('reference_no') ?></div>
    </div>
  </section>

  <section class="pf-section">
    <header class="pf-head"><h2>Income and tax withheld</h2><p>Choose the ATC on the 2307. The rate and the nature of payment come from it; the tax works out as income x rate, rounded to the centavo.</p></header>
    <div class="pf-grid">
      <div class="<?= $cls('atc_code', 'span-2') ?>"><label for="f-atc_code">ATC code<?= $col(5) ?> *</label>
        <select id="f-atc_code" name="atc_code" required data-sw="atc"<?= $dis ?>><option value="">Choose an ATC</option>
          <?php $found = false; foreach ($atcs as $a) { $r = rtrim(rtrim(Money::rateFormat(Money::rateUnits($a['rate']), 'pct2'), '0'), '.'); if ($a['atc_code'] === $atcNow) { $found = true; } ?>
            <option value="<?= e($a['atc_code']) ?>" data-rate="<?= e($r) ?>" data-desc="<?= e($a['description']) ?>"<?= $a['atc_code'] === $atcNow ? ' selected' : '' ?>><?= e($a['atc_code'] . ', ' . $r . '%: ' . $a['description']) ?><?= $a['status'] !== 'approved' ? ' (pending review)' : '' ?></option>
          <?php } ?>
          <?php if ($atcNow !== '' && !$found) { ?><option value="<?= e($atcNow) ?>" selected><?= e($atcNow) ?> (not in the registry for this month)</option><?php } ?>
        </select><?= $err('atc_code') ?></div>
      <div class="<?= $cls('nature_of_payment', 'span-2') ?>"><label for="f-nature_of_payment">Nature of payment</label>
        <input id="f-nature_of_payment" name="nature_of_payment" type="text" maxlength="255" value="<?= e($v('nature_of_payment')) ?>" placeholder="From the ATC" data-sw="nature"<?= $dis ?>><?= $err('nature_of_payment') ?></div>
    </div>
    <div class="pf-ledger sw-ledger">
      <div class="<?= $cls('income_amount') ?>"><label for="f-income_amount">Amount of income payment<?= $col(6) ?> *</label>
        <input id="f-income_amount" name="income_amount" type="text" inputmode="decimal" class="num" placeholder="0.00" value="<?= e($amt('income_amount')) ?>" required data-sw="income"<?= $dis ?>><?= $err('income_amount') ?></div>
      <div class="<?= $cls('tax_rate', 'pf-rate') ?>"><label for="f-tax_rate">Tax rate (%)<?= $col(7) ?></label>
        <input id="f-tax_rate" name="tax_rate" type="text" inputmode="decimal" class="num" placeholder="From ATC" value="<?= e($rateShown) ?>" data-sw="rate"<?= $dis ?>><?= $err('tax_rate') ?></div>
      <div class="<?= $cls('tax_withheld') ?>"><label for="f-tax_withheld">Amount of tax withheld<?= $col(8) ?></label>
        <input id="f-tax_withheld" name="tax_withheld" type="text" inputmode="decimal" class="num" placeholder="Computed" value="<?= e($amt('tax_withheld')) ?>" data-sw="tax"<?= $dis ?>>
        <span class="hint" data-sw-hint>Income x rate. Type over it only to match the 2307.</span><?= $err('tax_withheld') ?></div>
    </div>
    <details class="pf-more"<?= $v('rate_exception_note') ? ' open' : '' ?>>
      <summary>Note on the rate or tax</summary>
      <div class="pf-grid"><div class="<?= $cls('rate_exception_note', 'span-4') ?>"><label for="f-rate_exception_note">Note</label>
        <input id="f-rate_exception_note" name="rate_exception_note" type="text" maxlength="255" value="<?= e($v('rate_exception_note')) ?>" placeholder="Why the 2307 shows a different rate or tax"<?= $dis ?>><?= $err('rate_exception_note') ?></div></div>
    </details>
    <?php if ($row && $canEdit) { ?>
      <div class="pf-grid" style="margin-top:14px"><div class="field span-4"><label for="edit_reason">Reason for this change</label><input id="edit_reason" name="edit_reason" type="text" maxlength="255" placeholder="Kept in the revision history"></div></div>
    <?php } ?>
  </section>

  <?php if ($canEdit) { ?>
  <div class="sheet-foot">
    <?php if ($isNew) { ?><button class="btn" type="submit" name="again" value="1">Save and add another</button><?php } ?>
    <button class="btn btn-primary" type="submit"><?= $row ? 'Save changes' : 'Save line' ?></button>
  </div>
  <?php } ?>
</form>
