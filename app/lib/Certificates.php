<?php
/**
 * BIR Forms 2307, 2306 and 2316: payloads from saved records, numbered register,
 * void and reissue, and print-ready PDF rendering (single, combined or ZIP of individual files).
 *
 * The PDF is the form as the BIR Alphalist Data Entry and Validation Module version 7.4 prints it:
 * 2307 and 2306 January 2018 (ENCS), 2316 September 2021 (ENCS), each with the module's PDF417 barcode.
 * The template revision string is stored with every certificate.
 */
class Certificates
{
    const REV_2307 = '2307 January 2018 (ENCS), BIR v7.4';
    const REV_2306 = '2306 January 2018 (ENCS), BIR v7.4';
    const REV_2316 = '2316 September 2021 (ENCS), BIR v7.4';

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
            $paper = isset($opts['paper']) && array_key_exists($opts['paper'], self::papers()) ? $opts['paper'] : self::defaultPaper($form);
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
    /*
     * The three forms are drawn the way the BIR Alphalist Data Entry and Validation Module version 7.4
     * prints them: the blank form comes from the module's own templates (2316(1).xltx, 2307.xltx and
     * 2306_1.xltx, see app/specs/bir_forms.php), with the BIR seal and the PDF417 barcode the module
     * prints, and each value goes into the box the module writes it to, named as in the module.
     */
    public static function render($pdf, $p, $tplRev, $void = false)
    {
        if ((string)$p['form'] === '2316') {
            self::draw2316($pdf, $p);
        } else {
            self::drawWithholding($pdf, $p);
        }
        if ($void) {
            $pdf->strokeRgb(180, 30, 30)->setLineWidth(3);
            $pdf->setFont('B', 60)->fillRgb(200, 40, 40);
            $pdf->text($pdf->width() / 2 - 80, $pdf->height() / 2 - 40, 'VOID');
            $pdf->fillRgb(0, 0, 0)->strokeRgb(0, 0, 0)->setLineWidth(0.6);
        }
    }

    /** Paper the BIR module prints each form on. */
    public static function defaultPaper($form)
    {
        return (string)$form === '2307' ? 'LEGAL' : 'FOLIO';
    }

    public static function papers()
    {
        return array('LEGAL' => 'Legal (8.5 x 14 in)', 'FOLIO' => 'Long / folio (8.5 x 13 in)', 'A4' => 'A4', 'LETTER' => 'Letter (8.5 x 11 in)');
    }

    private static $formSpecs = null;

    public static function formSpec($code)
    {
        if (self::$formSpecs === null) {
            self::$formSpecs = require APP_PATH . '/specs/bir_forms.php';
        }
        if (!isset(self::$formSpecs[$code])) {
            throw new RuntimeException('No print layout for BIR Form ' . $code . '.');
        }
        return self::$formSpecs[$code];
    }

    /**
     * Start a page with the blank form. It keeps the template's own position when the paper is the
     * module's paper; on other paper it is scaled to fit inside a 12 point margin. Close with endScale().
     */
    private static function blankForm($pdf, $code)
    {
        $s = self::formSpec($code);
        $pdf->addPage();
        $m = 12;
        list($x0, $top, $x1, $bottom) = $s['bbox'];
        $pw = $pdf->width();
        $ph = $pdf->height();
        $scale = min(1, ($pw - 2 * $m) / ($x1 - $x0), ($ph - 2 * $m) / ($bottom - $top));
        $ox = ($pw - ($x1 - $x0) * $scale) / 2 - $x0 * $scale;
        $oy = ($scale == 1 && $top >= $m && $bottom <= $ph - $m) ? 0 : $m - $top * $scale;
        $pdf->beginScale($scale, $ox, $oy);
        $pdf->raw(file_get_contents(APP_PATH . '/specs/forms/' . $s['bg']), $s['size'][1]);
        list($sx, $sy, $sw, $sh) = $s['seal'];
        $pdf->image(APP_PATH . '/specs/forms/bir_seal.png', $sx, $sy, $sw, $sh);
        $b = $s['barcode'];
        $pdf->pdf417($b['rows'], $b['box'][0], $b['box'][1], $b['box'][2], $b['box'][3], $b['px']);
        return $s;
    }

