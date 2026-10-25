<?php
Ctx::requirePerm('reports.generate');
$client = Ctx::requireClient();
$targets = array('1604E_S4' => '1604-E Schedule 4 (twelve months of QAP, placement A)', 'SRS' => 'SRS: Semestral List of Regular Suppliers (six months)');
$target = isset($targets[input('target')]) ? input('target') : '1604E_S4';
$year = input_int('year', (int)date('Y') - ($target === '1604E_S4' ? 1 : 0));
$sem = input_int('sem', 1) === 2 ? 2 : 1;
$ho = Ctx::headOffice();
$branch = Ctx::branchAllowed(input_int('branch_id', $ho ? (int)$ho['id'] : 0));
if (!$branch) {
    deny('Choose a branch you can access.');
}
$srsAtcs = array_filter(array_map('trim', explode(',', strtoupper((string)input('atcs', sys_config('srs_atcs', 'WI158,WC158,WI160,WC160'))))));
$months = array();
$startM = $target === 'SRS' ? ($sem === 1 ? 1 : 7) : 1;
$count = $target === 'SRS' ? 6 : 12;
for ($i = 0; $i < $count; $i++) {
    $months[] = sprintf('%04d-%02d', $year, $startM + $i);
}
$atcFilter = $target === 'SRS' ? $srsAtcs : null;

