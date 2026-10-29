<?php
/**
 * BIR Forms 2307, 2306 and 2316: payloads from saved records, numbered register,
 * void and reissue, and print-ready PDF rendering (single, combined or ZIP of individual files).
 *
 * The PDF layouts are system replicas of the current BIR revisions: 2307 and 2306 January 2018 (ENCS),
 * 2316 September 2021 (ENCS).
 * Compare them with the current official form before relying on them; the template revision
 * string is stored with every certificate.
 */
class Certificates
{
    const REV_2307 = '2307 January 2018 (ENCS) system replica';
    const REV_2306 = '2306 January 2018 (ENCS) system replica';
    const REV_2316 = '2316 September 2021 (ENCS) replica';

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
                'last_name' => $c['last_name'], 'first_name' => $c['first_name'], 'middle_name' => $c['middle_name'],
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

    // ------------------------------------------------------------------ BIR Form 2316, September 2021 (ENCS)
    /*
     * Drawn on a 612 x 936 point sheet (the 8.5 x 13 inch form) and scaled to fit the chosen paper.
     * Item numbers, labels and their order follow the September 2021 (ENCS) revision.
     */
    const F21_W = 612;
    const F21_H = 936;

    private static function g21($pdf, $x, $y, $w, $h)
    {
        $pdf->fillRgb(217, 217, 217)->rect($x, $y, $w, $h, 'FD')->fillRgb(0, 0, 0);
    }

    /** White entry box with an optional value. $cells > 1 adds the small digit ticks of the printed form. */
    private static function box21($pdf, $x, $y, $w, $h, $val = '', $align = 'L', $size = 8.5, $cells = 0, $style = '')
    {
        $pdf->fillRgb(255, 255, 255)->rect($x, $y, $w, $h, 'FD')->fillRgb(0, 0, 0);
        if ($cells > 1) {
            $pdf->setLineWidth(0.4);
            for ($i = 1; $i < $cells; $i++) {
                $tx = $x + $w * $i / $cells;
                $pdf->line($tx, $y + $h - 2.5, $tx, $y + $h);
            }
            $pdf->setLineWidth(0.6);
        }
        if ((string)$val !== '') {
            $pdf->setFont($style, $size)->cell($x, $y, $w, $h, (string)$val, $align, 2.5);
        }
    }

    /** Item number in bold, then its label; returns the x after the label. */
    private static function lbl21($pdf, $x, $y, $num, $label, $size = 6.6, $italic = '')
    {
        if ($num !== '') {
            $pdf->setFont('B', $size)->text($x, $y, $num);
            $x += max(12, $pdf->strWidth($num, 'B', $size) + 3);
        }
        $pdf->setFont('', $size)->text($x, $y, $label);
        $end = $x + $pdf->strWidth($label, '', $size);
        if ($italic !== '') {
            $pdf->setFont('BI', $size)->text($end + 2, $y, $italic);
            $end += 2 + $pdf->strWidth($italic, 'BI', $size);
        }
        return $end;
    }

    private static function tin21($pdf, $x, $y, $tin, $branch)
    {
        $tin = (string)$tin;
        $g = strlen($tin) === 9 ? array(substr($tin, 0, 3), substr($tin, 3, 3), substr($tin, 6, 3), (string)$branch) : array('', '', '', $tin !== '' ? (string)$branch : '');
        foreach (array(3, 3, 3, 4) as $i => $n) {
            $w = $n === 4 ? 46 : 36;
            self::box21($pdf, $x, $y, $w, 13, $g[$i], 'C', 8.5, $n);
            $x += $w + ($i < 3 ? 9 : 0);
        }
    }

    /** MM / DD / YYYY boxes as on the form; $date is Y-m-d or empty. */
    private static function date21($pdf, $x, $y, $date, $h = 13)
    {
        $v = $date ? array(substr($date, 5, 2), substr($date, 8, 2), substr($date, 0, 4)) : array('', '', '');
        self::box21($pdf, $x, $y, 22, $h, $v[0], 'C', 8, 2);
        self::box21($pdf, $x + 22, $y, 22, $h, $v[1], 'C', 8, 2);
        self::box21($pdf, $x + 44, $y, 40, $h, $v[2], 'C', 8, 4);
        return $x + 84;
    }