    /** Box of a named shape of the form: x, y, width, height. */
    private static function box($s, $name)
    {
        if (!isset($s['fields'][$name])) {
            throw new RuntimeException('The form layout has no box named ' . $name . '.');
        }
        $f = $s['fields'][$name];
        return array($f[0], $f[1], $f[2] - $f[0], $f[3] - $f[1]);
    }

    /**
     * Write into a named box, centred vertically, Arial bold 10 like the module unless told otherwise.
     * Left-aligned text too long for one line wraps to two, as the module's text boxes do, before the
     * font is made smaller.
     */
    private static function put($pdf, $s, $name, $text, $align = 'L', $size = 10, $style = 'B')
    {
        $text = trim(preg_replace('/\s+/', ' ', (string)$text));
        if ($text === '') {
            return;
        }
        list($x, $y, $w, $h) = self::box($s, $name);
        $pad = $align === 'C' ? 0.5 : 1.8;
        $avail = $w - 2 * $pad;
        if ($align === 'L' && $pdf->strWidth($text, $style, $size) > $avail && strpos($text, ' ') !== false) {
            for ($sz = min($size, 8); $sz >= 6; $sz -= 0.5) {
                $lines = self::wrapTwo($pdf, $text, $style, $sz, $avail);
                if ($lines) {
                    $lead = $sz * 1.05;
                    $ty = $y + ($h - 2 * $lead) / 2;
                    $pdf->setFont($style, $sz);
                    $pdf->cell($x, $ty, $w, $lead, $lines[0], 'L', $pad);
                    $pdf->cell($x, $ty + $lead, $w, $lead, $lines[1], 'L', $pad);
                    return;
                }
            }
        }
        $pdf->setFont($style, $size)->cell($x, $y, $w, $h, $text, $align, $pad);
    }

    /** Split at a space into two lines that each fit $avail, or return null. */
    private static function wrapTwo($pdf, $text, $style, $size, $avail)
    {
        $words = explode(' ', $text);
        $first = '';
        foreach ($words as $i => $word) {
            $try = $first === '' ? $word : $first . ' ' . $word;
            if ($pdf->strWidth($try, $style, $size) > $avail) {
                $rest = implode(' ', array_slice($words, $i));
                return $first !== '' && $pdf->strWidth($rest, $style, $size) <= $avail ? array($first, $rest) : null;
            }
            $first = $try;
        }
        return null;
    }

    /** Write into part of a named box: $from and $to are fractions of its width. */
    private static function putPart($pdf, $s, $name, $from, $to, $text, $size = 10)
    {
        $text = trim((string)$text);
        if ($text === '') {
            return;
        }
        list($x, $y, $w, $h) = self::box($s, $name);
        $pdf->setFont('B', $size)->cell($x + $w * $from, $y, $w * ($to - $from), $h, $text, 'C', 0.5);
    }

    private static function tinParts($tin, $branch)
    {
        $tin = preg_replace('/\D/', '', (string)$tin);
        if (strlen($tin) < 9) {
            return array('', '', '', '');
        }
        return array(substr($tin, 0, 3), substr($tin, 3, 3), substr($tin, 6, 3), $branch === null || $branch === '' ? '0000' : str_pad((string)$branch, 4, '0', STR_PAD_LEFT));
    }

    /** "TALACAY, NAZARIO REY ARENDAYEN" printed as "NAZARIO REY ARENDAYEN TALACAY", as the module prints the signature names. */
    private static function firstNameFirst($e)
    {
        if (!empty($e['first_name']) || !empty($e['last_name'])) {
            return strtoupper(trim(preg_replace('/\s+/', ' ', $e['first_name'] . ' ' . (isset($e['middle_name']) ? $e['middle_name'] : '') . ' ' . $e['last_name'])));
        }
        $parts = explode(',', (string)$e['name'], 2);
        return strtoupper(trim(count($parts) === 2 ? trim($parts[1]) . ' ' . trim($parts[0]) : $parts[0]));
    }

    /** Amount as the module prints it: 15,000.00, and 0.00 for nothing. */
    private static function amt($c)
    {
        return Money::fmt((int)$c);
    }

    private static function signatoryLine($p)
    {
        if (empty($p['signatory'])) {
            return '';
        }
        $s = $p['signatory'];
        return strtoupper($s['name']) . ($s['title'] ? ', ' . $s['title'] : '') . ($s['tin'] ? ', TIN ' . $s['tin'] : '');
    }