// ---------------------------------------------------------------- per-month sources
$grid = array();
foreach ($months as $m) {
    $sql = 'SELECT COUNT(*) AS n, COALESCE(SUM(tax_withheld),0) AS tax, COALESCE(SUM(income_amount),0) AS inc, COUNT(DISTINCT counterparty_id) AS payees FROM withholding_entries
        WHERE client_id = ? AND branch_id = ? AND direction = ? AND tax_kind = ? AND schedule_tag = ? AND status = ? AND period_month = ?';
    $p = array($client['id'], $branch['id'], 'agent', 'EWT', 'A', 'posted', $m);
    if ($atcFilter) {
        $sql .= ' AND atc_code IN (' . DB::in($atcFilter) . ')';
        $p = array_merge($p, $atcFilter);
    }
    $db = DB::row($sql, $p);
    $files = DB::all('SELECT d.*, s.status AS spec_status FROM dat_files d LEFT JOIN layout_specs s ON s.id = d.spec_id WHERE d.client_id = ? AND d.form_code = ? AND d.schedule_code = ?
        AND d.period_to = ? AND d.status <> ? AND (d.branch_id = ? OR d.branch_id IS NULL) ORDER BY d.id DESC', array($client['id'], '1601EQ', 'S1', last_day($m), 'superseded', $branch['id']));
    $flags = array();
    if ((int)$db['n'] === 0 && !$files) {
        $flags[] = array('bad', 'Missing: no records and no QAP file');
    }
    if (count($files) > 1) {
        $flags[] = array('wait', count($files) . ' files for this month; choose one');
    }
    foreach ($files as $fl) {
        if ($fl['amended']) {
            $flags[] = array('info', 'Amended file present');
        }
        if ($fl['branch_id'] === null) {
            $flags[] = array('wait', 'A file has no branch; check it belongs to ' . $branch['branch_code']);
        }
        if ((int)$db['n'] && Money::fromDb($fl['total_tax']) !== Money::fromDb($db['tax']) && !$atcFilter) {
            $flags[] = array('wait', 'File ' . $fl['filename'] . ' tax ' . Money::fmtDb($fl['total_tax']) . ' differs from records ' . Money::fmtDb($db['tax']));
        }
    }
    $default = (int)$db['n'] ? 'db' : ($files ? (string)$files[0]['id'] : 'db');
    $grid[$m] = array('db' => $db, 'files' => $files, 'flags' => $flags, 'choice' => (string)input('src_' . str_replace('-', '', $m), $default));
}

// ---------------------------------------------------------------- build merged rows
$build = function () use ($grid, $client, $branch, $atcFilter) {
    $groups = array();
    $srcCount = 0;
    $modes = array();
    foreach ($grid as $m => $g) {
        if ($g['choice'] === 'db') {
            $ds = Datasets::withholding($client['id'], $branch['id'], 'agent', 'EWT', 'A', $m, $m, $atcFilter);
            $modes['database'] = 1;
        } else {
            $ds = Datasets::fromDatFiles(array((int)$g['choice']), $atcFilter);
            foreach ($ds['rows'] as &$r) {
                $r['months'] = $m;
            }
            unset($r);
            $modes['uploaded_dat'] = 1;
        }
        $srcCount += $ds['source_count'];
        foreach ($ds['rows'] as $r) {
            $gk = $r['tin'] . '-' . $r['branch'] . '|' . $r['atc'] . '|' . $r['rate'];
            if (!isset($groups[$gk])) {
                $groups[$gk] = $r;
                $groups[$gk]['income'] = 0;
                $groups[$gk]['tax'] = 0;
                $groups[$gk]['source_ids'] = array();
                $groups[$gk]['months_list'] = array();
            }
            $groups[$gk]['income'] += $r['income'];
            $groups[$gk]['tax'] += $r['tax'];
            $groups[$gk]['source_ids'] = array_merge($groups[$gk]['source_ids'], $r['source_ids']);
            foreach (explode(' ', $r['months']) as $mm) {
                if ($mm !== '') {
                    $groups[$gk]['months_list'][$mm] = 1;
                }
            }
        }
    }
    foreach ($groups as &$g) {
        $ml = array_keys($g['months_list']);
        sort($ml);
        $g['months'] = implode(' ', $ml);
        unset($g['months_list']);
    }
    unset($g);
    ksort($groups);
    return array('rows' => array_values($groups), 'source_count' => $srcCount, 'source_mode' => count($modes) > 1 ? 'mixed' : key($modes));
};
$in = $target === 'SRS'
    ? array('form' => 'SRS', 'schedule' => 'S1', 'branch_id' => $branch['id'], 'year' => $year, 'part' => $sem, 'sawt_form' => null, 'amended' => 0, 'srs_atcs' => $srsAtcs)
    : array('form' => '1604E', 'schedule' => 'S4', 'branch_id' => $branch['id'], 'year' => $year, 'part' => 1, 'sawt_form' => null, 'amended' => 0);

if (is_post()) {
    try {
        $merged = $build();
        $ctx = Reports::context($in, $merged);
        $fid = Reports::generate($ctx, input('draft') === '1', 'Consolidated from ' . count($months) . ' months (' . $merged['source_mode'] . ')');
        flash('success', 'Consolidated file generated.');
        redirect(url('file_view', array('id' => $fid)));
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
}
$preview = input('build') ? $build() : null;
$ctx = $preview ? Reports::context($in, $preview) : null;
$branchOpts = array();
foreach (Ctx::branches() as $b) {
    $branchOpts[$b['id']] = $b['branch_code'] . ' ' . $b['name'];
}
View::header('SRS and annual builder');
View::pageBar('SRS and annual builder', View::clientContext('consolidate monthly QAP sources'));
?>
<form method="get" class="sheet"><input type="hidden" name="p" value="consolidate">
  <div class="filters">
    <div class="field grow"><label for="target">Build</label><?= View::select('target', $targets, $target, 'id="target" data-autosubmit') ?></div>
    <div class="field"><label for="branch_id">Branch</label><?= View::select('branch_id', $branchOpts, $branch['id'], 'id="branch_id"') ?></div>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= (int)$year ?>"></div>
    <?php if ($target === 'SRS') { ?>
      <div class="field"><label for="sem">Semester</label><?= View::select('sem', array(1 => 'January to June', 2 => 'July to December'), $sem, 'id="sem"') ?></div>
      <div class="field grow"><label for="atcs">ATCs included</label><input id="atcs" name="atcs" type="text" value="<?= e(implode(',', $srsAtcs)) ?>"></div>
    <?php } ?>
    <button class="btn">Load months</button>
  </div>
  <?php if ($target === 'SRS') { ?><div class="sheet-body"><div class="callout warn small">A secondary source reports that RMC 122-2019 terminated SRS submission (RR 14-2008, format under RMC 5-2009). Confirm the current rule before relying on this output. The CSV layout is an unsourced draft.</div></div><?php } ?>
  <div class="table-wrap"><table class="table table-stack">
    <thead><tr><th>Month</th><th>Saved records</th><th>QAP files (1601-EQ S1)</th><th>Use</th><th>Checks</th></tr></thead>
    <tbody><?php foreach ($grid as $m => $g) { $nm = 'src_' . str_replace('-', '', $m); ?>
      <tr class="<?= $g['flags'] && $g['flags'][0][0] === 'bad' ? 'row-error' : '' ?>">
        <td data-label="Month"><strong><?= e(month_label($m)) ?></strong></td>
        <td data-label="Saved records"><?= (int)$g['db']['n'] ? plural($g['db']['n'], 'row') . ', ' . (int)$g['db']['payees'] . ' payees, tax ' . Money::fmtDb($g['db']['tax']) : '<span class="muted">None</span>' ?></td>
        <td data-label="QAP files"><?php foreach ($g['files'] as $fl) { ?><div><a class="mono small" href="<?= e(url('file_view', array('id' => $fl['id']))) ?>"><?= e($fl['filename']) ?></a> <?= View::status($fl['status']) ?><span class="sub"><?= e($fl['origin']) ?>, tax <?= Money::fmtDb($fl['total_tax']) ?></span></div><?php } ?><?= $g['files'] ? '' : '<span class="muted">None</span>' ?></td>
        <td data-label="Use"><select name="<?= $nm ?>" aria-label="Source for <?= e(month_label($m)) ?>"><option value="db" <?= $g['choice'] === 'db' ? 'selected' : '' ?>>Saved records</option>
          <?php foreach ($g['files'] as $fl) { ?><option value="<?= (int)$fl['id'] ?>" <?= $g['choice'] === (string)$fl['id'] ? 'selected' : '' ?>>File #<?= (int)$fl['id'] ?></option><?php } ?></select></td>
        <td data-label="Checks"><?php foreach ($g['flags'] as $fl) { ?><div class="st st-<?= $fl[0] ?>" style="white-space:normal"><?= e($fl[1]) ?></div><?php } ?><?= $g['flags'] ? '' : '<span class="st st-ok">OK</span>' ?></td></tr>
    <?php } ?></tbody></table></div>
  <div class="sheet-foot"><button class="btn btn-primary" name="build" value="1">Preview consolidated rows</button></div>
</form>

<?php if ($ctx) { list($nE, $nW) = Checks::counts($ctx['dq']); list($b, $t) = Reports::totals($ctx['dataset']['rows']); ?>
<div class="sheet">
  <div class="sheet-head"><div><h2><?= plural(count($ctx['dataset']['rows']), 'payee row') ?> from <?= plural($preview['source_count'], 'source record') ?></h2><p>Source mode: <?= e($preview['source_mode']) ?>. Each row traces to its months below.</p></div></div>
  <div class="figures"><div><strong><?= Money::fmt($b) ?></strong><span>Income payments</span></div><div><strong><?= Money::fmt($t) ?></strong><span>Tax withheld</span></div><div><strong><?= $nE ?></strong><span>Errors</span></div><div><strong><?= $nW ?></strong><span>Warnings</span></div></div>
  <?php if ($ctx['dq']) { ?><ul class="issues"><?php foreach (array_slice($ctx['dq'], 0, 40) as $i) { ?><li class="<?= e($i['severity']) ?>"><?= e($i['message']) ?></li><?php } ?></ul><?php } ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th>Payee</th><th>TIN</th><th>ATC</th><th class="num">Income</th><th class="num">Tax</th><th>Months</th></tr></thead><tbody>
    <?php foreach (array_slice($ctx['dataset']['rows'], 0, 300) as $r) { ?><tr><td data-label="Payee"><?= e(party_name($r)) ?></td><td data-label="TIN"><?= e(Tin::display($r['tin'], $r['branch'])) ?></td><td data-label="ATC"><?= e($r['atc']) ?></td>
      <td data-label="Income" class="num"><?= Money::fmt($r['income']) ?></td><td data-label="Tax" class="num"><?= Money::fmt($r['tax']) ?></td><td data-label="Months" class="small"><?= e($r['months']) ?></td></tr><?php } ?>
  </tbody></table></div>
  <form method="post" class="sheet-foot"><?= csrf_field() ?>
    <?php foreach ($_GET as $k => $v) { if (is_string($v) && $k !== 'p') { ?><input type="hidden" name="<?= e($k) ?>" value="<?= e($v) ?>"><?php } } ?>
    <?php if (!$nE) { ?><button class="btn btn-primary">Generate consolidated file</button><?php } elseif (Ctx::can('dat.export_draft')) { ?><button class="btn" name="draft" value="1" data-confirm="Generate a diagnostic draft despite the errors?">Generate diagnostic draft</button><?php } ?>
  </form>
</div>
<?php } ?>
<?php View::footer();
