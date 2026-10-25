<?php
/**
 * BIR Forms 2307, 2306 and 2316: payloads from saved records, numbered register,
 * void and reissue, and print-ready PDF rendering (single, combined or ZIP of individual files).
 *
 * The PDF layouts are system replicas organised like the January 2018 (ENCS) revisions.
 * Compare them with the current official form before relying on them; the template revision
 * string is stored with every certificate.
 */
class Certificates
{
    const REV_2307 = '2307 January 2018 (ENCS) system replica';
    const REV_2306 = '2306 January 2018 (ENCS) system replica';
    const REV_2316 = '2316 January 2018 (ENCS) system replica';

    public static function payor($client, $branch)
    {
        $name = Ctx::clientName($client);
        $addr = trim(($branch && $branch['address1'] ? $branch['address1'] : $client['address1']) . ' ' . ($branch && $branch['address2'] ? $branch['address2'] : $client['address2']));
        return array('tin' => $client['tin'], 'branch' => $branch ? $branch['branch_code'] : '0000', 'name' => $name, 'address' => $addr,
            'zip' => $branch && $branch['zip_code'] ? $branch['zip_code'] : $client['zip_code'], 'rdo' => $branch && $branch['rdo_code'] ? $branch['rdo_code'] : $client['rdo_code']);
    }

    /** Payees with withholding in a quarter, with totals, for the batch screen. */
    public static function candidates($form, $branchId, $from, $to)
    {
        if ($form === '2316') {
            return DB::all('SELECT c.id AS ref_id, e.id AS party_id, e.tin, e.last_name, e.first_name, e.middle_name, NULL AS registered_name, c.pres_gross AS income, c.tax_withheld_adjusted AS tax
                FROM compensation_records c JOIN employees e ON e.id = c.employee_id WHERE c.client_id = ? AND c.branch_id = ? AND c.tax_year = ? AND c.status = ? ORDER BY e.last_name, e.first_name',
                array(Ctx::clientId(), $branchId, (int)substr($to, 0, 4), 'posted'));
        }
        $kinds = $form === '2306' ? array('FWT') : array('EWT', 'GVAT', 'GPT');
        return DB::all('SELECT cp.id AS party_id, cp.id AS ref_id, cp.tin, cp.branch_code, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name,
            SUM(w.income_amount) AS income, SUM(w.tax_withheld) AS tax, COUNT(*) AS n FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id
            WHERE w.client_id = ? AND w.branch_id = ? AND w.direction = ? AND w.status = ? AND w.tax_kind IN (' . DB::in($kinds) . ') AND w.payment_date >= ? AND w.payment_date <= ?
            GROUP BY cp.id, cp.tin, cp.branch_code, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name ORDER BY cp.registered_name, cp.last_name',
            array_merge(array(Ctx::clientId(), $branchId, 'agent', 'posted'), $kinds, array($from, $to)));
    }

    public static function buildWithholding($form, $client, $branch, $cpId, $from, $to)
    {
        $cp = DB::row('SELECT * FROM counterparties WHERE id = ? AND client_id = ?', array($cpId, $client['id']));
        if (!$cp) {
            throw new RuntimeException('Payee not found.');
        }
        $months = array(substr($from, 0, 7), date('Y-m', strtotime($from . ' +1 month')), date('Y-m', strtotime($from . ' +2 month')));
        $kinds = $form === '2306' ? array('FWT') : array('EWT', 'GVAT', 'GPT');
        $rows = DB::all('SELECT w.*, t.description AS atc_desc FROM withholding_entries w LEFT JOIN tax_codes t ON t.atc_code = w.atc_code AND t.tax_kind = w.tax_kind
            AND t.effective_from <= w.payment_date AND (t.effective_to IS NULL OR t.effective_to >= w.payment_date)
            WHERE w.client_id = ? AND w.branch_id = ? AND w.counterparty_id = ? AND w.direction = ? AND w.status = ? AND w.tax_kind IN (' . DB::in($kinds) . ')
            AND w.payment_date >= ? AND w.payment_date <= ? ORDER BY w.atc_code, w.payment_date',
            array_merge(array($client['id'], $branch['id'], $cpId, 'agent', 'posted'), $kinds, array($from, $to)));
        if (!$rows) {
            throw new RuntimeException('No withholding was recorded for ' . party_name($cp) . ' in this period.');
        }
        $lines = array();
        $biz = array();
        $ids = array();
        $accum = function (&$set, $r) use ($months) {
            $key = $r['atc_code'];
            if (!isset($set[$key])) {
                $set[$key] = array('nature' => $r['atc_desc'] ?: ($r['nature_of_payment'] ?: $r['atc_code']), 'atc' => $r['atc_code'], 'm' => array(0, 0, 0), 'total' => 0, 'tax' => 0);
            }
            $idx = array_search(substr($r['payment_date'], 0, 7), $months, true);
            $idx = $idx === false ? 2 : $idx;
            $inc = Money::fromDb($r['income_amount']);
            $set[$key]['m'][$idx] += $inc;
            $set[$key]['total'] += $inc;
            $set[$key]['tax'] += Money::fromDb($r['tax_withheld']);
        };
        foreach ($rows as $r) {
            if (in_array($r['tax_kind'], array('GVAT', 'GPT'), true)) {
                $accum($biz, $r);
            } else {
                $accum($lines, $r);
            }
            $ids[] = (int)$r['id'];
        }
        $sum = function ($set) {
            $t = array('m' => array(0, 0, 0), 'total' => 0, 'tax' => 0);
            foreach ($set as $l) {
                for ($i = 0; $i < 3; $i++) {
                    $t['m'][$i] += $l['m'][$i];
                }
                $t['total'] += $l['total'];
                $t['tax'] += $l['tax'];
            }
            return $t;
        };
        $addr = trim($cp['address1'] . ' ' . $cp['address2']);
        return array(
            'form' => $form, 'period_from' => $from, 'period_to' => $to,
            'payee' => array('tin' => $cp['tin'], 'branch' => $cp['branch_code'], 'name' => party_name($cp), 'address' => $addr, 'zip' => $cp['zip_code'], 'foreign' => $cp['foreign_address']),
            'payor' => self::payor($client, $branch),
            'lines' => array_values($lines), 'biz' => array_values($biz), 'total' => $sum($lines), 'biz_total' => $sum($biz),
            'source_ids' => $ids, 'counterparty_id' => (int)$cp['id'],
        );
    }