    private static function signatureImage($pdf, $p, $box)
    {
        if (empty($p['use_signature']) || empty($p['signature_path']) || !is_file(storage_path($p['signature_path']))) {
            return;
        }
        list($x, $y, $w, $h) = $box;
        try {
            // over the printed name, its foot just below the name box, as a hand signature sits on the line
            $pdf->image(storage_path($p['signature_path']), $x + $w / 2 - 50 + (float)$p['offset_x'], $y + $h - 19 + (float)$p['offset_y'], 100, 24);
        } catch (Exception $e) {
            // a signature image problem never blocks printing; the name prints regardless
        }
    }

    /** Certificate number and printing details, small, under the form. */
    private static function reference($pdf, $s, $p)
    {
        $t = 'Certificate ' . $p['cert_no'] . ', revision ' . $p['revision'] . '. Printed ' . date('m/d/Y H:i') . ' by G-SMaRT BIR Integration System.';
        $pdf->setFont('', 5.5)->fillRgb(110, 110, 110);
        $pdf->text($s['bbox'][2] - $pdf->strWidth($t), $s['bbox'][3] + 2, $t);
        $pdf->fillRgb(0, 0, 0);
    }

    // ------------------------------------------------------------------ 2307 and 2306
    /*
     * Income table geometry measured from the module's templates: column edges (x), the first row
     * (taller, the row the module writes to), the following rows, and the Total row.
     */
    private static function tables($form)
    {
        if ($form === '2306') {
            return array(
                'cols' => array(17.71, 232.69, 293.51, 419.02, 547.38),
                'sets' => array(array('first' => array(340.7, 351.5), 'step' => 10.78, 'rows' => 14, 'total' => array(491.6, 502.3))),
            );
        }
        return array(
            'cols' => array(24.48, 177.72, 228.8, 292.65, 356.5, 420.35, 484.2, 586.36),
            'sets' => array(
                array('first' => array(391.9, 412.9), 'step' => 11.47, 'rows' => 13, 'total' => array(550.5, 562.0)),
                array('first' => array(584.9, 606.0), 'step' => 10.82, 'rows' => 16, 'total' => array(768.3, 779.7)),
            ),
        );
    }

    private static function rowBox($set, $i)
    {
        if ($i === 0) {
            return $set['first'];
        }
        $y = $set['first'][1] + ($i - 1) * $set['step'];
        return array($y, $y + $set['step']);
    }

    /** Text in a table cell; the nature of payment wraps to as many lines as the row holds. */
    private static function tableCell($pdf, $x0, $x1, $y0, $y1, $text, $align, $size, $style = '')
    {
        $text = (string)$text;
        if ($text === '') {
            return;
        }
        $pdf->setFont($style, $size);
        $w = $x1 - $x0;
        if ($align !== 'W' || $pdf->strWidth($text) <= $w - 3) {
            $pdf->cell($x0, $y0, $w, $y1 - $y0, $text, $align === 'W' ? 'L' : $align, 1.8);
            return;
        }
        $lead = $size * 1.12;
        $max = max(1, (int)floor(($y1 - $y0 - 1) / $lead));
        $lines = array();
        $line = '';
        foreach (preg_split('/\s+/', trim($text)) as $word) {
            $try = $line === '' ? $word : $line . ' ' . $word;
            if ($pdf->strWidth($try) > $w - 3 && $line !== '') {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $try;
            }
        }
        $lines[] = $line;
        if (count($lines) > $max) {
            $lines = array_slice($lines, 0, $max);
            $lines[$max - 1] = rtrim($lines[$max - 1], ' ,;-') . '…';
        }
        $ty = $y0 + ($y1 - $y0 - count($lines) * $lead) / 2;
        foreach ($lines as $l) {
            $pdf->cell($x0, $ty, $w, $lead, $l, 'L', 1.8);
            $ty += $lead;
        }
    }

