<?php
/**
 * New clients uploaded from a CSV file. The file is read into a batch of rows that are checked one by
 * one and against each other; the user edits or deletes the rows that are flagged, and saves the batch
 * when no row has an error left. Saving adds each client with its head office branch (0000), the same
 * as the Add client form.
 */
class ClientImport
{
    const MAX_ROWS = 2000;

    /** key => array(column title, other titles it may have, help, example for the two sample rows) */
    public static function columns()
    {
        return array(
            'tin' => array('TIN', array('TIN NO', 'TIN NUMBER', 'TAXPAYER IDENTIFICATION NUMBER', 'TAX IDENTIFICATION NUMBER'),
                'Required. Nine digits; dashes are optional (123-456-789). A branch code after the TIN is not used: every client starts with its head office, 0000.', array('010-234-567', '301-765-432')),
            'taxpayer_type' => array('TAXPAYER TYPE', array('TYPE', 'TYPE OF TAXPAYER'),
                'Non-individual or Individual. Left blank, it is Individual when only the last and first names are given.', array('Non-individual', 'Individual')),
            'registered_name' => array('REGISTERED NAME', array('COMPANY NAME', 'CORPORATE NAME', 'NAME OF TAXPAYER', 'TAXPAYER NAME'),
                'Required for a non-individual, as registered with the BIR.', array('SAMPLE TRADING CORPORATION', '')),
            'last_name' => array('LAST NAME', array('SURNAME', 'FAMILY NAME'), 'Required for an individual.', array('', 'DELA CRUZ')),
            'first_name' => array('FIRST NAME', array('GIVEN NAME'), 'Required for an individual.', array('', 'JUAN')),
            'middle_name' => array('MIDDLE NAME', array(), 'Individual, optional.', array('', 'SANTOS')),
            'trade_name' => array('TRADE NAME', array('BUSINESS NAME', 'BUSINESS STYLE'), 'Optional.', array('SAMPLE MART', 'JDC HARDWARE')),
            'rdo_code' => array('RDO CODE', array('RDO', 'RDO NO', 'REVENUE DISTRICT OFFICE'),
                'Three digits (081) or digits and a letter (21B). The BIR file headers need it.', array('081', '21B')),
            'address1' => array('REGISTERED ADDRESS', array('ADDRESS', 'ADDRESS 1', 'STREET ADDRESS', 'BUSINESS ADDRESS'), 'Unit, street, barangay.', array('2F UNIT 3, 12 OSMENA BLVD, BRGY KALUBIHAN', '45 RIZAL ST, BRGY POBLACION')),
            'address2' => array('CITY OR MUNICIPALITY', array('CITY', 'MUNICIPALITY', 'ADDRESS 2', 'TOWN'), 'Optional.', array('CEBU CITY', 'TAGUM CITY')),
            'zip_code' => array('ZIP CODE', array('ZIP', 'POSTAL CODE'), 'Four digits.', array('6000', '8100')),
            'classification' => array('CLASSIFICATION', array('TAXPAYER CLASSIFICATION'),
                'Regular, Top withholding agent, Large taxpayer or Government. Blank means Regular.', array('Top withholding agent', 'Regular')),
            'vat_status' => array('VAT STATUS', array('VAT', 'VAT REGISTRATION'), 'VAT or Non-VAT. Blank means VAT.', array('VAT', 'Non-VAT')),
            'reporting_cycle' => array('ACCOUNTING PERIOD', array('PERIOD', 'CALENDAR OR FISCAL', 'ACCOUNTING YEAR'), 'Calendar or Fiscal. Blank means Calendar.', array('Fiscal', 'Calendar')),
            'fiscal_month_end' => array('FISCAL YEAR ENDS', array('FISCAL MONTH END', 'FISCAL YEAR END', 'FISCAL MONTH', 'YEAR END MONTH'),
                'For a fiscal year: the month it ends, as 1 to 12 or the month name. Ignored for a calendar year.', array('6', '')),
            'email' => array('EMAIL', array('EMAIL ADDRESS', 'E-MAIL'), 'Optional.', array('accounting@sample.ph', '')),
            'phone' => array('PHONE', array('PHONE NO', 'TELEPHONE', 'CONTACT NUMBER', 'MOBILE', 'CONTACT NO'), 'Optional.', array('(032) 255 1234', '0917 555 0101')),
        );
    }