    private static function money21($c)
    {
        return Money::fmt((int)$c);
    }

    /** "ABABAO, ERLINDA AMOLONG" printed as "ERLINDA AMOLONG ABABAO" for the signature lines. */
    private static function printName21($e)
    {
        if (!empty($e['first_name']) || !empty($e['last_name'])) {
            return strtoupper(trim(preg_replace('/\s+/', ' ', $e['first_name'] . ' ' . (isset($e['middle_name']) ? $e['middle_name'] : '') . ' ' . $e['last_name'])));
        }
        $parts = explode(',', (string)$e['name'], 2);
        return strtoupper(trim(count($parts) === 2 ? trim($parts[1]) . ' ' . trim($parts[0]) : $parts[0]));
    }

    private static function draw2316($pdf, $p, $tplRev)
    {
        $pdf->addPage();
        $m = 8;
        $scale = min(($pdf->width() - 2 * $m) / self::F21_W, ($pdf->height() - 2 * $m) / self::F21_H, 1);
        $pdf->beginScale($scale, ($pdf->width() - self::F21_W * $scale) / 2, $m);
        $a = $p['amounts'];
        $e = $p['employee'];
        $er = $p['employer'];
        $L = 14;
        $W = 584;
        $R = $L + $W;
        $LW = 290;
        $RX = $L + $LW;
        $RW = $W - $LW;

        // ---------------------------------------------------------------- heading
        self::g21($pdf, $L, 8, 50, 22);
        $pdf->setFont('', 6.5)->text($L + 4, 12, 'For BIR');
        $pdf->text($L + 4, 20, 'Use Only');
        $pdf->setFont('', 6.5)->text($L + 58, 12, 'BCS/');
        $pdf->text($L + 58, 20, 'Item:');
        $pdf->setFont('', 7.5)->cell($L, 8, $W, 9, 'Republic of the Philippines', 'C');
        $pdf->cell($L, 16, $W, 9, 'Department of Finance', 'C');
        $pdf->setFont('B', 8)->cell($L, 24, $W, 9, 'Bureau of Internal Revenue', 'C');
        $hy = 34;
        $pdf->setLineWidth(1.2)->rect($L, $hy, $W, 44)->setLineWidth(0.6);
        $pdf->line($L + 112, $hy, $L + 112, $hy + 44);
        $pdf->line($R - 132, $hy, $R - 132, $hy + 44);
        $pdf->setFont('', 6.5)->cell($L, $hy + 2, 112, 8, 'BIR Form No.', 'C');
        $pdf->setFont('B', 20)->cell($L, $hy + 10, 112, 20, '2316', 'C');
        $pdf->setFont('', 6.3)->cell($L, $hy + 32, 112, 9, 'September 2021 (ENCS)', 'C');
        $pdf->setFont('B', 13)->cell($L + 112, $hy + 3, $W - 244, 14, 'Certificate of Compensation', 'C');
        $pdf->cell($L + 112, $hy + 16, $W - 244, 14, 'Payment/Tax Withheld', 'C');
        $pdf->setFont('', 7.3)->cell($L + 112, $hy + 32, $W - 244, 9, 'For Compensation Payment With or Without Tax Withheld', 'C');
        $pdf->setLineWidth(0.5);
        for ($i = 0; $i < 46; $i++) {
            $bx = $R - 124 + $i * 2.5;
            $pdf->line($bx, $hy + 6, $bx, $hy + 30 - ($i % 4 === 1 ? 4 : 0));
        }
        $pdf->setLineWidth(0.6);
        $pdf->setFont('', 6.5)->cell($R - 132, $hy + 32, 132, 9, '2316 09/21ENCS', 'C');
        $y = $hy + 44;
        $pdf->rect($L, $y, $W, 10);
        $pdf->setFont('', 6.3)->text($L + 3, $y + 2, 'Fill in all applicable spaces. Mark all appropriate boxes with an "X"');
        $y += 10;

        // ---------------------------------------------------------------- items 1 and 2
        self::g21($pdf, $L, $y, $LW, 24);
        self::g21($pdf, $RX, $y, $RW, 24);
        self::lbl21($pdf, $L + 3, $y + 3, '1', 'For the Year');
        $pdf->setFont('', 6.3)->text($L + 22, $y + 13, '(YYYY)');
        self::box21($pdf, $L + 110, $y + 5, 64, 14, (string)$p['year'], 'C', 8.5, 4);
        self::lbl21($pdf, $RX + 3, $y + 3, '2', 'For the Period');
        $pdf->setFont('', 6.3)->text($RX + 22, $y + 13, 'From');
        $pdf->text($RX + 44, $y + 13, '(MM/DD)');
        self::box21($pdf, $RX + 116, $y + 6, 22, 13, fmt_date($p['period_from'], 'm'), 'C', 8, 2);
        self::box21($pdf, $RX + 138, $y + 6, 22, 13, fmt_date($p['period_from'], 'd'), 'C', 8, 2);
        $pdf->setFont('', 6.3)->text($RX + 172, $y + 13, 'To');
        $pdf->text($RX + 184, $y + 13, '(MM/DD)');
        self::box21($pdf, $RX + 240, $y + 6, 22, 13, fmt_date($p['period_to'], 'm'), 'C', 8, 2);
        self::box21($pdf, $RX + 262, $y + 6, 22, 13, fmt_date($p['period_to'], 'd'), 'C', 8, 2);
        $y += 24;
        $bar = function ($x, $yy, $w, $text) use ($pdf) {
            $pdf->fillRgb(255, 255, 255)->rect($x, $yy, $w, 10, 'FD')->fillRgb(0, 0, 0);
            $pdf->setFont('B', 7)->cell($x, $yy, $w, 10, $text, 'C');
        };
        $bar($L, $y, $LW, 'Part I - Employee Information');
        $bar($RX, $y, $RW, 'Part IV-B Details of Compensation Income and Tax Withheld from Present Employer');
        $y += 10;
        $top = $y;

        // ---------------------------------------------------------------- left column: parts I to III
        $boxR = $L + $LW - 4;
        $zipW = 56;
        $yl = $top;
        self::g21($pdf, $L, $yl, $LW, 19);
        self::lbl21($pdf, $L + 3, $yl + 3, '3', 'TIN');
        self::tin21($pdf, $boxR - 181, $yl + 3, $e['tin'], $e['branch']);
        $yl += 19;
        $addr = function ($num, $label, $zipNum, $val, $zip) use ($pdf, $L, $LW, $boxR, $zipW, &$yl) {
            self::g21($pdf, $L, $yl, $LW, 25);
            self::lbl21($pdf, $L + 3, $yl + 2, $num, $label);
            self::lbl21($pdf, $boxR - $zipW, $yl + 2, $zipNum, $zipNum === '5' ? 'RDO Code' : 'Zip Code');
            self::box21($pdf, $L + 6, $yl + 10, $LW - $zipW - 16, 13, $val, 'L', 8.5);
            self::box21($pdf, $boxR - $zipW, $yl + 10, $zipW, 13, $zip, 'C', 8.5, $zipNum === '5' ? 3 : 4);
            $yl += 25;
        };
        $addr('4', "Employee's Name (Last Name, First Name, Middle Name)", '5', strtoupper($e['name']), isset($e['rdo']) ? $e['rdo'] : '');
        $addr('6', 'Registered Address', '6A', strtoupper((string)$e['address']), (string)$e['zip']);
        $addr('6B', 'Local Home Address', '6C', isset($e['local_address']) ? strtoupper($e['local_address']) : '', isset($e['local_zip']) ? $e['local_zip'] : '');
        $addr('6D', 'Foreign Address', '6E', isset($e['foreign_address']) ? strtoupper($e['foreign_address']) : '', isset($e['foreign_zip']) ? $e['foreign_zip'] : '');
        self::g21($pdf, $L, $yl, $LW, 25);
        $pdf->line($L + 150, $yl, $L + 150, $yl + 25);
        self::lbl21($pdf, $L + 3, $yl + 2, '7', 'Date of Birth (MM/DD/YYYY)');
        self::date21($pdf, $L + 6, $yl + 10, $e['birth']);
        self::lbl21($pdf, $L + 153, $yl + 2, '8', 'Telephone Number');
        self::box21($pdf, $L + 156, $yl + 10, $LW - 160, 13, (string)$e['contact'], 'L', 8.5);
        $yl += 25;
        foreach (array(array('9', 'Statutory Minimum Wage rate per day', $a['smw_per_day']), array('10', 'Statutory Minimum Wage rate per month', $a['smw_per_month'])) as $r) {
            self::g21($pdf, $L, $yl, $LW, 19);
            self::lbl21($pdf, $L + 3, $yl + 3, $r[0], $r[1]);
            self::box21($pdf, $boxR - 112, $yl + 3, 112, 13, $r[2] ? self::money21($r[2]) : '', 'R', 8.5);
            $yl += 19;
        }
        self::g21($pdf, $L, $yl, $LW, 24);
        $pdf->setFont('B', 6.6)->text($L + 3, $yl + 4, '11');
        self::box21($pdf, $L + 17, $yl + 4, 18, 11, $e['mwe'] ? 'X' : '', 'C', 8, 0, 'B');
        $pdf->setFont('', 6.4)->para($L + 40, $yl + 3, $LW - 46, 'Minimum Wage Earner (MWE) whose compensation is exempt from withholding tax and not subject to income tax', 8);
        $yl += 24;
        $bar($L, $yl, $LW, 'Part II - Employer Information (Present)');
        $yl += 10;
        self::g21($pdf, $L, $yl, $LW, 19);
        self::lbl21($pdf, $L + 3, $yl + 3, '12', 'TIN');
        self::tin21($pdf, $boxR - 181, $yl + 3, $er['tin'], $er['branch']);
        $yl += 19;
        self::g21($pdf, $L, $yl, $LW, 25);
        self::lbl21($pdf, $L + 3, $yl + 2, '13', "Employer's Name");
        self::box21($pdf, $L + 6, $yl + 10, $LW - 10, 13, strtoupper($er['name']), 'L', 8.5);
        $yl += 25;
        $addrEr = function ($num, $zipNum, $val, $zip) use ($pdf, $L, $LW, $boxR, $zipW, &$yl) {
            self::g21($pdf, $L, $yl, $LW, 25);
            self::lbl21($pdf, $L + 3, $yl + 2, $num, 'Registered Address');
            self::lbl21($pdf, $boxR - $zipW, $yl + 2, $zipNum, 'Zip Code');
            self::box21($pdf, $L + 6, $yl + 10, $LW - $zipW - 16, 13, $val, 'L', 8.5);
            self::box21($pdf, $boxR - $zipW, $yl + 10, $zipW, 13, $zip, 'C', 8.5, 4);
            $yl += 25;
        };
        $addrEr('14', '14A', strtoupper((string)$er['address']), (string)$er['zip']);
        self::g21($pdf, $L, $yl, $LW, 18);
        self::lbl21($pdf, $L + 3, $yl + 4, '15', 'Type of Employer');
        $secondary = !empty($p['secondary_employer']);
        self::box21($pdf, $L + 110, $yl + 3, 13, 12, $secondary ? '' : 'X', 'C', 8, 0, 'B');
        $pdf->setFont('', 6.6)->text($L + 127, $yl + 4, 'Main Employer');
        self::box21($pdf, $L + 186, $yl + 3, 13, 12, $secondary ? 'X' : '', 'C', 8, 0, 'B');
        $pdf->setFont('', 6.6)->text($L + 203, $yl + 4, 'Secondary Employer');
        $yl += 18;
        $bar($L, $yl, $LW, 'Part III - Employer Information (Previous)');
        $yl += 10;
        $pv = isset($p['previous']) ? $p['previous'] : array('tin' => '', 'branch' => '', 'name' => '', 'address' => '', 'zip' => '');
        self::g21($pdf, $L, $yl, $LW, 19);
        self::lbl21($pdf, $L + 3, $yl + 3, '16', 'TIN');
        self::tin21($pdf, $boxR - 181, $yl + 3, $pv['tin'], $pv['branch']);
        $yl += 19;
        self::g21($pdf, $L, $yl, $LW, 25);
        self::lbl21($pdf, $L + 3, $yl + 2, '17', "Employer's Name");
        self::box21($pdf, $L + 6, $yl + 10, $LW - 10, 13, strtoupper($pv['name']), 'L', 8.5);
        $yl += 25;
        $addrEr('18', '18A', strtoupper($pv['address']), $pv['zip']);
        $bar($L, $yl, $LW, 'Part IVA - Summary');
        $yl += 10;

        // Part IV-A summary rows: item, first label line, second label line, italic part, amount, row height
        $tw = $a['tax_withheld_adjusted'] + $a['prev_tax_withheld'];
        $sum = array(
            array('19', 'Gross Compensation Income from Present', 'Employer', '(Sum of Items 38 and 52)', $a['pres_gross'], 22),
            array('20', 'Less: Total Non-Taxable/Exempt Compensation', 'Income from Present Employer (From Item 38)', '', $a['pres_nt_total'], 22),
            array('21', 'Taxable Compensation Income from Present', 'Employer (Item 19 Less Item 20) (From Item 52)', '', $a['pres_tx_total'], 22),
            array('22', 'Add: Taxable Compensation Income from', 'Previous Employer, if applicable', '', $a['prev_tx_total'], 22),
            array('23', 'Gross Taxable Compensation Income', '', '(Sum of Items 21 and 22)', $a['taxable_comp_total'], 22),
            array('24', 'Tax Due', '', '', $a['tax_due'], 19),
            array('25', 'Amount of Taxes Withheld', '', '', null, 11),
            array('25A', 'Present Employer', '', '', $a['tax_withheld_adjusted'], 19),
            array('25B', 'Previous Employer', '', '', $a['prev_tax_withheld'], 19),
            array('26', 'Total Amount of Taxes Withheld as adjusted', '', '(Sum of Items 25A and 25B)', $tw, 22),
            array('27', '5% Tax Credit (PERA Act of 2008)', '', '', $a['pera_credit'], 19),
            array('28', 'Total Taxes Withheld (sum of Items 26 and 27)', '', '', $tw + $a['pera_credit'], 19),
        );
        $sumTop = $yl;
        foreach ($sum as $r) {
            $yl += $r[5];
        }
        self::g21($pdf, $L, $sumTop, $LW, $yl - $sumTop);
        $yy = $sumTop;
        foreach ($sum as $r) {
            $lx = $L + 3;
            if (in_array($r[0], array('25A', '25B'), true)) {
                $lx += 12;
            }
            if ($r[1] !== '') {
                if ($r[2] === '' && $r[3] !== '' && $r[5] > 19) {
                    self::lbl21($pdf, $lx, $yy + 3, $r[0], $r[1]);
                    $pdf->setFont('BI', 6.4)->text($L + 18, $yy + 12, $r[3]);
                } else {
                    self::lbl21($pdf, $lx, $yy + 3, $r[0], $r[1]);
                    if ($r[2] !== '') {
                        $pdf->setFont('', 6.6)->text($L + 18, $yy + 12, $r[2]);
                        if ($r[3] !== '') {
                            $pdf->setFont('BI', 6.4)->text($L + 20 + $pdf->strWidth($r[2], '', 6.6), $yy + 12, $r[3]);
                        }
                    }
                }
            }
            if ($r[4] !== null) {
                self::box21($pdf, $boxR - 120, $yy + 3, 120, 13, self::money21($r[4]), 'R', 8.5);
            }
            $yy += $r[5];
        }

        // ---------------------------------------------------------------- right column: part IV-B
        $vx = $L + $W - 118;
        $yr = $top;
        $rowsR = array(
            array('h', 'A.  NON-TAXABLE/EXEMPT COMPENSATION INCOME', 16),
            array('amt', 'Amount', 10),
            array('29', 'Basic Salary (including the exempt P250,000 &', 'below) or the Statutory Minimum Wage of the MWE', '', $a['pres_nt_smw'], 24, true),
            array('30', 'Holiday Pay (MWE)', '', '', $a['mwe_holiday_pay'], 19, true),
            array('31', 'Overtime Pay (MWE)', '', '', $a['mwe_overtime_pay'], 19, true),
            array('32', 'Night Shift Differential (MWE)', '', '', $a['mwe_night_diff'], 19, true),
            array('33', 'Hazard Pay (MWE)', '', '', $a['mwe_hazard_pay'], 19, true),
            array('34', '13th Month Pay and Other Benefits', '', '(maximum of P90,000)', $a['pres_nt_13th'], 24, true),
            array('35', 'De Minimis Benefits', '', '', $a['pres_nt_deminimis'], 19, true),
            array('36', 'SSS, GSIS, PHIC & PAG-IBIG Contributions', 'and Union Dues (Employee share only)', '', $a['pres_nt_contrib'], 24, true),
            array('37', 'Salaries and Other Forms of Compensation', '', '', $a['pres_nt_other'], 19, true),
            array('38', 'Total Non-Taxable/Exempt Compensation', 'Income', '(Sum of Items 29 to 37)', $a['pres_nt_total'], 24, true),
            array('h', 'B.  TAXABLE COMPENSATION INCOME REGULAR', 17),
            array('39', 'Basic Salary', '', '', $a['pres_tx_basic'], 19, true),
            array('40', 'Representation', '', '', 0, 19, false),
            array('41', 'Transportation', '', '', 0, 19, false),
            array('42', 'Cost of Living Allowance (COLA)', '', '', 0, 19, false),
            array('43', 'Fixed Housing Allowance', '', '', 0, 19, false),
            array('spec', '44', 'Others', 12),
            array('44A', '', '', '', 0, 19, true),
            array('44B', '', '', '', 0, 19, false),
            array('h', 'SUPPLEMENTARY', 17),
            array('45', 'Commission', '', '', 0, 19, false),
            array('46', 'Profit Sharing', '', '', 0, 19, false),
            array('47', "Fees Including Director's Fees", '', '', 0, 19, false),
            array('48', 'Taxable 13th Month Pay Benefits', '', '', $a['pres_tx_13th'], 19, true),
            array('49', 'Hazard Pay', '', '', 0, 19, false),
            array('50', 'Overtime Pay', '', '', 0, 19, false),
            array('spec', '51', 'Others', 12),
            array('51A', $a['pres_tx_other'] ? 'Other compensation' : '', '', '', $a['pres_tx_other'], 19, (bool)$a['pres_tx_other']),
            array('51B', '', '', '', 0, 19, false),
            array('52', 'Total Taxable Compensation Income', '', '(Sum of Items 39 to 51B)', $a['pres_tx_total'], 26, true),
        );
        self::g21($pdf, $RX, $top, $RW, $yl - $top);
        foreach ($rowsR as $r) {
            if ($r[0] === 'h') {
                $pdf->setFont('B', 7)->text($RX + 4, $yr + 5, $r[1]);
                $yr += $r[2];
                continue;
            }
            if ($r[0] === 'amt') {
                $pdf->setFont('B', 6.8)->cell($vx, $yr, 114, 10, $r[1], 'C');
                $yr += $r[2];
                continue;
            }
            if ($r[0] === 'spec') {
                self::lbl21($pdf, $RX + 3, $yr + 2, $r[1], $r[2], 6.6, '(Specify)');
                $yr += $r[3];
                continue;
            }
            if (in_array($r[0], array('44A', '44B', '51A', '51B'), true)) {
                $pdf->setFont('B', 6.6)->text($RX + 16, $yr + 6, $r[0]);
                self::box21($pdf, $RX + 34, $yr + 3, 118, 14, $r[1], 'L', 7.5);
            } elseif ($r[2] === '' && $r[3] !== '' && $r[5] > 19) {
                self::lbl21($pdf, $RX + 3, $yr + 3, $r[0], $r[1]);
                $pdf->setFont('BI', 6.4)->text($RX + 18, $yr + 12, $r[3]);
            } else {
                self::lbl21($pdf, $RX + 3, $yr + 3, $r[0], $r[1]);
                if ($r[2] !== '') {
                    $pdf->setFont('', 6.6)->text($RX + 18, $yr + 12, $r[2]);
                    if ($r[3] !== '') {
                        $pdf->setFont('BI', 6.4)->text($RX + 20 + $pdf->strWidth($r[2], '', 6.6), $yr + 12, $r[3]);
                    }
                }
            }
            self::box21($pdf, $vx, $yr + 3, 114, 13, $r[6] ? self::money21($r[4]) : ($r[4] ? self::money21($r[4]) : ''), 'R', 8.5);
            $yr += $r[5];
        }
        $pdf->setLineWidth(1.2)->rect($L, $top - 10, $W, $yl - $top + 10)->setLineWidth(0.6);
        $pdf->setLineWidth(1)->line($RX, $top - 10, $RX, $yl)->setLineWidth(0.6);

        // ---------------------------------------------------------------- declaration and signatures
        $y = $yl;
        self::g21($pdf, $L, $y, $W, 25);
        $pdf->setFont('', 5.9)->para($L + 6, $y + 2.5, $W - 12, 'I/We declare, under the penalties of perjury, that this certificate has been made in good faith, verified by me/us, and to the best of my/our knowledge and belief, is true and correct pursuant to the provisions of the National Internal Revenue Code, as amended, and the regulations issued under authority thereof. Further, I/we give my/our consent to the processing of my/our information as contemplated under the *Data Privacy Act of 2012 (R.A. No. 10173) for legitimate and lawful purposes.', 6.9);
        $y += 25;
        $sigTop = $y;
        $signer = !empty($p['signatory']) ? strtoupper($p['signatory']['name']) : '';
        $empName = self::printName21($e);
        $sigLine = function ($yy, $num, $name, $caption, $withImage) use ($pdf, $L, $p) {
            if ($withImage && !empty($p['use_signature']) && !empty($p['signature_path']) && is_file(storage_path($p['signature_path']))) {
                try {
                    $pdf->image(storage_path($p['signature_path']), $L + 170 + (float)$p['offset_x'], $yy - 10 + (float)$p['offset_y'], 96, 22);
                } catch (Exception $ex) {
                    // a signature image problem never blocks printing; the name prints regardless
                }
            }
            $pdf->setFont('B', 7.5)->text($L + 26, $yy + 3, $num);
            $pdf->setFont('', 8.5)->cell($L + 76, $yy, 300, 11, $name, 'C');
            $pdf->line($L + 76, $yy + 11, $L + 376, $yy + 11);
            $pdf->setFont('', 6)->cell($L + 76, $yy + 11.5, 300, 8, $caption, 'C');
        };
        $sigLine($y + 4, '51', $signer, 'Present Employer/Authorized Agent Signature Over Printed Name', true);
        $pdf->setFont('', 6.4)->text($L + 404, $y + 7, 'Date Signed');
        self::date21($pdf, $L + 446, $y + 4, '', 12);
        $y += 26;
        $pdf->setFont('B', 7)->text($L + 12, $y, 'CONFORME:');
        $y += 8;
        $sigLine($y + 2, '52', $empName, 'Employee Signature Over Printed Name', false);
        $pdf->setFont('', 6.4)->text($L + 404, $y + 5, 'Date Signed');
        self::date21($pdf, $L + 446, $y + 2, '', 12);
        $y += 24;
        $pdf->setFont('', 6)->text($L + 12, $y + 1, 'CTC/Valid ID No.');
        $pdf->setFont('I', 5.6)->text($L + 12, $y + 8, 'of Employee');
        self::box21($pdf, $L + 66, $y + 1, 110, 12, '', 'L');
        $pdf->setFont('', 6)->text($L + 184, $y + 1, 'Place of');
        $pdf->text($L + 184, $y + 8, 'Issue');
        self::box21($pdf, $L + 212, $y + 1, 120, 12, '', 'L');
        $pdf->setFont('', 6.4)->text($L + 404, $y + 4, 'Date of Issue');
        self::date21($pdf, $L + 446, $y + 1, '', 12);
        $pdf->setFont('', 5.8)->cell($R - 50, $y - 8, 48, 8, 'Amount Paid, if CTC', 'C');
        self::box21($pdf, $R - 50, $y + 1, 46, 12, '', 'R');
        $y += 17;
        $pdf->setLineWidth(1)->rect($L, $sigTop, $W, $y - $sigTop)->setLineWidth(0.6);
        $pdf->setLineWidth(1)->rect($L, $y, $W, 11)->setLineWidth(0.6);
        $pdf->setFont('B', 7.3)->cell($L, $y, $W, 11, 'To be accomplished under substituted filing', 'C');
        $y += 11;
        $subH = 68;
        $half = $W / 2;
        $pdf->setLineWidth(1)->rect($L, $y, $W, $subH)->line($L + $half, $y, $L + $half, $y + $subH)->setLineWidth(0.6);
        $pdf->setFont('', 5.9)->para($L + 6, $y + 3, $half - 12, 'I declare, under the penalties of perjury, that the information herein stated are reported under BIR Form No. 1604-C which has been filed with the Bureau of Internal Revenue.', 7);
        $pdf->para($L + $half + 6, $y + 3, $half - 12, 'I declare, under the penalties of perjury that I am qualified under substituted filing of Income Tax Returns (BIR Form No. 1700), since I received purely compensation income from only one employer in the Philippines for the calendar year; that taxes have been correctly withheld by my employer (tax due equals tax withheld); that the BIR Form No. 1604-C filed by my employer to the BIR shall constitute as my income tax return; and that BIR Form No. 2316 shall serve the same purpose as if BIR Form No. 1700 had been filed pursuant to the provisions of Revenue Regulations (RR) No. 3-2002, as amended.', 6.8);
        $sub = !empty($p['substituted']);
        $sy = $y + 27;
        if ($sub && !empty($p['use_signature']) && !empty($p['signature_path']) && is_file(storage_path($p['signature_path']))) {
            try {
                $pdf->image(storage_path($p['signature_path']), $L + 96 + (float)$p['offset_x'], $sy - 12 + (float)$p['offset_y'], 96, 22);
            } catch (Exception $ex) {
            }
        }
        $pdf->setFont('B', 7.5)->text($L + 22, $sy + 3, '53');
        $pdf->setFont('', 8.5)->cell($L + 40, $sy, $half - 60, 11, $sub ? $signer : '', 'C');
        $pdf->line($L + 40, $sy + 11, $L + $half - 20, $sy + 11);
        $pdf->setFont('', 6)->cell($L + 20, $sy + 12, $half - 40, 8, 'Present Employer/Authorized Agent Signature Over Printed Name', 'C');
        $pdf->cell($L + 20, $sy + 19, $half - 40, 8, '(Head of Accounting/Human Resource or Authorized Representative)', 'C');
        $ey = $y + $subH - 21;
        $pdf->setFont('B', 7.5)->text($L + $half + 22, $ey + 3, '54');
        $pdf->setFont('', 8.5)->cell($L + $half + 40, $ey, $half - 60, 11, $sub ? $empName : '', 'C');
        $pdf->line($L + $half + 40, $ey + 11, $L + $W - 20, $ey + 11);
        $pdf->setFont('', 6)->cell($L + $half + 40, $ey + 12, $half - 60, 8, 'Employee Signature Over Printed Name', 'C');
        $y += $subH;
        $pdf->setFont('', 6.2)->text($L, $y + 3, '*NOTE: The BIR Data Privacy is in the BIR website (www.bir.gov.ph)');
        $pdf->setFont('', 5.2)->fillRgb(90, 90, 90);
        $foot = 'Certificate ' . $p['cert_no'] . ', revision ' . $p['revision'] . '. ' . self::REV_2316 . '. Generated ' . date('m/d/Y H:i') . ' by G-SMaRT BIR Integration System.';
        $pdf->text($R - $pdf->strWidth($foot, '', 5.2), $y + 4, $foot);
        $pdf->fillRgb(0, 0, 0);
        $pdf->endScale();
    }
}