    private static function drawWithholding($pdf, $p)
    {
        $is2306 = (string)$p['form'] === '2306';
        $t = self::tables($p['form']);
        $c = $t['cols'];
        $groups = $is2306 ? array($p['lines']) : array($p['lines'], $p['biz']);
        $totals = $is2306 ? array($p['total']) : array($p['total'], $p['biz_total']);
        // lines that do not fit continue on another copy of the form; totals print on the last one
        $pages = 1;
        foreach ($groups as $g => $lines) {
            $pages = max($pages, (int)ceil(count($lines) / $t['sets'][$g]['rows']));
        }
        for ($pg = 0; $pg < $pages; $pg++) {
            $s = self::blankForm($pdf, $p['form']);
            $last = $pg === $pages - 1;
            $from = $p['period_from'];
            $to = $p['period_to'];
            $payee = $p['payee'];
            $payor = $p['payor'];
            if ($is2306) {
                self::put($pdf, $s, 'Rectangle 366', substr($from, 5, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 368', substr($from, 8, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 74', substr($from, 0, 4), 'C', 9);
                self::put($pdf, $s, 'Rectangle 386', substr($to, 5, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 387', substr($to, 8, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 388', substr($to, 0, 4), 'C', 9);
                $names = array('payee_tin' => array('Rectangle 80', 'Rectangle 84', 'Rectangle 87', 'Rectangle 92'),
                    'payor_tin' => array('Rectangle 95', 'Rectangle 97', 'Rectangle 98', 'Rectangle 91'),
                    'payee_name' => 'Rectangle 15', 'payee_addr' => 'Rectangle 323', 'payee_zip' => 'Rectangle 126', 'payee_foreign' => 'Rectangle 224',
                    'payor_name' => 'Rectangle 118', 'payor_addr' => 'Rectangle 115', 'payor_zip' => 'Rectangle 124');
            } else {
                self::put($pdf, $s, 'Rectangle 138', substr($from, 5, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 543', substr($from, 8, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 19', substr($from, 0, 4), 'C', 9);
                self::put($pdf, $s, 'Rectangle 292', substr($to, 5, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 541', substr($to, 8, 2), 'C', 9);
                self::put($pdf, $s, 'Rectangle 287', substr($to, 0, 4), 'C', 9);
                $names = array('payee_tin' => array('Rectangle 27', 'Rectangle 25', 'Rectangle 24', 'Rectangle 22'),
                    'payor_tin' => array('Rectangle 496', 'Rectangle 494', 'Rectangle 493', 'Rectangle 491'),
                    'payee_name' => 'Rectangle 540', 'payee_addr' => 'Rectangle 447', 'payee_zip' => 'Rectangle 448', 'payee_foreign' => 'Rectangle 453',
                    'payor_name' => 'Rectangle 526', 'payor_addr' => 'Rectangle 528', 'payor_zip' => 'Rectangle 529');
            }
            foreach (array('payee_tin' => $payee, 'payor_tin' => $payor) as $k => $who) {
                foreach (self::tinParts($who['tin'], $who['branch']) as $i => $part) {
                    self::put($pdf, $s, $names[$k][$i], $part, 'C', 9);
                }
            }
            self::put($pdf, $s, $names['payee_name'], strtoupper($payee['name']), 'L', 9);
            self::put($pdf, $s, $names['payee_addr'], strtoupper((string)$payee['address']), 'L', 9);
            self::put($pdf, $s, $names['payee_zip'], (string)$payee['zip'], 'C', 9);
            self::put($pdf, $s, $names['payee_foreign'], strtoupper((string)$payee['foreign']), 'L', 9);
            self::put($pdf, $s, $names['payor_name'], strtoupper($payor['name']), 'L', 9);
            self::put($pdf, $s, $names['payor_addr'], strtoupper((string)$payor['address']), 'L', 9);
            self::put($pdf, $s, $names['payor_zip'], (string)$payor['zip'], 'C', 9);

            foreach ($groups as $g => $lines) {
                $set = $t['sets'][$g];
                $chunk = array_slice($lines, $pg * $set['rows'], $set['rows']);
                foreach (array_values($chunk) as $i => $l) {
                    list($y0, $y1) = self::rowBox($set, $i);
                    $nature = $l['nature'];
                    if ($is2306) {
                        self::tableCell($pdf, $c[0], $c[1], $y0, $y1, $nature, 'W', 6.5);
                        self::tableCell($pdf, $c[1], $c[2], $y0, $y1, $l['atc'], 'C', 7.5, 'B');
                        self::tableCell($pdf, $c[2], $c[3], $y0, $y1, self::amt($l['total']), 'R', 7.5);
                        self::tableCell($pdf, $c[3], $c[4], $y0, $y1, self::amt($l['tax']), 'R', 7.5);
                    } else {
                        self::tableCell($pdf, $c[0], $c[1], $y0, $y1, $nature, 'W', 6);
                        self::tableCell($pdf, $c[1], $c[2], $y0, $y1, $l['atc'], 'C', 7.5, 'B');
                        for ($m = 0; $m < 3; $m++) {
                            self::tableCell($pdf, $c[2 + $m], $c[3 + $m], $y0, $y1, self::amt($l['m'][$m]), 'R', 7.5);
                        }
                        self::tableCell($pdf, $c[5], $c[6], $y0, $y1, self::amt($l['total']), 'R', 7.5, 'B');
                        self::tableCell($pdf, $c[6], $c[7], $y0, $y1, self::amt($l['tax']), 'R', 7.5, 'B');
                    }
                }
                list($y0, $y1) = $set['total'];
                $tot = $totals[$g];
                $ci = $is2306 ? array(2, 3) : array(5, 6);
                if ($last) {
                    // the module's Total cells use the accounting format: a dash for nothing
                    self::tableCell($pdf, $c[$ci[0]], $c[$ci[0] + 1], $y0, $y1, $tot['total'] ? self::amt($tot['total']) : '-', 'R', 7.5, 'B');
                    self::tableCell($pdf, $c[$ci[1]], $c[$ci[1] + 1], $y0, $y1, $tot['tax'] ? self::amt($tot['tax']) : '-', 'R', 7.5, 'B');
                } elseif (count($lines) > ($pg + 1) * $set['rows']) {
                    self::tableCell($pdf, $c[1], $c[$ci[0]], $y0, $y1, 'Continued on the next page', 'L', 6.5);
                }
            }

            $signer = self::signatoryLine($p);
            if ($is2306) {
                self::signatureImage($pdf, $p, self::box($s, 'TextBox 1'));
                self::put($pdf, $s, 'TextBox 1', $signer, 'C', 9);
                self::put($pdf, $s, 'TextBox 395', strtoupper($payor['name']), 'C', 9);
                self::put($pdf, $s, 'TextBox 399', strtoupper($payee['name']), 'C', 9);
            } else {
                self::signatureImage($pdf, $p, self::box($s, 'Text Box 562'));
                self::put($pdf, $s, 'Text Box 562', $signer, 'C', 9);
                self::put($pdf, $s, 'Text Box 564', strtoupper($payee['name']), 'C', 9);
            }
            self::reference($pdf, $s, $p);
            $pdf->endScale();
        }
    }

    // ------------------------------------------------------------------ 2316
    private static function draw2316($pdf, $p)
    {
        $s = self::blankForm($pdf, '2316');
        $a = $p['amounts'];
        $e = $p['employee'];
        $er = $p['employer'];

        // 1 and 2: year and period
        self::put($pdf, $s, 'Rectangle 17', (string)$p['year'], 'C');
        self::putPart($pdf, $s, 'Rectangle 93', 0, 0.508, fmt_date($p['period_from'], 'm'));
        self::putPart($pdf, $s, 'Rectangle 93', 0.508, 1, fmt_date($p['period_from'], 'd'));
        self::putPart($pdf, $s, 'Rectangle 92', 0, 0.537, fmt_date($p['period_to'], 'm'));
        self::putPart($pdf, $s, 'Rectangle 92', 0.537, 1, fmt_date($p['period_to'], 'd'));

        // Part I: employee
        foreach (self::tinParts($e['tin'], $e['branch']) as $i => $part) {
            self::put($pdf, $s, array('Rectangle 689', 'Rectangle 686', 'Rectangle 683', 'Rectangle 698')[$i], $part, 'C');
        }
        self::put($pdf, $s, 'Rectangle 131', strtoupper($e['name']));
        self::put($pdf, $s, 'Rectangle 243', (string)$er['rdo'], 'C');
        self::put($pdf, $s, 'Rectangle 420', strtoupper((string)$e['address']));
        self::put($pdf, $s, 'Rectangle 421', (string)$e['zip'], 'C');
        if (!empty($e['birth'])) {
            self::putPart($pdf, $s, 'Rectangle 248', 0, 0.204, substr($e['birth'], 5, 2));
            self::putPart($pdf, $s, 'Rectangle 248', 0.204, 0.524, substr($e['birth'], 8, 2));
            self::putPart($pdf, $s, 'Rectangle 248', 0.524, 1, substr($e['birth'], 0, 4));
        }
        self::put($pdf, $s, 'Rectangle 250', (string)$e['contact']);
        self::put($pdf, $s, 'Rectangle 635', self::amt($a['smw_per_day']), 'R');
        self::put($pdf, $s, 'Rectangle 636', self::amt($a['smw_per_month']), 'R');
        if (!empty($e['mwe'])) {
            self::put($pdf, $s, 'Text Box 676', 'X', 'C');
        }

        // Part II: present employer
        foreach (self::tinParts($er['tin'], $er['branch']) as $i => $part) {
            self::put($pdf, $s, array('Rectangle 709', 'Rectangle 706', 'Rectangle 703', 'Rectangle 718')[$i], $part, 'C');
        }
        self::put($pdf, $s, 'Rectangle 81', strtoupper($er['name']));
        self::put($pdf, $s, 'Rectangle 82', strtoupper((string)$er['address']));
        self::put($pdf, $s, 'Rectangle 135', (string)$er['zip'], 'C');
        self::put($pdf, $s, empty($p['secondary_employer']) ? 'Rectangle 212' : 'Rectangle 213', 'X', 'C');

        // Part III: previous employer, when the record names one
        if (!empty($p['previous'])) {
            $pv = $p['previous'];
            foreach (self::tinParts($pv['tin'], $pv['branch']) as $i => $part) {
                self::put($pdf, $s, array('Rectangle 729', 'Rectangle 726', 'Rectangle 723', 'Rectangle 738')[$i], $part, 'C');
            }
            self::put($pdf, $s, 'Rectangle 153', strtoupper($pv['name']));
            self::put($pdf, $s, 'Rectangle 173', strtoupper($pv['address']));
            self::put($pdf, $s, 'Rectangle 174', $pv['zip'], 'C');
        }

        // Part IV-A summary, as the module computes items 25A to 28
        $pres = max(0, $a['tax_withheld_adjusted']);
        $adjusted = max(0, $a['tax_withheld_adjusted'] + $a['prev_tax_withheld']);
        $sum = array(
            'Rectangle 557' => $a['pres_gross'], 'Rectangle 637' => $a['pres_nt_total'], 'Rectangle 638' => $a['pres_tx_total'],
            'Rectangle 639' => $a['prev_tx_total'], 'Rectangle 640' => $a['taxable_comp_total'], 'Rectangle 647' => $a['tax_due'],
            'Rectangle 656' => $pres, 'Rectangle 660' => max(0, $a['prev_tax_withheld']), 'Rectangle 665' => $adjusted,
            'Rectangle 1' => $a['pera_credit'], 'Rectangle 2' => $adjusted + $a['pera_credit'],
            // Part IV-B
            'Rectangle 521' => $a['pres_nt_smw'], 'Rectangle 789' => $a['mwe_holiday_pay'], 'Rectangle 790' => $a['mwe_overtime_pay'],
            'Rectangle 791' => $a['mwe_night_diff'], 'Rectangle 792' => $a['mwe_hazard_pay'], 'Rectangle 793' => $a['pres_nt_13th'],
            'Rectangle 782' => $a['pres_nt_deminimis'], 'Rectangle 779' => $a['pres_nt_contrib'], 'Rectangle 783' => $a['pres_nt_other'],
            'Rectangle 784' => $a['pres_nt_total'], 'Rectangle 785' => $a['pres_tx_basic'], 'Rectangle 533' => $a['pres_tx_other'],
            'Rectangle 555' => $a['pres_tx_13th'], 'Rectangle 671' => $a['pres_tx_total'],
        );
        foreach ($sum as $name => $v) {
            self::put($pdf, $s, $name, self::amt($v), 'R');
        }

        // 51 and 52 always; 53 and 54 only for an employee under substituted filing
        $signer = !empty($p['signatory']) ? strtoupper($p['signatory']['name']) : '';
        $empName = self::firstNameFirst($e);
        self::signatureImage($pdf, $p, self::box($s, 'Text Box 809'));
        self::put($pdf, $s, 'Text Box 809', $signer, 'C');
        self::put($pdf, $s, 'Text Box 810', $empName, 'C');
        if (!empty($p['substituted'])) {
            self::signatureImage($pdf, $p, self::box($s, 'Text Box 811'));
            self::put($pdf, $s, 'Text Box 811', $signer, 'C');
            self::put($pdf, $s, 'Text Box 808', $empName, 'C');
        }
        self::reference($pdf, $s, $p);
        $pdf->endScale();
    }
}