    public static function labels()
    {
        $out = array();
        foreach (self::columns() as $k => $c) {
            $out[$k] = $c[0];
        }
        return $out;
    }

    public static function typeOptions()
    {
        return array('non_individual' => 'Non-individual', 'individual' => 'Individual');
    }

    public static function classOptions()
    {
        return array('regular' => 'Regular', 'top_withholding_agent' => 'Top withholding agent', 'large_taxpayer' => 'Large taxpayer', 'government' => 'Government office or GOCC');
    }

    public static function vatOptions()
    {
        return array('vat' => 'VAT-registered', 'non_vat' => 'Non-VAT');
    }

    public static function cycleOptions()
    {
        return array('calendar' => 'Calendar year', 'fiscal' => 'Fiscal year');
    }

    /** The CSV template: the header row, and with $sample two example clients. UTF-8 with a BOM so Excel keeps Ñ. */
    public static function template($sample = false)
    {
        $lines = array(array_values(self::labels()));
        if ($sample) {
            foreach (array(0, 1) as $i) {
                $r = array();
                foreach (self::columns() as $c) {
                    $r[] = $c[3][$i];
                }
                $lines[] = $r;
            }
        }
        $fh = fopen('php://temp', 'r+');
        foreach ($lines as $l) {
            fputcsv($fh, $l);
        }
        rewind($fh);
        $csv = stream_get_contents($fh);
        fclose($fh);
        return "\xEF\xBB\xBF" . str_replace("\n", "\r\n", $csv);
    }

