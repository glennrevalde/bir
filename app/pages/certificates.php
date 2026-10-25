<?php
Ctx::requirePerm('records.view');
$client = Ctx::requireClient();
if (is_post()) {
    $ids = array_map('intval', (array)input('ids', array()));
    $action = input('action');
    try {
        if (!$ids) {
            throw new RuntimeException('Select certificates first.');
        }
        if ($action === 'combined') {
            $bytes = Certificates::renderStored($ids, true);
            Audit::log('certificate.download', 'certificates', null, 'Downloaded ' . count($ids) . ' certificates as one PDF');
            send_bytes($bytes, 'certificates-' . $client['tin'] . '-' . date('Ymd-His') . '.pdf', 'application/pdf');
        } elseif ($action === 'zip') {
            if (!class_exists('ZipArchive')) {
                throw new RuntimeException('The PHP zip extension is required for ZIP downloads.');
            }
            $tmp = tempnam(storage_path('tmp'), 'zip');
            $zip = new ZipArchive();
            $zip->open($tmp, ZipArchive::OVERWRITE);
            foreach (Certificates::renderStored($ids, false) as $c) {
                if ($c['pdf_path'] && is_file(storage_path($c['pdf_path']))) {
                    $who = $c['counterparty_id'] ? party_name(DB::row('SELECT * FROM counterparties WHERE id = ?', array($c['counterparty_id'])))
                        : (($e = DB::row('SELECT last_name, first_name FROM employees WHERE id = ?', array($c['employee_id']))) ? $e['last_name'] . ' ' . $e['first_name'] : '');
                    $zip->addFile(storage_path($c['pdf_path']), $c['cert_no'] . '-r' . $c['revision'] . '-' . preg_replace('/[^A-Za-z0-9]+/', '_', $who) . '.pdf');
                }
            }
            $zip->close();
            Audit::log('certificate.download', 'certificates', null, 'Downloaded ' . count($ids) . ' certificates as ZIP');
            $b = file_get_contents($tmp);
            @unlink($tmp);
            send_bytes($b, 'certificates-' . $client['tin'] . '-' . date('Ymd-His') . '.zip', 'application/zip');
        }
    } catch (RuntimeException $e) {
        flash('error', $e->getMessage());
    }
    redirect(url('certificates'));
}
$f = array('form' => (string)input('form', ''), 'year' => input_int('year', (int)date('Y')), 'q' => trim((string)input('q', '')), 'status' => (string)input('status', ''));
$where = array('c.client_id = ?', 'YEAR(c.period_to) = ?');
$p = array($client['id'], $f['year']);
if (in_array($f['form'], array('2307', '2306', '2316'), true)) {
    $where[] = 'c.form_code = ?';
    $p[] = $f['form'];
}
if (in_array($f['status'], array('issued', 'void', 'reissued'), true)) {
    $where[] = 'c.status = ?';
    $p[] = $f['status'];
}
if ($f['q'] !== '') {
    $where[] = '(c.cert_no LIKE ? OR cp.registered_name LIKE ? OR cp.last_name LIKE ? OR e.last_name LIKE ? OR cp.tin LIKE ?)';
    array_push($p, '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . $f['q'] . '%', '%' . preg_replace('/\D/', '', $f['q']) . '%');
}
$rows = DB::all('SELECT c.*, cp.tin, cp.branch_code AS cp_branch, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name, e.last_name AS e_last, e.first_name AS e_first, e.tin AS e_tin, u.full_name
    FROM certificates c LEFT JOIN counterparties cp ON cp.id = c.counterparty_id LEFT JOIN employees e ON e.id = c.employee_id LEFT JOIN users u ON u.id = c.generated_by
    WHERE ' . implode(' AND ', $where) . ' ORDER BY c.cert_no DESC, c.revision DESC LIMIT 500', $p);
View::header('Certificate register');
View::pageBar('Certificate register', View::clientContext('BIR Forms 2307, 2306 and 2316'),
    (Ctx::can('certificates.issue') ? '<a class="btn btn-primary" href="' . e(url('cert_issue')) . '">' . View::icon('stamp') . 'Issue certificates</a><a class="btn" href="' . e(url('cert_quick')) . '">Quick 2307</a>' : ''));
?>
<div class="sheet">
  <form class="filters" method="get"><input type="hidden" name="p" value="certificates">
    <div class="field"><label for="form">Form</label><?= View::select('form', array('2307' => '2307 creditable', '2306' => '2306 final', '2316' => '2316 compensation'), $f['form'], 'id="form"', 'All forms') ?></div>
    <div class="field"><label for="year">Year</label><input id="year" name="year" type="number" value="<?= (int)$f['year'] ?>"></div>
    <div class="field"><label for="status">Status</label><?= View::select('status', array('issued' => 'Issued', 'reissued' => 'Replaced by a revision', 'void' => 'Void'), $f['status'], 'id="status"', 'All') ?></div>
    <div class="field grow"><label for="q">Number, name or TIN</label><input id="q" name="q" type="search" value="<?= e($f['q']) ?>"></div><button class="btn">Filter</button></form>
  <?php if (!$rows) { echo View::empty_state('No certificates for ' . $f['year'], 'Issue 2307 and 2306 from withholding records, and 2316 from compensation records.'); } else { ?>
  <form method="post"><?= csrf_field() ?>
  <div class="table-wrap"><table class="table table-stack"><thead><tr><th><input type="checkbox" data-check-all="ids[]" aria-label="Select all"></th><th>Number</th><th>Form</th><th>Payee or employee</th><th>Period</th><th class="num">Income</th><th class="num">Tax</th><th>Status</th><th></th></tr></thead><tbody>
  <?php foreach ($rows as $r) { ?>
    <tr class="<?= $r['status'] === 'void' ? 'void' : '' ?>"><td data-label="Select"><input type="checkbox" name="ids[]" value="<?= (int)$r['id'] ?>" aria-label="Select"></td>
      <td data-label="Number"><strong><?= e($r['cert_no']) ?></strong><span class="sub">Revision <?= (int)$r['revision'] ?></span></td><td data-label="Form"><?= e($r['form_code']) ?></td>
      <td data-label="Payee"><?= e($r['employee_id'] ? $r['e_last'] . ', ' . $r['e_first'] : party_name($r)) ?><span class="sub"><?= e(Tin::display($r['employee_id'] ? $r['e_tin'] : $r['tin'])) ?></span></td>
      <td data-label="Period"><?= e(fmt_date($r['period_from'], 'm/d/Y') . ' to ' . fmt_date($r['period_to'], 'm/d/Y')) ?></td>
      <td data-label="Income" class="num"><?= Money::fmtDb($r['total_income']) ?></td><td data-label="Tax" class="num"><?= Money::fmtDb($r['total_tax']) ?></td>
      <td data-label="Status"><?= View::status($r['status']) ?></td><td class="actions"><a class="btn btn-sm btn-quiet" href="<?= e(url('cert_view', array('id' => $r['id']))) ?>">Open</a></td></tr>
  <?php } ?></tbody></table></div>
  <div class="sheet-foot"><button class="btn" name="action" value="combined"><?= View::icon('download') ?>One combined PDF</button><button class="btn" name="action" value="zip"><?= View::icon('download') ?>ZIP of individual PDFs</button></div>
  </form>
  <?php } ?>
</div>
<?php View::footer();
