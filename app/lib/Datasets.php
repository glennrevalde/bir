<?php
/**
 * Canonical datasets: the domain rules that turn saved records (or historical DAT files)
 * into the rows a layout serializes. Amounts in rows are integer centavos.
 */
class Datasets
{
    public static function sawtForms()
    {
        return array(
            '1700' => array('Annual ITR, individuals earning purely compensation (1700)', 'annual'),
            '1701' => array('Annual ITR, individuals (1701)', 'annual'),
            '1701Q' => array('Quarterly ITR, individuals (1701Q)', 'quarterly'),
            '1702' => array('Annual ITR, non-individuals (1702)', 'annual'),
            '1702Q' => array('Quarterly ITR, non-individuals (1702Q)', 'quarterly'),
            '2550M' => array('Monthly VAT declaration (2550M)', 'monthly'),
            '2550Q' => array('Quarterly VAT return (2550Q)', 'quarterly'),
            '2551Q' => array('Quarterly percentage tax return (2551Q)', 'quarterly'),
            '2553' => array('Percentage tax return, special cases (2553)', 'monthly'),
        );
    }

    public static function schedule($form, $schedule)
    {
        return DB::row('SELECT s.*, f.family, f.frequency, f.name AS form_name, f.output_format FROM report_schedules s JOIN form_types f ON f.code = s.form_code
            WHERE s.form_code = ? AND s.schedule_code = ?', array($form, $schedule));
    }

    /** Filer (withholding agent / taxpayer) context for headers and filenames. */
    public static function agent($client, $branch)
    {
        $ind = $client['taxpayer_type'] === 'individual';
        $display = $ind ? trim($client['last_name'] . ', ' . $client['first_name'] . ' ' . $client['middle_name']) : $client['registered_name'];
        $fme = (int)$client['fiscal_month_end'] ?: 12;
        return array(
            'tin' => $client['tin'],
            'branch' => $branch ? $branch['branch_code'] : '0000',
            'registered_name' => $ind ? '' : (string)$client['registered_name'],
            'last_name' => $ind ? (string)$client['last_name'] : '',
            'first_name' => $ind ? (string)$client['first_name'] : '',
            'middle_name' => $ind ? (string)$client['middle_name'] : '',
            'trade_name' => (string)$client['trade_name'],
            'address1' => $branch && $branch['address1'] ? $branch['address1'] : (string)$client['address1'],
            'address2' => $branch && $branch['address2'] ? $branch['address2'] : (string)$client['address2'],
            'rdo' => $branch && $branch['rdo_code'] ? $branch['rdo_code'] : (string)$client['rdo_code'],
            'fiscal_month_end' => $fme,
            'display_name' => $display,
            'taxpayer_type' => $client['taxpayer_type'],
        );
    }

    private static function partyCols($alias = 'cp')
    {
        return "$alias.id AS cp_id, $alias.tin, $alias.branch_code, $alias.entity_type, $alias.registered_name, $alias.last_name, $alias.first_name, $alias.middle_name, $alias.address1, $alias.address2";
    }

    private static function partyRow($r)
    {
        $ind = $r['entity_type'] === 'individual';
        return array(
            'tin' => (string)$r['tin'], 'branch' => (string)($r['branch_code'] ?: '0000'),
            'registered_name' => $ind ? '' : (string)$r['registered_name'],
            'last_name' => $ind ? (string)$r['last_name'] : '', 'first_name' => $ind ? (string)$r['first_name'] : '', 'middle_name' => $ind ? (string)$r['middle_name'] : '',
            'address1' => (string)$r['address1'], 'address2' => (string)$r['address2'], 'cp_id' => (int)$r['cp_id'],
        );
    }

    /**
     * Build a dataset.
     * $p = array('form','schedule','client','branch' (row|null),'from','to','vars'=>array('sawt_form'=>..., 'srs_atcs'=>[...]))
     */
    public static function build($p)
    {
        $sched = self::schedule($p['form'], $p['schedule']);
        if (!$sched) {
            throw new RuntimeException('Unknown form or schedule.');
        }
        $key = $sched['dataset_key'];
        $client = $p['client'];
        $from = substr($p['from'], 0, 7);
        $to = substr($p['to'], 0, 7);
        $branchId = $p['branch'] ? (int)$p['branch']['id'] : null;
        $parts = explode(':', $key);
        switch ($parts[0]) {
            case 'relief_sales':
                return self::relief($client['id'], $from, $to, 'sales');
            case 'relief_purchases':
                return self::relief($client['id'], $from, $to, 'purchases');
            case 'relief_importations':
                return self::importations($client['id'], $from, $to);
            case 'wh_month':
            case 'wh_year':
                return self::withholding($client['id'], $branchId, 'agent', $parts[1], $parts[2], $from, $to, null);
            case 'sawt':
                return self::withholding($client['id'], $branchId, 'payee', 'EWT', '*', $from, $to, null);
            case 'srs':
                $atcs = isset($p['vars']['srs_atcs']) ? $p['vars']['srs_atcs'] : explode(',', sys_config('srs_atcs', 'WI158,WC158,WI160,WC160'));
                return self::withholding($client['id'], $branchId, 'agent', 'EWT', '*', $from, $to, $atcs, true);
            case 'comp_year':
                return self::compensation($client['id'], $branchId, (int)substr($p['to'], 0, 4), $parts[1]);
        }
        throw new RuntimeException('Dataset ' . $key . ' is not implemented.');
    }

    private static function relief($clientId, $from, $to, $kind)
    {
        $table = $kind === 'sales' ? 'sales_entries' : 'purchase_entries';
        $amounts = $kind === 'sales'
            ? array('exempt' => 'exempt_amount', 'zero_rated' => 'zero_rated_amount', 'taxable' => 'taxable_amount', 'output_vat' => 'output_vat')
            : array('exempt' => 'exempt_amount', 'zero_rated' => 'zero_rated_amount', 'services' => 'services_amount', 'capital_goods' => 'capital_goods_amount',
                'other_goods' => 'other_goods_amount', 'input_tax' => 'input_vat', 'non_creditable' => 'non_creditable_vat');
        $rows = DB::all('SELECT t.*, ' . self::partyCols() . ' FROM ' . $table . ' t JOIN counterparties cp ON cp.id = t.counterparty_id
            WHERE t.client_id = ? AND t.status = ? AND t.period_month >= ? AND t.period_month <= ? ORDER BY cp.tin, cp.registered_name, cp.last_name, t.id',
            array($clientId, 'posted', $from, $to));
        $groups = array();
        foreach ($rows as $r) {
            $gk = $r['tin'] ? 'T' . $r['tin'] : 'N' . strtoupper(party_name($r));
            if (!isset($groups[$gk])) {
                $groups[$gk] = self::partyRow($r);
                foreach ($amounts as $k => $col) {
                    $groups[$gk][$k] = 0;
                }
                $groups[$gk]['source_ids'] = array();
            }
            foreach ($amounts as $k => $col) {
                $groups[$gk][$k] += Money::fromDb($r[$col]);
            }
            $groups[$gk]['source_ids'][] = (int)$r['id'];
        }
        if ($kind === 'purchases') {
            foreach ($groups as &$g) {
                $g['creditable_input'] = $g['input_tax'] - $g['non_creditable'];
            }
            unset($g);
        }
        uasort($groups, function ($a, $b) {
            return strcmp($a['tin'] . party_name($a), $b['tin'] . party_name($b));
        });
        return array('rows' => array_values($groups), 'source_table' => $table, 'source_count' => count($rows));
    }

    private static function importations($clientId, $from, $to)
    {
        $rows = DB::all('SELECT t.*, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name FROM importation_entries t JOIN counterparties cp ON cp.id = t.counterparty_id
            WHERE t.client_id = ? AND t.status = ? AND t.period_month >= ? AND t.period_month <= ? ORDER BY t.importation_date, t.id', array($clientId, 'posted', $from, $to));
        $out = array();
        foreach ($rows as $r) {
            $out[] = array('import_entry_no' => $r['import_entry_no'], 'assessment_date' => $r['assessment_date'], 'supplier_name' => party_name($r),
                'importation_date' => $r['importation_date'], 'country' => (string)$r['country_origin'], 'landed_cost' => Money::fromDb($r['landed_cost']),
                'other_charges' => Money::fromDb($r['other_charges']), 'exempt' => Money::fromDb($r['exempt_amount']), 'taxable' => Money::fromDb($r['taxable_amount']),
                'vat' => Money::fromDb($r['vat_amount']), 'or_no' => (string)$r['or_no'], 'vat_payment_date' => $r['vat_payment_date'], 'source_ids' => array((int)$r['id']));
        }
        return array('rows' => $out, 'source_table' => 'importation_entries', 'source_count' => count($rows));
    }

    /** Withholding rows grouped per counterparty + ATC + rate. */
    public static function withholding($clientId, $branchId, $direction, $kind, $tag, $from, $to, $atcFilter = null, $withMonths = false)
    {
        $sql = 'SELECT t.*, ' . self::partyCols() . ' FROM withholding_entries t JOIN counterparties cp ON cp.id = t.counterparty_id
            WHERE t.client_id = ? AND t.status = ? AND t.direction = ? AND t.tax_kind = ? AND t.period_month >= ? AND t.period_month <= ?';
        $params = array($clientId, 'posted', $direction, $kind, $from, $to);
        if ($tag !== '*') {
            $sql .= ' AND t.schedule_tag = ?';
            $params[] = $tag;
        }
        if ($branchId) {
            $sql .= ' AND t.branch_id = ?';
            $params[] = $branchId;
        }
        if ($atcFilter) {
            $sql .= ' AND t.atc_code IN (' . DB::in($atcFilter) . ')';
            $params = array_merge($params, $atcFilter);
        }
        $rows = DB::all($sql . ' ORDER BY cp.tin, cp.branch_code, t.atc_code, t.id', $params);
        $groups = array();
        foreach ($rows as $r) {
            $pk = $r['tin'] ? $r['tin'] . '-' . $r['branch_code'] : 'N' . strtoupper(party_name($r));
            $gk = $pk . '|' . $r['atc_code'] . '|' . $r['tax_rate'];
            if (!isset($groups[$gk])) {
                $groups[$gk] = self::partyRow($r);
                $groups[$gk]['atc'] = $r['atc_code'];
                $groups[$gk]['rate'] = $r['tax_rate'];
                $groups[$gk]['income'] = 0;
                $groups[$gk]['tax'] = 0;
                $groups[$gk]['source_ids'] = array();
                $groups[$gk]['months_set'] = array();
            }
            $groups[$gk]['income'] += Money::fromDb($r['income_amount']);
            $groups[$gk]['tax'] += Money::fromDb($r['tax_withheld']);
            $groups[$gk]['source_ids'][] = (int)$r['id'];
            $groups[$gk]['months_set'][$r['period_month']] = true;
        }
        foreach ($groups as &$g) {
            $m = array_keys($g['months_set']);
            sort($m);
            $g['months'] = implode(' ', $m);
            unset($g['months_set']);
        }
        unset($g);
        return array('rows' => array_values($groups), 'source_table' => 'withholding_entries', 'source_count' => count($rows));
    }

    public static function compensation($clientId, $branchId, $year, $schedule)
    {
        $sql = 'SELECT c.*, e.tin, e.tin_branch, e.last_name, e.first_name, e.middle_name FROM compensation_records c JOIN employees e ON e.id = c.employee_id
            WHERE c.client_id = ? AND c.status = ? AND c.tax_year = ? AND c.schedule_code = ?';
        $p = array($clientId, 'posted', $year, $schedule);
        if ($branchId) {
            $sql .= ' AND c.branch_id = ?';
            $p[] = $branchId;
        }
        $rows = DB::all($sql . ' ORDER BY e.last_name, e.first_name, e.id', $p);
        $out = array();
        $amountKeys = array();
        foreach (Records::compFields() as $fields) {
            $amountKeys = array_merge($amountKeys, array_keys($fields));
        }
        foreach ($rows as $r) {
            $row = array('tin' => (string)$r['tin'], 'tin_branch' => (string)$r['tin_branch'], 'last_name' => (string)$r['last_name'], 'first_name' => (string)$r['first_name'],
                'middle_name' => (string)$r['middle_name'], 'region_code' => $r['region_code'], 'employment_from' => $r['employment_from'], 'employment_to' => $r['employment_to'],
                'nationality' => $r['nationality'], 'employment_status' => $r['employment_status'], 'separation_reason' => $r['separation_reason'],
                'substituted_filing' => $r['substituted_filing'], 'factor_days' => $r['factor_days'], 'source_ids' => array((int)$r['id']), 'employee_id' => (int)$r['employee_id']);
            foreach ($amountKeys as $k) {
                $row[$k] = Money::fromDb($r[$k]);
            }
            $out[] = $row;
        }
        return array('rows' => $out, 'source_table' => 'compensation_records', 'source_count' => count($rows));
    }

    // ------------------------------------------------------------------ historical DAT sources
    /**
     * Rows for a period taken from stored DAT files (generated or uploaded) instead of the database.
     * $fileIds: list of dat_files ids chosen per month. Rows are re-aggregated per payee + ATC + rate.
     */
    public static function fromDatFiles($fileIds, $atcFilter = null)
    {
        $groups = array();
        $count = 0;
        foreach ($fileIds as $fid) {
            $f = DB::row('SELECT * FROM dat_files WHERE id = ? AND client_id = ?', array($fid, Ctx::clientId()));
            if (!$f) {
                continue;
            }
            $spec = Specs::load($f['spec_id']);
            $parsed = DatReader::parse((string)Reports::storedBytes($f), $spec);
            foreach ($parsed['rows'] as $r) {
                $count++;
                if ($atcFilter && !in_array(isset($r['atc']) ? $r['atc'] : '', $atcFilter, true)) {
                    continue;
                }
                $gk = $r['tin'] . '-' . $r['branch'] . '|' . $r['atc'] . '|' . $r['rate'];
                if (!isset($groups[$gk])) {
                    $groups[$gk] = array('tin' => $r['tin'], 'branch' => $r['branch'], 'registered_name' => isset($r['registered_name']) ? $r['registered_name'] : '',
                        'last_name' => isset($r['last_name']) ? $r['last_name'] : '', 'first_name' => isset($r['first_name']) ? $r['first_name'] : '',
                        'middle_name' => isset($r['middle_name']) ? $r['middle_name'] : '', 'address1' => '', 'address2' => '', 'atc' => $r['atc'], 'rate' => $r['rate'],
                        'income' => 0, 'tax' => 0, 'source_ids' => array(), 'months_set' => array());
                }
                $groups[$gk]['income'] += (int)$r['income'];
                $groups[$gk]['tax'] += (int)$r['tax'];
                $groups[$gk]['months_set'][substr((string)$f['period_to'], 0, 7)] = true;
                $groups[$gk]['source_ids'][] = 'file:' . $f['id'];
            }
        }
        foreach ($groups as &$g) {
            $m = array_keys($g['months_set']);
            sort($m);
            $g['months'] = implode(' ', $m);
            $g['source_ids'] = array_values(array_unique($g['source_ids']));
            unset($g['months_set']);
        }
        unset($g);
        ksort($groups);
        return array('rows' => array_values($groups), 'source_table' => 'dat_files', 'source_count' => $count);
    }

    /** Period helpers per frequency. Returns array(from, to, label). */
    public static function period($frequency, $year, $part, $sawtForm = null)
    {
        $year = (int)$year;
        if ($frequency === 'return' && $sawtForm) {
            $sf = self::sawtForms();
            $frequency = isset($sf[$sawtForm]) ? $sf[$sawtForm][1] : 'quarterly';
        }
        if ($frequency === 'monthly') {
            $m = max(1, min(12, (int)$part));
            $ym = sprintf('%04d-%02d', $year, $m);
            return array($ym . '-01', last_day($ym), month_label($ym));
        }
        if ($frequency === 'quarterly') {
            $q = max(1, min(4, (int)$part));
            $months = quarter_months($year, $q);
            return array($months[0] . '-01', last_day($months[2]), 'Q' . $q . ' ' . $year);
        }
        if ($frequency === 'semestral') {
            $s = (int)$part === 2 ? 2 : 1;
            return $s === 1 ? array($year . '-01-01', $year . '-06-30', 'Jan to Jun ' . $year) : array($year . '-07-01', $year . '-12-31', 'Jul to Dec ' . $year);
        }
        return array($year . '-01-01', $year . '-12-31', 'Year ' . $year);
    }
}