    private static function norm($s)
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));
    }

    /**
     * Read an uploaded CSV into a new batch. Returns the batch id.
     * The header row is the first row with a TIN column and a name column among its first 10 rows.
     */
    public static function stage($path, $originalName, $storedPath = null)
    {
        $rows = CsvReader::rows($path, self::MAX_ROWS + 20);
        $alias = array();
        foreach (self::columns() as $k => $c) {
            foreach (array_merge(array($c[0]), $c[1]) as $t) {
                $alias[self::norm($t)] = $k;
            }
        }
        $headerAt = null;
        $map = array();
        $ignored = array();
        foreach (array_slice($rows, 0, 10, true) as $n => $cells) {
            $m = array();
            $other = array();
            foreach ($cells as $i => $cell) {
                $t = self::norm($cell['v']);
                if ($t !== '' && isset($alias[$t]) && !in_array($alias[$t], $m, true)) {
                    $m[$i] = $alias[$t];
                } elseif (trim($cell['v']) !== '') {
                    $other[] = trim($cell['v']);
                }
            }
            if (in_array('tin', $m, true) && (in_array('registered_name', $m, true) || in_array('last_name', $m, true))) {
                $headerAt = $n;
                $map = $m;
                $ignored = $other;
                break;
            }
        }
        if ($headerAt === null) {
            throw new RuntimeException('No header row was found. The first row of the file must name the columns, with at least TIN and REGISTERED NAME or LAST NAME. Download the CSV template to see them.');
        }
        $data = array();
        foreach ($rows as $n => $cells) {
            if ($n <= $headerAt) {
                continue;
            }
            $d = array();
            $any = false;
            foreach (self::columns() as $k => $c) {
                $d[$k] = '';
            }
            foreach ($map as $i => $k) {
                $v = isset($cells[$i]) ? trim(preg_replace('/\s+/u', ' ', (string)$cells[$i]['v'])) : '';
                $d[$k] = $v;
                $any = $any || $v !== '';
            }
            if ($any) {
                $data[$n] = $d;
            }
        }
        if (!$data) {
            throw new RuntimeException('The file has a header row but no clients under it.');
        }
        if (count($data) > self::MAX_ROWS) {
            throw new RuntimeException('The file has more than ' . self::MAX_ROWS . ' clients. Split it into smaller files.');
        }
        $batch = DB::tx(function () use ($data, $originalName, $storedPath, $path, $ignored) {
            $b = DB::insert('client_import_batches', array('organization_id' => Ctx::orgId(), 'original_filename' => mb_substr($originalName, 0, 200), 'stored_path' => $storedPath,
                'file_sha256' => hash_file('sha256', $path), 'ignored_columns' => $ignored ? mb_substr(implode(', ', $ignored), 0, 500) : null, 'status' => 'review',
                'total_rows' => count($data), 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()));
            foreach ($data as $n => $d) {
                DB::insert('client_import_rows', array('batch_id' => $b, 'row_no' => $n, 'data_json' => json_encode($d), 'status' => 'error'));
            }
            return $b;
        });
        self::recheck($batch);
        Audit::log('client.upload', 'client_import_batches', $batch, 'Uploaded ' . plural(count($data), 'new client') . ' from ' . $originalName . ' for review');
        return $batch;
    }

    /** A batch of the current organization, or null. */
    public static function batch($id)
    {
        return DB::row('SELECT b.*, u.full_name AS uploader, s.full_name AS saver FROM client_import_batches b LEFT JOIN users u ON u.id = b.created_by LEFT JOIN users s ON s.id = b.saved_by
            WHERE b.id = ? AND b.organization_id = ?', array((int)$id, Ctx::orgId()));
    }

    public static function rows($batchId, $show = 'all')
    {
        $sql = 'SELECT * FROM client_import_rows WHERE batch_id = ?';
        $p = array($batchId);
        if (in_array($show, array('error', 'ok', 'saved'), true)) {
            $sql .= ' AND status = ?';
            $p[] = $show;
        } elseif ($show === 'warning') {
            $sql .= " AND status = 'ok' AND warnings_json IS NOT NULL";
        }
        $out = array();
        foreach (DB::all($sql . ' ORDER BY row_no', $p) as $r) {
            $r['data'] = json_decode($r['data_json'], true) ?: array();
            $r['errors'] = json_decode((string)$r['errors_json'], true) ?: array();
            $r['warnings'] = json_decode((string)$r['warnings_json'], true) ?: array();
            $out[] = $r;
        }
        return $out;
    }

    public static function counts($batchId)
    {
        $c = array('total' => 0, 'ok' => 0, 'error' => 0, 'warning' => 0, 'saved' => 0);
        foreach (DB::all('SELECT status, (warnings_json IS NOT NULL) AS w, COUNT(*) AS n FROM client_import_rows WHERE batch_id = ? GROUP BY status, w', array($batchId)) as $r) {
            $c['total'] += (int)$r['n'];
            $c[$r['status']] += (int)$r['n'];
            if ($r['status'] === 'ok' && (int)$r['w']) {
                $c['warning'] += (int)$r['n'];
            }
        }
        return $c;
    }

    /**
     * Check one row. Returns array(clean values for client_entities, errors, warnings); errors and
     * warnings are keyed by column. $seen holds the TINs of the rows before it in the file.
     */
    public static function check($d, $seen = array())
    {
        $e = array();
        $w = array();
        $c = array();
        $g = function ($k) use ($d) {
            return isset($d[$k]) ? trim((string)$d[$k]) : '';
        };
        // TIN: a CSV opened and saved in Excel loses leading zeros, so 8 bare digits are read as that.
        $tinRaw = $g('tin');
        $t = Tin::parse($tinRaw, ctype_digit($tinRaw));
        if (!$t['ok']) {
            $e['tin'] = $t['error'];
        } else {
            $c['tin'] = $t['tin'];
            if ($t['branch'] !== null && $t['branch'] !== '0000' && $t['branch'] !== '00000') {
                $w['tin'] = 'Branch code ' . $t['branch'] . ' after the TIN is not used. The client starts with its head office (0000); add branch ' . $t['branch'] . ' under the client\'s Branches tab.';
            }
            if (in_array($t['tin'], array('111111111', '999999999'), true)) {
                $e['tin'] = 'TIN ' . Tin::display($t['tin']) . ' is a placeholder the BIR validator refuses.';
            } elseif (isset($seen[$t['tin']])) {
                $e['tin'] = 'Same TIN as row ' . $seen[$t['tin']] . ' of this file. Each client needs its own TIN; delete one of the two rows.';
            } else {
                $ex = DB::row('SELECT id, taxpayer_type, registered_name, last_name, first_name, middle_name, status, deleted_at FROM client_entities WHERE organization_id = ? AND tin = ?', array(Ctx::orgId(), $t['tin']));
                if ($ex) {
                    $e['tin'] = $ex['deleted_at'] ? 'This TIN belonged to a client that was deleted, so it cannot be added again.'
                        : 'Already a client: ' . Ctx::clientName($ex) . ($ex['status'] === 'archived' ? ' (archived). Reactivate it instead of adding it again.' : '. Delete this row.');
                }
            }
        }
        // Taxpayer type: blank is worked out from the names given.
        $type = self::pick($g('taxpayer_type'), array('NONINDIVIDUAL' => 'non_individual', 'NI' => 'non_individual', 'N' => 'non_individual', 'CORPORATION' => 'non_individual',
            'CORPORATE' => 'non_individual', 'CORP' => 'non_individual', 'COMPANY' => 'non_individual', 'PARTNERSHIP' => 'non_individual', 'NONIND' => 'non_individual', 'JURIDICAL' => 'non_individual',
            'INDIVIDUAL' => 'individual', 'I' => 'individual', 'IND' => 'individual', 'PERSON' => 'individual', 'SOLEPROPRIETOR' => 'individual', 'SOLEPROPRIETORSHIP' => 'individual',
            'PROFESSIONAL' => 'individual', 'SELFEMPLOYED' => 'individual'));
        if ($type === null) {
            $e['taxpayer_type'] = 'Taxpayer type "' . $g('taxpayer_type') . '" is not known. Use Non-individual or Individual.';
            $type = $g('registered_name') !== '' ? 'non_individual' : 'individual';
        } elseif ($type === '') {
            $type = $g('registered_name') === '' && $g('last_name') !== '' ? 'individual' : 'non_individual';
        }
        $c['taxpayer_type'] = $type;
        foreach (array('registered_name' => 150, 'last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'trade_name' => 150, 'address1' => 150, 'address2' => 150, 'email' => 190, 'phone' => 40) as $k => $max) {
            $v = $g($k);
            if (mb_strlen($v) > $max) {
                $e[$k] = ucfirst(strtolower(self::labels()[$k])) . ' is longer than ' . $max . ' characters.';
            }
            $c[$k] = $v === '' ? null : $v;
        }
        if ($type === 'non_individual') {
            if (!$c['registered_name']) {
                $e['registered_name'] = 'A non-individual needs its registered name.';
            }
            if ($c['last_name'] || $c['first_name'] || $c['middle_name']) {
                $w['last_name'] = 'Last, first and middle names are kept but not used for a non-individual; the registered name is.';
            }
        } else {
            if (!$c['last_name'] || !$c['first_name']) {
                $e['last_name'] = 'An individual needs a last name and a first name.';
            }
            if ($c['registered_name']) {
                $w['registered_name'] = 'The registered name is kept but not used for an individual; the last, first and middle names are.';
            }
        }
        foreach (array('registered_name', 'last_name', 'first_name', 'middle_name') as $k) {
            if (!isset($e[$k]) && $c[$k] && preg_match('/["&\',|]/', $c[$k])) {
                $w[$k] = ucfirst(strtolower(self::labels()[$k])) . ' has a character (" & \' , |) that the BIR alphalist validator refuses in names. It is replaced when files are written; correct it here if you prefer.';
            }
        }
        // RDO
        $rdo = rdo_normalize($g('rdo_code'));
        if ($rdo === null) {
            $e['rdo_code'] = 'RDO code "' . $g('rdo_code') . '" is not valid. Use 3 digits (081) or digits and a letter (21B).';
            $c['rdo_code'] = null;
        } elseif ($rdo === '') {
            $w['rdo_code'] = 'No RDO code. The BIR file headers need one; files for this client are blocked until it is added.';
            $c['rdo_code'] = null;
        } else {
            $c['rdo_code'] = $rdo;
            if (!DB::val('SELECT code FROM rdo_codes WHERE code = ?', array($rdo))) {
                $w['rdo_code'] = 'RDO ' . $rdo . ' is not in the list of RDO codes. Check it.';
            }
        }
        // ZIP
        $zip = $g('zip_code');
        if ($zip !== '' && !preg_match('/^\d{1,6}$/', $zip)) {
            $e['zip_code'] = 'ZIP code "' . $zip . '" must be digits only.';
            $c['zip_code'] = null;
        } else {
            if ($zip !== '' && strlen($zip) < 4) {
                $zip = str_pad($zip, 4, '0', STR_PAD_LEFT);
            }
            if ($zip !== '' && strlen($zip) !== 4) {
                $w['zip_code'] = 'Philippine ZIP codes have 4 digits; this one has ' . strlen($zip) . '.';
            }
            $c['zip_code'] = $zip === '' ? null : $zip;
        }
        // Classification, VAT, accounting period
        $cls = self::pick($g('classification'), array('REGULAR' => 'regular', 'R' => 'regular', 'TOPWITHHOLDINGAGENT' => 'top_withholding_agent', 'TWA' => 'top_withholding_agent',
            'TOP' => 'top_withholding_agent', 'TOPWA' => 'top_withholding_agent', 'LARGETAXPAYER' => 'large_taxpayer', 'LT' => 'large_taxpayer', 'LARGE' => 'large_taxpayer', 'LTS' => 'large_taxpayer',
            'GOVERNMENT' => 'government', 'GOV' => 'government', 'GOVT' => 'government', 'GOCC' => 'government', 'GOVERNMENTOFFICEORGOCC' => 'government', 'GOVERNMENTOFFICE' => 'government'));
        if ($cls === null) {
            $e['classification'] = 'Classification "' . $g('classification') . '" is not known. Use Regular, Top withholding agent, Large taxpayer or Government.';
        }
        $c['classification'] = $cls ?: 'regular';
        $vat = self::pick($g('vat_status'), array('VAT' => 'vat', 'VATREGISTERED' => 'vat', 'V' => 'vat', 'YES' => 'vat', 'Y' => 'vat', 'VATABLE' => 'vat', 'REGISTERED' => 'vat',
            'NONVAT' => 'non_vat', 'NV' => 'non_vat', 'NO' => 'non_vat', 'N' => 'non_vat', 'NONVATREGISTERED' => 'non_vat', 'PERCENTAGETAX' => 'non_vat'));
        if ($vat === null) {
            $e['vat_status'] = 'VAT status "' . $g('vat_status') . '" is not known. Use VAT or Non-VAT.';
        }
        $c['vat_status'] = $vat ?: 'vat';
        $cyc = self::pick($g('reporting_cycle'), array('CALENDAR' => 'calendar', 'CALENDARYEAR' => 'calendar', 'C' => 'calendar', 'CY' => 'calendar',
            'FISCAL' => 'fiscal', 'FISCALYEAR' => 'fiscal', 'F' => 'fiscal', 'FY' => 'fiscal'));
        if ($cyc === null) {
            $e['reporting_cycle'] = 'Accounting period "' . $g('reporting_cycle') . '" is not known. Use Calendar or Fiscal.';
        }
        $c['reporting_cycle'] = $cyc ?: 'calendar';
        $fm = self::month($g('fiscal_month_end'));
        if ($c['reporting_cycle'] === 'fiscal') {
            if ($fm === null) {
                $e['fiscal_month_end'] = $g('fiscal_month_end') === '' ? 'A fiscal year needs the month it ends (1 to 12).' : 'Fiscal year end "' . $g('fiscal_month_end') . '" is not a month. Use 1 to 12 or the month name.';
            } elseif ($fm === 12) {
                $w['fiscal_month_end'] = 'A fiscal year ending in December is a calendar year. Check the accounting period.';
            }
            $c['fiscal_month_end'] = $fm ?: 12;
        } else {
            $c['fiscal_month_end'] = 12;
            if ($g('fiscal_month_end') !== '' && $fm !== 12) {
                $w['fiscal_month_end'] = 'Fiscal year end is ignored for a calendar year.';
            }
        }
        if ($c['email'] && !filter_var($c['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Email "' . $c['email'] . '" is not a valid address.';
        }
        return array($c, $e, $w);
    }

    /** A canonical value from a set of spellings: '' when blank, null when not recognised. */
    private static function pick($v, $map)
    {
        $n = self::norm($v);
        if ($n === '') {
            return '';
        }
        return isset($map[$n]) ? $map[$n] : null;
    }

    private static function month($v)
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        if (ctype_digit($v)) {
            $n = (int)$v;
            return $n >= 1 && $n <= 12 ? $n : null;
        }
        $names = array('JAN', 'FEB', 'MAR', 'APR', 'MAY', 'JUN', 'JUL', 'AUG', 'SEP', 'OCT', 'NOV', 'DEC');
        $p = array_search(substr(self::norm($v), 0, 3), $names, true);
        return $p === false ? null : $p + 1;
    }

    /** Check every unsaved row of a batch again (a change to one row can clear or raise a duplicate in another). */
    public static function recheck($batchId)
    {
        $seen = array();
        foreach (DB::all('SELECT id, row_no, data_json, status FROM client_import_rows WHERE batch_id = ? ORDER BY row_no', array($batchId)) as $r) {
            if ($r['status'] === 'saved') {
                continue;
            }
            list($c, $e, $w) = self::check(json_decode($r['data_json'], true) ?: array(), $seen);
            if (!empty($c['tin']) && !isset($e['tin'])) {
                $seen[$c['tin']] = (int)$r['row_no'];
            }
            DB::update('client_import_rows', array('errors_json' => $e ? json_encode($e) : null, 'warnings_json' => $w ? json_encode($w) : null, 'status' => $e ? 'error' : 'ok'),
                'id = ?', array($r['id']));
        }
        DB::update('client_import_batches', array('total_rows' => (int)DB::val('SELECT COUNT(*) FROM client_import_rows WHERE batch_id = ?', array($batchId)), 'updated_at' => now()),
            'id = ?', array($batchId));
    }

    private static function openBatch($batchId)
    {
        $b = self::batch($batchId);
        if (!$b) {
            throw new RuntimeException('This upload was not found.');
        }
        if ($b['status'] !== 'review') {
            throw new RuntimeException('This upload was already ' . ($b['status'] === 'saved' ? 'saved' : 'discarded') . '.');
        }
        return $b;
    }

    /** Replace one row's values with what was typed in the edit form, then check the batch again. */
    public static function updateRow($batchId, $rowId, $input)
    {
        self::openBatch($batchId);
        $r = DB::row('SELECT * FROM client_import_rows WHERE id = ? AND batch_id = ?', array((int)$rowId, (int)$batchId));
        if (!$r || $r['status'] === 'saved') {
            throw new RuntimeException('That row is no longer in this upload.');
        }
        $old = json_decode($r['data_json'], true) ?: array();
        $d = array();
        foreach (self::columns() as $k => $c) {
            $d[$k] = isset($input[$k]) ? trim(preg_replace('/\s+/u', ' ', (string)$input[$k])) : (isset($old[$k]) ? $old[$k] : '');
        }
        DB::update('client_import_rows', array('data_json' => json_encode($d), 'edited' => 1), 'id = ?', array($r['id']));
        self::recheck($batchId);
        Audit::log('client.upload_edit', 'client_import_batches', $batchId, 'Corrected row ' . $r['row_no'] . ' of the client upload', $old, $d);
        return DB::row('SELECT * FROM client_import_rows WHERE id = ?', array($r['id']));
    }

    /** Delete rows of a batch: the given ids, or with $ids = 'errors' every row that still has an error. */
    public static function deleteRows($batchId, $ids)
    {
        self::openBatch($batchId);
        if ($ids === 'errors') {
            $rows = DB::all("SELECT id, row_no FROM client_import_rows WHERE batch_id = ? AND status = 'error'", array($batchId));
        } else {
            $ids = array_map('intval', (array)$ids);
            $rows = $ids ? DB::all('SELECT id, row_no FROM client_import_rows WHERE batch_id = ? AND status <> ? AND id IN (' . DB::in($ids) . ')', array_merge(array($batchId, 'saved'), $ids)) : array();
        }
        if (!$rows) {
            return 0;
        }
        $nos = array();
        foreach ($rows as $r) {
            DB::q('DELETE FROM client_import_rows WHERE id = ?', array($r['id']));
            $nos[] = (int)$r['row_no'];
        }
        self::recheck($batchId);
        Audit::log('client.upload_delete', 'client_import_batches', $batchId, 'Deleted ' . plural(count($nos), 'row') . ' from the client upload (row ' . implode(', ', $nos) . ')');
        return count($nos);
    }

    public static function discard($batchId)
    {
        $b = self::openBatch($batchId);
        DB::q('DELETE FROM client_import_rows WHERE batch_id = ?', array($batchId));
        DB::update('client_import_batches', array('status' => 'discarded', 'updated_at' => now()), 'id = ?', array($batchId));
        Audit::log('client.upload_discard', 'client_import_batches', $batchId, 'Discarded the client upload ' . $b['original_filename'] . '; no client was added');
    }

    /**
     * Save every row of the batch as a new client with its head office branch. Refused while any row has
     * an error. Returns the number of clients added.
     */
    public static function save($batchId)
    {
        $b = self::openBatch($batchId);
        self::recheck($batchId);
        $cnt = self::counts($batchId);
        if ($cnt['error']) {
            throw new RuntimeException(plural($cnt['error'], 'row') . ' still ' . ($cnt['error'] === 1 ? 'has' : 'have') . ' errors. Edit or delete ' . ($cnt['error'] === 1 ? 'it' : 'them') . ' before saving.');
        }
        if (!$cnt['ok']) {
            throw new RuntimeException('There are no clients left in this upload to save.');
        }
        $m = Ctx::membership();
        $scoped = $m && !(int)$m['all_clients'] && !Ctx::isSuper();
        $added = DB::tx(function () use ($batchId, $m, $scoped) {
            $added = array();
            foreach (DB::all("SELECT * FROM client_import_rows WHERE batch_id = ? AND status = 'ok' ORDER BY row_no", array($batchId)) as $r) {
                list($c) = self::check(json_decode($r['data_json'], true) ?: array());
                $id = DB::insert('client_entities', array_merge($c, array('organization_id' => Ctx::orgId(), 'status' => 'active', 'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now())));
                DB::insert('branches', array('organization_id' => Ctx::orgId(), 'client_id' => $id, 'branch_code' => '0000', 'name' => 'Head office', 'is_head_office' => 1,
                    'rdo_code' => $c['rdo_code'], 'address1' => $c['address1'], 'address2' => $c['address2'], 'zip_code' => $c['zip_code'], 'status' => 'active', 'created_at' => now()));
                if ($scoped) {
                    DB::insert('membership_scopes', array('membership_id' => $m['id'], 'client_id' => $id, 'branch_id' => null));
                }
                DB::update('client_import_rows', array('status' => 'saved', 'client_id' => $id), 'id = ?', array($r['id']));
                $added[$id] = $c;
            }
            DB::update('client_import_batches', array('status' => 'saved', 'saved_rows' => count($added), 'saved_by' => Auth::id(), 'saved_at' => now(), 'updated_at' => now()), 'id = ?', array($batchId));
            return $added;
        });
        foreach ($added as $id => $c) {
            Audit::log('client.create', 'client_entities', $id, 'Added client ' . ($c['registered_name'] ?: $c['last_name'] . ', ' . $c['first_name']) . ' from the CSV upload ' . $b['original_filename'], null, $c, null, $id);
        }
        Audit::log('client.upload_save', 'client_import_batches', $batchId, 'Saved ' . plural(count($added), 'new client') . ' from ' . $b['original_filename']);
        Ctx::reset();
        return count($added);
    }

    /** The name a row shows: the registered name, or LAST, FIRST MIDDLE. */
    public static function rowName($d)
    {
        $reg = isset($d['registered_name']) ? trim($d['registered_name']) : '';
        $ind = trim((isset($d['last_name']) ? $d['last_name'] : '') . ', ' . (isset($d['first_name']) ? $d['first_name'] : '') . ' ' . (isset($d['middle_name']) ? $d['middle_name'] : ''), ', ');
        $type = isset($d['taxpayer_type']) ? self::norm($d['taxpayer_type']) : '';
        if (in_array($type, array('INDIVIDUAL', 'I', 'IND'), true) || ($reg === '' && $ind !== '')) {
            return $ind;
        }
        return $reg !== '' ? $reg : $ind;
    }
}