    public static function build2316($client, $branch, $compId)
    {
        $c = DB::row('SELECT c.*, e.tin AS e_tin, e.tin_branch, e.last_name, e.first_name, e.middle_name, e.birth_date, e.address, e.zip_code, e.contact_no
            FROM compensation_records c JOIN employees e ON e.id = c.employee_id WHERE c.id = ? AND c.client_id = ? AND c.status = ?', array($compId, $client['id'], 'posted'));
        if (!$c) {
            throw new RuntimeException('Compensation record not found.');
        }
        $a = array();
        foreach (Records::compFields() as $fields) {
            foreach ($fields as $k => $l) {
                $a[$k] = Money::fromDb($c[$k]);
            }
        }
        return array(
            'form' => '2316', 'period_from' => $c['employment_from'], 'period_to' => $c['employment_to'], 'year' => (int)$c['tax_year'],
            'employee' => array('tin' => $c['e_tin'], 'branch' => $c['tin_branch'], 'name' => trim($c['last_name'] . ', ' . $c['first_name'] . ' ' . $c['middle_name']),
                'address' => $c['address'], 'zip' => $c['zip_code'], 'birth' => $c['birth_date'], 'contact' => $c['contact_no'], 'mwe' => $c['schedule_code'] === 'S2'),
            'employer' => self::payor($client, $branch), 'has_previous' => (int)$c['has_previous_employer'], 'amounts' => $a,
            'substituted' => $c['substituted_filing'] === 'Y', 'employee_id' => (int)$c['employee_id'], 'comp_id' => (int)$c['id'],
        );
    }

    public static function nextNumber($clientId, $form, $year)
    {
        $last = DB::val('SELECT MAX(CAST(SUBSTRING(cert_no, ' . (strlen($form . '-' . $year . '-') + 1) . ') AS UNSIGNED)) FROM certificates WHERE client_id = ? AND form_code = ? AND cert_no LIKE ?',
            array($clientId, $form, $form . '-' . $year . '-%'));
        return $form . '-' . $year . '-' . str_pad((string)((int)$last + 1), 5, '0', STR_PAD_LEFT);
    }

    /** Issue (store + render) one certificate. $opts: signatory_id, use_signature, paper, offset_x, offset_y, reissue_of */
    public static function issue($payload, $opts)
    {
        Ctx::requireActive();
        $client = Ctx::client();
        $form = $payload['form'];
        $year = substr($payload['period_to'], 0, 4);
        $rev = 1;
        $certNo = null;
        if (!empty($opts['reissue_of'])) {
            $orig = DB::row('SELECT * FROM certificates WHERE id = ? AND client_id = ?', array($opts['reissue_of'], $client['id']));
            if (!$orig) {
                throw new RuntimeException('Original certificate not found.');
            }
            $certNo = $orig['cert_no'];
            $rev = (int)DB::val('SELECT MAX(revision) FROM certificates WHERE client_id = ? AND form_code = ? AND cert_no = ?', array($client['id'], $form, $certNo)) + 1;
        }
        $sig = !empty($opts['signatory_id']) ? DB::row('SELECT * FROM signatories WHERE id = ? AND client_id = ? AND status = ?', array($opts['signatory_id'], $client['id'], 'active')) : null;
        $useSig = !empty($opts['use_signature']) && $sig && $sig['signature_path'] && $sig['signature_approved_at'];
        if (!empty($opts['use_signature']) && !$useSig) {
            throw new RuntimeException('The selected signatory has no approved signature image.');
        }
        if ($useSig && !Ctx::can('certificates.sign') && (int)$sig['user_id'] !== Auth::id()) {
            throw new RuntimeException('Only an authorized signatory can apply a signature image.');
        }
        return DB::tx(function () use ($payload, $opts, $client, $form, $year, $rev, $certNo, $sig, $useSig) {
            $certNo = $certNo ?: self::nextNumber($client['id'], $form, $year);
            $payload['cert_no'] = $certNo;
            $payload['revision'] = $rev;
            $payload['signatory'] = $sig ? array('name' => $sig['full_name'], 'title' => $sig['title'], 'tin' => $sig['tin']) : null;
            $payload['use_signature'] = $useSig ? 1 : 0;
            $payload['signature_path'] = $useSig ? $sig['signature_path'] : null;
            $payload['offset_x'] = isset($opts['offset_x']) ? (float)$opts['offset_x'] : 0;
            $payload['offset_y'] = isset($opts['offset_y']) ? (float)$opts['offset_y'] : 0;
            $paper = isset($opts['paper']) && in_array($opts['paper'], array('A4', 'LETTER', 'FOLIO'), true) ? $opts['paper'] : 'A4';
            $tplRev = $form === '2307' ? self::REV_2307 : ($form === '2306' ? self::REV_2306 : self::REV_2316);
            $pdf = new Pdf($paper);
            self::render($pdf, $payload, $tplRev);
            $bytes = $pdf->output('BIR Form ' . $form . ' ' . $certNo);
            $rel = 'pdf/' . $client['id'] . '/' . $form . '/' . $certNo . '-r' . $rev . '.pdf';
            if (!is_dir(dirname(storage_path($rel)))) {
                mkdir(dirname(storage_path($rel)), 0775, true);
            }
            file_put_contents(storage_path($rel), $bytes);
            $total = $form === '2316' ? $payload['amounts']['pres_gross'] : $payload['total']['total'] + $payload['biz_total']['total'];
            $tax = $form === '2316' ? $payload['amounts']['tax_withheld_adjusted'] + $payload['amounts']['prev_tax_withheld'] : $payload['total']['tax'] + $payload['biz_total']['tax'];
            $id = DB::insert('certificates', array(
                'organization_id' => Ctx::orgId(), 'client_id' => $client['id'], 'branch_id' => isset($opts['branch_id']) ? $opts['branch_id'] : null, 'form_code' => $form,
                'cert_no' => $certNo, 'counterparty_id' => isset($payload['counterparty_id']) ? $payload['counterparty_id'] : null,
                'employee_id' => isset($payload['employee_id']) ? $payload['employee_id'] : null, 'period_from' => $payload['period_from'], 'period_to' => $payload['period_to'],
                'total_income' => Money::toPlain($total), 'total_tax' => Money::toPlain($tax), 'revision' => $rev, 'reissue_of' => !empty($opts['reissue_of']) ? $opts['reissue_of'] : null,
                'status' => 'issued', 'signatory_id' => $sig ? $sig['id'] : null, 'use_signature' => $useSig ? 1 : 0, 'paper_size' => $paper, 'template_revision' => $tplRev,
                'payload_json' => json_encode($payload), 'pdf_path' => $rel, 'sha256' => hash('sha256', $bytes), 'generated_by' => Auth::id(), 'created_at' => now(),
            ));
            if (!empty($opts['reissue_of'])) {
                DB::update('certificates', array('status' => 'reissued'), 'id = ?', array($opts['reissue_of']));
            }
            if (!empty($payload['source_ids'])) {
                DB::q('UPDATE withholding_entries SET certificate_id = ? WHERE id IN (' . DB::in($payload['source_ids']) . ') AND client_id = ?', array_merge(array($id), $payload['source_ids'], array($client['id'])));
            }
            Audit::log('certificate.issue', 'certificates', $id, 'Issued ' . $certNo . ' revision ' . $rev);
            return $id;
        });
    }

    public static function void($certId, $reason)
    {
        $c = DB::row('SELECT * FROM certificates WHERE id = ?', array($certId));
        if (!$c || $c['status'] === 'void') {
            throw new RuntimeException('Certificate not found or already void.');
        }
        DB::update('certificates', array('status' => 'void', 'void_reason' => mb_substr($reason, 0, 255), 'voided_by' => Auth::id(), 'voided_at' => now()), 'id = ?', array($certId));
        DB::q('UPDATE withholding_entries SET certificate_id = NULL WHERE certificate_id = ?', array($certId));
        Audit::log('certificate.void', 'certificates', $certId, 'Voided ' . $c['cert_no'], null, null, $reason, $c['client_id']);
    }

    public static function renderStored($certIds, $combine = true)
    {
        $files = array();
        $pdf = null;
        foreach ($certIds as $id) {
            $c = DB::row('SELECT * FROM certificates WHERE id = ? AND client_id = ?', array($id, Ctx::clientId()));
            if (!$c) {
                continue;
            }
            if ($combine) {
                if (!$pdf) {
                    $pdf = new Pdf($c['paper_size']);
                }
                $payload = json_decode($c['payload_json'], true);
                self::render($pdf, $payload, $c['template_revision'], $c['status'] === 'void');
            } else {
                $files[] = $c;
            }
        }
        return $combine ? ($pdf ? $pdf->output('Certificates') : null) : $files;
    }

    // ------------------------------------------------------------------ drawing
    public static function render($pdf, $p, $tplRev, $void = false)
    {
        if ($p['form'] === '2316') {
            self::draw2316($pdf, $p, $tplRev);
        } else {
            self::drawWithholding($pdf, $p, $tplRev);
        }
        if ($void) {
            $pdf->strokeRgb(180, 30, 30)->setLineWidth(3);
            $pdf->setFont('B', 60)->fillRgb(200, 40, 40);
            $pdf->text($pdf->width() / 2 - 80, $pdf->height() / 2 - 40, 'VOID');
            $pdf->fillRgb(0, 0, 0)->strokeRgb(0, 0, 0)->setLineWidth(0.6);
        }
    }

    private static function head($pdf, $formNo, $title, $L, $W, $y)
    {
        $pdf->setFont('', 7.5)->text($L, $y, 'For BIR Use Only');
        $pdf->setFont('', 8.5);
        $pdf->cell($L, $y + 2, $W, 11, 'Republic of the Philippines', 'C');
        $pdf->cell($L, $y + 12, $W, 11, 'Department of Finance', 'C');
        $pdf->setFont('B', 9.5)->cell($L, $y + 22, $W, 12, 'Bureau of Internal Revenue', 'C');
        $y += 38;
        $pdf->setLineWidth(1)->rect($L, $y, $W, 40)->setLineWidth(0.6);
        $pdf->line($L + 92, $y, $L + 92, $y + 40);
        $pdf->line($L + $W - 110, $y, $L + $W - 110, $y + 40);
        $pdf->setFont('', 7)->text($L + 5, $y + 5, 'BIR Form No.');
        $pdf->setFont('B', 20)->text($L + 5, $y + 13, $formNo);
        $pdf->setFont('', 6.5)->text($L + 5, $y + 33, 'January 2018 (ENCS)');
        $pdf->setFont('B', 11);
        $tw = $W - 92 - 110;
        $parts = explode('|', $title);
        $ty = $y + (count($parts) > 1 ? 8 : 14);
        foreach ($parts as $t) {
            $pdf->cell($L + 92, $ty, $tw, 12, $t, 'C');
            $ty += 13;
        }
        $pdf->setFont('', 7)->cell($L + $W - 110, $y + 6, 110, 10, $formNo . ' 01/18ENCS', 'C');
        $pdf->setLineWidth(0.4);
        for ($i = 0; $i < 30; $i++) {
            $x = $L + $W - 100 + $i * 3;
            $pdf->line($x, $y + 20, $x, $y + 34 - ($i % 3 === 0 ? 0 : 3));
        }
        $pdf->setLineWidth(0.6);
        $pdf->setFont('', 6.5)->text($L, $y + 43, 'Fill in all applicable spaces. Mark all appropriate boxes with an "X".');
        return $y + 53;
    }

    private static function bar($pdf, $L, $W, $y, $text)
    {
        $pdf->fillRgb(205, 208, 215)->rect($L, $y, $W, 12, 'FD')->fillRgb(0, 0, 0);
        $pdf->setFont('B', 8)->cell($L, $y, $W, 12, $text, 'C');
        return $y + 12;
    }

    private static function field($pdf, $x, $y, $w, $h, $num, $label, $value, $align = 'L')
    {
        $pdf->rect($x, $y, $w, $h);
        $pdf->setFont('B', 6.5)->text($x + 2, $y + 2, $num);
        $lx = $x + 5 + max(7, $pdf->strWidth($num, 'B', 6.5));
        $pdf->setFont('', 6.5)->cell($lx - 2, $y + 1, $w - ($lx - $x), 8, $label);
        $pdf->setFont('B', 9)->cell($x + 10, $y + 9, $w - 12, $h - 10, (string)$value, $align);
    }

    private static function tinField($pdf, $x, $y, $w, $num, $label, $tin, $branch)
    {
        $pdf->rect($x, $y, $w, 22);
        $pdf->setFont('B', 6.5)->text($x + 2, $y + 2, $num);
        $pdf->setFont('', 6.5)->text($x + 5 + max(7, $pdf->strWidth($num, 'B', 6.5)), $y + 2, $label);
        $groups = strlen((string)$tin) === 9 ? array(substr($tin, 0, 3), substr($tin, 3, 3), substr($tin, 6, 3), (string)$branch) : array('', '', '', '');
        $bx = $x + 12;
        foreach ($groups as $i => $g) {
            $bw = $i === 3 ? 42 : 34;
            $pdf->rect($bx, $y + 10, $bw, 10);
            $pdf->setFont('B', 9)->cell($bx, $y + 10, $bw, 10, $g, 'C');
            $bx += $bw + 6;
            if ($i < 3) {
                $pdf->setFont('', 8)->text($bx - 5, $y + 11, '-');
            }
        }
    }

    private static function money($c)
    {
        return $c ? Money::fmt($c) : '';
    }

    private static function drawWithholding($pdf, $p, $tplRev)
    {
        $pdf->addPage();
        $L = 28;
        $W = $pdf->width() - 56;
        $is2306 = $p['form'] === '2306';
        $y = self::head($pdf, $p['form'], $is2306 ? 'Certificate of Final Tax|Withheld at Source' : 'Certificate of Creditable Tax|Withheld at Source', $L, $W, 24);
        $pdf->rect($L, $y, $W, 18);
        $pdf->setFont('B', 6.5)->text($L + 2, $y + 2, '1');
        $pdf->setFont('', 7)->text($L + 12, $y + 5, 'For the Period');
        $pdf->text($L + 80, $y + 5, 'From');
        $pdf->rect($L + 104, $y + 3, 80, 12);
        $pdf->setFont('B', 9)->cell($L + 104, $y + 3, 80, 12, fmt_date($p['period_from'], 'm/d/Y'), 'C');
        $pdf->setFont('', 7)->text($L + 200, $y + 5, 'To');
        $pdf->rect($L + 216, $y + 3, 80, 12);
        $pdf->setFont('B', 9)->cell($L + 216, $y + 3, 80, 12, fmt_date($p['period_to'], 'm/d/Y'), 'C');
        $pdf->setFont('', 6.5)->text($L + 310, $y + 6, '(MM/DD/YYYY)');
        $y += 18;
        $y = self::bar($pdf, $L, $W, $y, 'Part I - Payee Information');
        self::tinField($pdf, $L, $y, $W, '2', 'Taxpayer Identification Number (TIN)', $p['payee']['tin'], $p['payee']['branch']);
        $y += 22;
        self::field($pdf, $L, $y, $W, 22, '3', "Payee's Name (Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)", $p['payee']['name']);
        $y += 22;
        self::field($pdf, $L, $y, $W - 90, 22, '4', 'Registered Address', $p['payee']['address']);
        self::field($pdf, $L + $W - 90, $y, 90, 22, '4A', 'ZIP Code', $p['payee']['zip'], 'C');
        $y += 22;
        self::field($pdf, $L, $y, $W, 22, '5', 'Foreign Address, if applicable', $p['payee']['foreign']);
        $y += 22;
        $y = self::bar($pdf, $L, $W, $y, 'Part II - Payor Information');
        self::tinField($pdf, $L, $y, $W, '6', 'Taxpayer Identification Number (TIN)', $p['payor']['tin'], $p['payor']['branch']);
        $y += 22;
        self::field($pdf, $L, $y, $W, 22, '7', "Payor's Name (Last Name, First Name, Middle Name for Individual OR Registered Name for Non-Individual)", $p['payor']['name']);
        $y += 22;
        self::field($pdf, $L, $y, $W - 90, 22, '8', 'Registered Address', $p['payor']['address']);
        self::field($pdf, $L + $W - 90, $y, 90, 22, '8A', 'ZIP Code', $p['payor']['zip'], 'C');
        $y += 22;
        $y = self::bar($pdf, $L, $W, $y, 'Part III - Details of Monthly Income Payments and Taxes Withheld');
        $cols = array(0.27, 0.07, 0.13, 0.13, 0.13, 0.13, 0.14);
        $xs = array($L);
        foreach ($cols as $c) {
            $xs[] = end($xs) + $c * $W;
        }
        $pdf->rect($L, $y, $W, 26);
        $pdf->line($xs[2], $y + 12, $xs[6], $y + 12);
        foreach (array(1, 2, 6) as $i) {
            $pdf->line($xs[$i], $y, $xs[$i], $y + 26);
        }
        foreach (array(3, 4, 5) as $i) {
            $pdf->line($xs[$i], $y + 12, $xs[$i], $y + 26);
        }
        $pdf->setFont('B', 6.5);
        $pdf->cell($xs[0], $y + 2, $xs[1] - $xs[0], 10, $is2306 ? 'Income Payments Subject to' : 'Income Payments Subject to', 'C');
        $pdf->cell($xs[0], $y + 12, $xs[1] - $xs[0], 10, $is2306 ? 'Final Withholding Tax' : 'Expanded Withholding Tax', 'C');
        $pdf->cell($xs[1], $y + 7, $xs[2] - $xs[1], 12, 'ATC', 'C');
        $pdf->cell($xs[2], $y, $xs[6] - $xs[2], 12, 'AMOUNT OF INCOME PAYMENTS', 'C');
        $heads = array('1st Month of the Quarter', '2nd Month of the Quarter', '3rd Month of the Quarter', 'Total');
        for ($i = 0; $i < 4; $i++) {
            $pdf->setFont('', 6)->cell($xs[2 + $i], $y + 12, $xs[3 + $i] - $xs[2 + $i], 14, $heads[$i], 'C');
        }
        $pdf->setFont('B', 6.5)->cell($xs[6], $y + 2, $xs[7] - $xs[6], 10, 'Tax Withheld', 'C');
        $pdf->cell($xs[6], $y + 12, $xs[7] - $xs[6], 10, 'for the Quarter', 'C');
        $y += 26;
        $drawRows = function ($lines, $n, $totalLabel, $total) use ($pdf, $xs, $L, $W, &$y) {
            for ($r = 0; $r < $n; $r++) {
                $pdf->rect($L, $y, $W, 13);
                for ($i = 1; $i <= 6; $i++) {
                    $pdf->line($xs[$i], $y, $xs[$i], $y + 13);
                }
                if (isset($lines[$r])) {
                    $l = $lines[$r];
                    $pdf->setFont('', 7)->cell($xs[0], $y, $xs[1] - $xs[0], 13, $l['nature']);
                    $pdf->setFont('B', 7.5)->cell($xs[1], $y, $xs[2] - $xs[1], 13, $l['atc'], 'C');
                    $pdf->setFont('', 7.5);
                    for ($m = 0; $m < 3; $m++) {
                        $pdf->cell($xs[2 + $m], $y, $xs[3 + $m] - $xs[2 + $m], 13, self::money($l['m'][$m]), 'R');
                    }
                    $pdf->setFont('B', 7.5)->cell($xs[5], $y, $xs[6] - $xs[5], 13, self::money($l['total']), 'R');
                    $pdf->cell($xs[6], $y, $xs[7] - $xs[6], 13, self::money($l['tax']), 'R');
                }
                $y += 13;
            }
            $pdf->fillRgb(236, 238, 242)->rect($L, $y, $W, 13, 'FD')->fillRgb(0, 0, 0);
            for ($i = 2; $i <= 6; $i++) {
                $pdf->line($xs[$i], $y, $xs[$i], $y + 13);
            }
            $pdf->setFont('B', 7.5)->cell($xs[0], $y, $xs[2] - $xs[0], 13, $totalLabel);
            for ($m = 0; $m < 3; $m++) {
                $pdf->cell($xs[2 + $m], $y, $xs[3 + $m] - $xs[2 + $m], 13, self::money($total['m'][$m]), 'R');
            }
            $pdf->cell($xs[5], $y, $xs[6] - $xs[5], 13, self::money($total['total']), 'R');
            $pdf->cell($xs[6], $y, $xs[7] - $xs[6], 13, self::money($total['tax']), 'R');
            $y += 13;
        };
        $lines = $p['lines'];
        $n = max(8, count($lines));
        $drawRows($lines, $n, 'Total', $p['total']);
        if (!$is2306) {
            $pdf->rect($L, $y, $W, 12);
            $pdf->setFont('B', 6.5)->cell($L, $y, $W, 12, 'Money Payments Subject to Withholding of Business Tax (Government & Private)', 'L');
            $y += 12;
            $drawRows($p['biz'], max(4, count($p['biz'])), 'Total', $p['biz_total']);
        }
        $y += 4;
        $pdf->setFont('', 6.8);
        $y = $pdf->para($L, $y, $W, 'We declare under the penalties of perjury that this certificate has been made in good faith, verified by us, and to the best of our knowledge and belief, is true and correct, pursuant to the provisions of the National Internal Revenue Code, as amended, and the regulations issued under authority thereof. Further, we give our consent to the processing of our information as contemplated under the *Data Privacy Act of 2012 (R.A. No. 10173) for legitimate and lawful purposes.', 8.5);
        $y += 2;
        self::signBlock($pdf, $p, $L, $W, $y, 'Payor/Payor\'s Authorized Representative/Tax Agent', true);
        $y += 64;
        $pdf->setFont('B', 7)->text($L, $y, 'CONFORME:');
        $y += 10;
        self::signBlock($pdf, $p, $L, $W, $y, 'Payee/Payee\'s Authorized Representative/Tax Agent', false);
        $y += 64;
        self::footer($pdf, $p, $tplRev, $L, $W);
    }

    private static function signBlock($pdf, $p, $L, $W, $y, $who, $isPayor)
    {
        $pdf->rect($L, $y, $W, 58);
        $sigName = '';
        if ($isPayor && !empty($p['signatory'])) {
            $s = $p['signatory'];
            $sigName = strtoupper($s['name']) . ($s['title'] ? ', ' . $s['title'] : '') . ($s['tin'] ? ', TIN ' . $s['tin'] : '');
            if (!empty($p['use_signature']) && !empty($p['signature_path']) && is_file(storage_path($p['signature_path']))) {
                try {
                    $pdf->image(storage_path($p['signature_path']), $L + $W * 0.3 + (float)$p['offset_x'], $y + 2 + (float)$p['offset_y'], 110, 26);
                } catch (Exception $e) {
                    // image problems never block issuing; the name prints regardless
                }
            }
        }
        $pdf->setFont('B', 8.5)->cell($L, $y + 18, $W, 12, $sigName, 'C');
        $pdf->line($L + 60, $y + 31, $L + $W - 60, $y + 31);
        $pdf->setFont('', 6.5)->cell($L, $y + 32, $W, 9, 'Signature over Printed Name of ' . $who, 'C');
        $pdf->cell($L, $y + 40, $W, 8, '(Indicate Title/Designation and TIN)', 'C');
        $third = $W / 3;
        $pdf->line($L, $y + 48, $L + $W, $y + 48);
        $pdf->line($L + $third, $y + 48, $L + $third, $y + 58);
        $pdf->line($L + 2 * $third, $y + 48, $L + 2 * $third, $y + 58);
        $pdf->setFont('', 5.8);
        $pdf->text($L + 2, $y + 50, "Tax Agent Accreditation No./Attorney's Roll No. (if applicable)");
        $pdf->text($L + $third + 2, $y + 50, 'Date of Issue (MM/DD/YYYY)');
        $pdf->text($L + 2 * $third + 2, $y + 50, 'Date of Expiry (MM/DD/YYYY)');
    }

    private static function footer($pdf, $p, $tplRev, $L, $W)
    {
        $pdf->setFont('', 6.2)->fillRgb(90, 90, 90);
        $pdf->text($L, $pdf->height() - 30, 'Certificate ' . $p['cert_no'] . ', revision ' . $p['revision'] . '. Layout: ' . $tplRev . '. Generated ' . date('m/d/Y H:i') . ' by G-SMaRT BIR Integration System.');
        $pdf->text($L, $pdf->height() - 22, '*NOTE: The BIR Data Privacy Policy is in the BIR website (www.bir.gov.ph)');
        $pdf->fillRgb(0, 0, 0);
    }

    private static function draw2316($pdf, $p, $tplRev)
    {
        $pdf->addPage();
        $L = 24;
        $W = $pdf->width() - 48;
        $a = $p['amounts'];
        $y = self::head($pdf, '2316', 'Certificate of Compensation Payment/Tax Withheld|For Compensation Payment With or Without Tax Withheld', $L, $W, 18);
        $half = $W / 2;
        self::field($pdf, $L, $y, 120, 22, '1', 'For the Year (YYYY)', (string)$p['year'], 'C');
        $pdf->rect($L + 120, $y, $W - 120, 22);
        $pdf->setFont('B', 6.5)->text($L + 122, $y + 2, '2');
        $pdf->setFont('', 6.5)->text($L + 132, $y + 2, 'For the Period');
        $pdf->text($L + 132, $y + 12, 'From (MM/DD)');
        $pdf->setFont('B', 9)->text($L + 190, $y + 11, fmt_date($p['period_from'], 'm/d'));
        $pdf->setFont('', 6.5)->text($L + 240, $y + 12, 'To (MM/DD)');
        $pdf->setFont('B', 9)->text($L + 288, $y + 11, fmt_date($p['period_to'], 'm/d'));
        $y += 22;
        // Left column: parts I-III, right column: part IV
        $colL = $L;
        $colW = $half - 3;
        $colR = $L + $half + 3;
        $yl = self::bar($pdf, $colL, $colW, $y, 'Part I - Employee Information');
        $e = $p['employee'];
        self::tinField($pdf, $colL, $yl, $colW, '3', 'TIN', $e['tin'], $e['branch']);
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW - 50, 22, '4', "Employee's Name (Last Name, First Name, Middle Name)", $e['name']);
        self::field($pdf, $colL + $colW - 50, $yl, 50, 22, '5', 'RDO Code', '', 'C');
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW - 50, 22, '6', 'Registered Address', $e['address']);
        self::field($pdf, $colL + $colW - 50, $yl, 50, 22, '6A', 'ZIP Code', $e['zip'], 'C');
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW / 2, 22, '7', 'Date of Birth (MM/DD/YYYY)', fmt_date($e['birth'], 'm/d/Y'), 'C');
        self::field($pdf, $colL + $colW / 2, $yl, $colW / 2, 22, '8', 'Contact Number', $e['contact'], 'C');
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW / 2, 22, '9', 'Statutory Minimum Wage rate per day', self::money($a['smw_per_day']), 'R');
        self::field($pdf, $colL + $colW / 2, $yl, $colW / 2, 22, '10', 'Statutory Minimum Wage rate per month', self::money($a['smw_per_month']), 'R');
        $yl += 22;
        $pdf->rect($colL, $yl, $colW, 20);
        $pdf->setFont('B', 6.5)->text($colL + 2, $yl + 2, '11');
        $pdf->rect($colL + 14, $yl + 5, 9, 9);
        if ($e['mwe']) {
            $pdf->setFont('B', 8)->text($colL + 15.5, $yl + 5.5, 'X');
        }
        $pdf->setFont('', 6.2)->para($colL + 28, $yl + 3, $colW - 32, 'Minimum Wage Earner (MWE) whose compensation is exempt from withholding tax and not subject to income tax', 7.5);
        $yl += 20;
        $yl = self::bar($pdf, $colL, $colW, $yl, 'Part II - Employer Information (Present)');
        $er = $p['employer'];
        self::tinField($pdf, $colL, $yl, $colW, '12', 'TIN', $er['tin'], $er['branch']);
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW, 22, '13', "Employer's Name", $er['name']);
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW - 50, 22, '14', 'Registered Address', $er['address']);
        self::field($pdf, $colL + $colW - 50, $yl, 50, 22, '14A', 'ZIP Code', $er['zip'], 'C');
        $yl += 22;
        $pdf->rect($colL, $yl, $colW, 16);
        $pdf->setFont('B', 6.5)->text($colL + 2, $yl + 2, '15');
        $pdf->setFont('', 6.5)->text($colL + 14, $yl + 4, 'Type of Employer');
        $pdf->rect($colL + 80, $yl + 4, 8, 8);
        $pdf->setFont('B', 8)->text($colL + 81.5, $yl + 4, 'X');
        $pdf->setFont('', 6.5)->text($colL + 92, $yl + 4, 'Main Employer');
        $pdf->rect($colL + 160, $yl + 4, 8, 8);
        $pdf->text($colL + 172, $yl + 4, 'Secondary Employer');
        $yl += 16;
        $yl = self::bar($pdf, $colL, $colW, $yl, 'Part III - Employer Information (Previous)');
        self::tinField($pdf, $colL, $yl, $colW, '16', 'TIN', '', '');
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW, 22, '17', "Employer's Name", $p['has_previous'] ? 'See previous employer certificate' : '');
        $yl += 22;
        self::field($pdf, $colL, $yl, $colW - 50, 22, '18', 'Registered Address', '');
        self::field($pdf, $colL + $colW - 50, $yl, 50, 22, '18A', 'ZIP Code', '', 'C');
        $yl += 22;
        $yl = self::bar($pdf, $colL, $colW, $yl, 'Part IV-A - Summary');
        $sum = array(
            array('19', 'Gross Compensation Income from Present Employer', $a['pres_gross']),
            array('20', 'Less: Total Non-Taxable/Exempt Compensation Income from Present Employer', $a['pres_nt_total']),
            array('21', 'Taxable Compensation Income from Present Employer', $a['pres_tx_total']),
            array('22', 'Add: Taxable Compensation Income from Previous Employer, if applicable', $a['prev_tx_total']),
            array('23', 'Gross Taxable Compensation Income', $a['taxable_comp_total']),
            array('24', 'Tax Due', $a['tax_due']),
            array('25A', 'Amount of Taxes Withheld: Present Employer', $a['tax_withheld_adjusted']),
            array('25B', 'Amount of Taxes Withheld: Previous Employer, if applicable', $a['prev_tax_withheld']),
            array('26', 'Total Amount of Taxes Withheld as adjusted', $a['tax_withheld_adjusted'] + $a['prev_tax_withheld']),
            array('27', '5% Tax Credit (PERA Act of 2008)', $a['pera_credit']),
            array('28', 'Total Taxes Withheld', $a['tax_withheld_adjusted'] + $a['prev_tax_withheld'] + $a['pera_credit']),
        );
        foreach ($sum as $s) {
            $yl = self::amountRow($pdf, $colL, $colW, $yl, $s[0], $s[1], $s[2]);
        }
        $yr = self::bar($pdf, $colR, $colW, $y, 'Part IV-B - Details of Compensation Income');
        $pdf->setFont('B', 6.5)->text($colR + 2, $yr + 2, 'A. NON-TAXABLE/EXEMPT COMPENSATION INCOME');
        $yr += 11;
        $nt = array(
            array('29', 'Basic Salary (including the exempt P250,000 and below) or SMW', $a['pres_nt_smw']),
            array('30', 'Holiday Pay (MWE)', $a['mwe_holiday_pay']), array('31', 'Overtime Pay (MWE)', $a['mwe_overtime_pay']),
            array('32', 'Night Shift Differential (MWE)', $a['mwe_night_diff']), array('33', 'Hazard Pay (MWE)', $a['mwe_hazard_pay']),
            array('34', '13th Month Pay and Other Benefits (maximum of P90,000)', $a['pres_nt_13th']),
            array('35', 'De Minimis Benefits', $a['pres_nt_deminimis']),
            array('36', 'SSS, GSIS, PHIC & PAG-IBIG Contributions and Union Dues (Employee share only)', $a['pres_nt_contrib']),
            array('37', 'Salaries and Other Forms of Compensation', $a['pres_nt_other']),
            array('38', 'Total Non-Taxable/Exempt Compensation Income', $a['pres_nt_total']),
        );
        foreach ($nt as $s) {
            $yr = self::amountRow($pdf, $colR, $colW, $yr, $s[0], $s[1], $s[2]);
        }
        $pdf->setFont('B', 6.5)->text($colR + 2, $yr + 2, 'B. TAXABLE COMPENSATION INCOME REGULAR');
        $yr += 11;
        $tx = array(
            array('39', 'Basic Salary', $a['pres_tx_basic']), array('40', 'Representation', 0), array('41', 'Transportation', 0),
            array('42', 'Cost of Living Allowance (COLA)', 0), array('43', 'Fixed Housing Allowance', 0), array('44', 'Others', 0),
        );
        foreach ($tx as $s) {
            $yr = self::amountRow($pdf, $colR, $colW, $yr, $s[0], $s[1], $s[2]);
        }
        $pdf->setFont('B', 6.5)->text($colR + 2, $yr + 2, 'SUPPLEMENTARY');
        $yr += 11;
        $sup = array(
            array('45', 'Commission', 0), array('46', 'Profit Sharing', 0), array('47', "Fees Including Director's Fees", 0),
            array('48', 'Taxable 13th Month Benefits', $a['pres_tx_13th']), array('49', 'Hazard Pay', 0), array('50', 'Overtime Pay', 0),
            array('51', 'Others', $a['pres_tx_other']), array('52', 'Total Taxable Compensation Income', $a['pres_tx_total']),
        );
        foreach ($sup as $s) {
            $yr = self::amountRow($pdf, $colR, $colW, $yr, $s[0], $s[1], $s[2]);
        }
        $y = max($yl, $yr) + 6;
        $pdf->setFont('', 6.6);
        $y = $pdf->para($L, $y, $W, 'I/We declare, under the penalties of perjury that this certificate has been made in good faith, verified by me/us, and to the best of my/our knowledge and belief, is true and correct, pursuant to the provisions of the National Internal Revenue Code, as amended, and the regulations issued under authority thereof. Further, I/we give my/our consent to the processing of my/our information as contemplated under the *Data Privacy Act of 2012 (R.A. No. 10173) for legitimate and lawful purposes.', 8);
        $y += 2;
        $pdf->rect($L, $y, $half, 46);
        $pdf->rect($L + $half, $y, $half, 46);
        if (!empty($p['signatory'])) {
            $s = $p['signatory'];
            if (!empty($p['use_signature']) && !empty($p['signature_path']) && is_file(storage_path($p['signature_path']))) {
                try {
                    $pdf->image(storage_path($p['signature_path']), $L + $half * 0.25 + (float)$p['offset_x'], $y + 2 + (float)$p['offset_y'], 100, 22);
                } catch (Exception $ex) {
                }
            }
            $pdf->setFont('B', 8)->cell($L, $y + 20, $half, 11, strtoupper($s['name']) . ($s['title'] ? ', ' . $s['title'] : ''), 'C');
        }
        $pdf->line($L + 20, $y + 32, $L + $half - 20, $y + 32);
        $pdf->setFont('', 6.2)->cell($L, $y + 33, $half, 9, 'Present Employer/Authorized Agent Signature over Printed Name', 'C');
        $pdf->setFont('B', 8)->cell($L + $half, $y + 20, $half, 11, strtoupper($e['name']), 'C');
        $pdf->line($L + $half + 20, $y + 32, $L + $W - 20, $y + 32);
        $pdf->setFont('', 6.2)->cell($L + $half, $y + 33, $half, 9, 'Employee Signature over Printed Name', 'C');
        $y += 50;
        if ($p['substituted']) {
            $pdf->setFont('B', 6.6)->text($L, $y, 'To be accomplished under substituted filing');
            $pdf->setFont('', 6.4);
            $pdf->para($L, $y + 9, $W, 'I declare, under the penalties of perjury, that the information herein stated are reported under BIR Form No. 1604-C which has been filed with the Bureau of Internal Revenue.', 8);
        }
        self::footer($pdf, $p, $tplRev, $L, $W);
    }

    private static function amountRow($pdf, $x, $w, $y, $num, $label, $cents)
    {
        $pdf->rect($x, $y, $w, 13);
        $pdf->line($x + $w - 82, $y, $x + $w - 82, $y + 13);
        $pdf->setFont('B', 6.3)->text($x + 2, $y + 3.5, $num);
        $pdf->setFont('', 6.3)->cell($x + 16, $y, $w - 100, 13, $label);
        $pdf->setFont('B', 7.5)->cell($x + $w - 82, $y, 82, 13, self::money($cents), 'R');
        return $y + 13;
    }
}
