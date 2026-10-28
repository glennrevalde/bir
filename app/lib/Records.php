<?php
/**
 * Business-record modules. One definition drives the list screen, the entry form,
 * validation, import mapping, period locking, revisions and the audit trail.
 */
class Records
{
    public static function modules()
    {
        $amount = function ($key, $label, $req = false) {
            return array('key' => $key, 'label' => $label, 'type' => 'amount', 'required' => $req);
        };
        return array(
            'sales' => array(
                'title' => 'Sales', 'singular' => 'sales entry', 'table' => 'sales_entries', 'party' => 'customer', 'date' => 'invoice_date',
                'lock_scope' => 'sales', 'help' => 'Invoices and receipts issued, in the columns of the VAT sales workbook. Feeds the Summary List of Sales (RELIEF).',
                'fields' => array(
                    array('key' => 'branch_id', 'label' => 'Branch', 'type' => 'branch', 'required' => true),
                    array('key' => 'counterparty_id', 'label' => 'Customer', 'type' => 'counterparty', 'required' => true, 'role' => 'customer'),
                    array('key' => 'invoice_no', 'label' => 'Invoice no.', 'type' => 'text', 'max' => 40),
                    array('key' => 'invoice_date', 'label' => 'Invoice date', 'type' => 'date', 'required' => true),
                    $amount('exempt_amount', 'Exempt sales'), $amount('zero_rated_amount', 'Zero-rated sales'),
                    array('key' => 'taxable_amount', 'label' => 'Taxable sales, net of VAT', 'type' => 'amount', 'auto' => true),
                    array('key' => 'vat_rate', 'label' => 'VAT rate (%)', 'type' => 'rate'),
                    array('key' => 'output_vat', 'label' => 'Output VAT', 'type' => 'amount', 'auto' => true),
                    array('key' => 'description', 'label' => 'Description', 'type' => 'text', 'max' => 255),
                ),
                'list' => array('invoice_date', 'invoice_no', 'counterparty_id', '_gross', 'exempt_amount', 'zero_rated_amount', 'taxable_amount', 'output_vat'),
                'computed' => array(
                    '_gross' => array('label' => 'Gross taxable', 'sum' => array('taxable_amount', 'output_vat')),
                ),
                'totals' => array('exempt_amount', 'zero_rated_amount', 'taxable_amount', 'output_vat'),
            ),
            'purchases' => array(
                'title' => 'Purchases', 'singular' => 'purchase entry', 'table' => 'purchase_entries', 'party' => 'supplier', 'date' => 'invoice_date',
                'sort' => 'period_month', 'period_field' => true, 'repeatable' => true, 'repeat_key' => 'invoice_no',
                'lock_scope' => 'purchases', 'help' => 'Purchases per vendor, in the same columns as the R_Purchases workbook. Feeds the Summary List of Purchases (RELIEF).',
                'fields' => array(
                    array('key' => 'branch_id', 'label' => 'Branch', 'type' => 'branch', 'required' => true),
                    array('key' => 'counterparty_id', 'label' => 'Vendor', 'type' => 'counterparty', 'required' => true, 'role' => 'supplier'),
                    array('key' => 'period_month', 'label' => 'Purchase month', 'type' => 'month', 'required' => true),
                    array('key' => 'invoice_no', 'label' => 'Invoice or OR no.', 'type' => 'text', 'max' => 40),
                    array('key' => 'invoice_date', 'label' => 'Invoice date', 'type' => 'date'),
                    $amount('exempt_amount', 'Exempt'), $amount('zero_rated_amount', 'Zero-rated'),
                    $amount('services_amount', 'Services'), $amount('capital_goods_amount', 'Capital goods'),
                    $amount('other_goods_amount', 'Other than capital goods'),
                    array('key' => 'vat_rate', 'label' => 'VAT rate (%)', 'type' => 'rate'),
                    array('key' => 'input_vat', 'label' => 'Input VAT', 'type' => 'amount', 'auto' => true),
                    $amount('non_creditable_vat', 'Non-creditable input VAT'),
                    array('key' => 'description', 'label' => 'Description', 'type' => 'text', 'max' => 255),
                ),
                'list' => array('period_month', 'counterparty_id', 'exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount',
                    '_taxable', 'input_vat', '_total'),
                'computed' => array(
                    '_taxable' => array('label' => 'Taxable net of VAT', 'sum' => array('services_amount', 'capital_goods_amount', 'other_goods_amount')),
                    '_total' => array('label' => 'Total purchases', 'sum' => array('exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount')),
                ),
                'totals' => array('exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount', 'input_vat', 'non_creditable_vat'),
            ),
            'importations' => array(
                'title' => 'Importations', 'singular' => 'importation', 'table' => 'importation_entries', 'party' => 'supplier', 'date' => 'importation_date',
                'lock_scope' => 'purchases', 'help' => 'Import entries. Feeds the Summary List of Importations (RELIEF).',
                'fields' => array(
                    array('key' => 'branch_id', 'label' => 'Branch', 'type' => 'branch', 'required' => true),
                    array('key' => 'counterparty_id', 'label' => 'Foreign seller', 'type' => 'counterparty', 'required' => true, 'role' => 'supplier'),
                    array('key' => 'import_entry_no', 'label' => 'Import entry no.', 'type' => 'text', 'max' => 40, 'required' => true),
                    array('key' => 'importation_date', 'label' => 'Date of importation', 'type' => 'date', 'required' => true),
                    array('key' => 'assessment_date', 'label' => 'Assessment or release date', 'type' => 'date'),
                    array('key' => 'country_origin', 'label' => 'Country of origin', 'type' => 'text', 'max' => 60),
                    $amount('landed_cost', 'Total landed cost'), $amount('other_charges', 'Other charges'), $amount('exempt_amount', 'Exempt importations'),
                    $amount('taxable_amount', 'Taxable importations'), $amount('vat_amount', 'VAT paid'),
                    array('key' => 'or_no', 'label' => 'OR no. of VAT payment', 'type' => 'text', 'max' => 40),
                    array('key' => 'vat_payment_date', 'label' => 'Date of VAT payment', 'type' => 'date'),
                ),
                'list' => array('importation_date', 'import_entry_no', 'counterparty_id', 'landed_cost', 'vat_amount'),
                'totals' => array('landed_cost', 'other_charges', 'exempt_amount', 'taxable_amount', 'vat_amount'),
            ),
            'withholding' => array(
                'title' => 'Taxes we withheld', 'singular' => 'withholding entry', 'table' => 'withholding_entries', 'party' => 'payee', 'date' => 'payment_date',
                'lock_scope' => 'withholding', 'fixed' => array('direction' => 'agent'),
                'help' => 'Income payments where this client is the withholding agent. Feeds QAP, MAP, 1604-E/F and certificates 2307 and 2306.',
                'fields' => array(
                    array('key' => 'branch_id', 'label' => 'Branch', 'type' => 'branch', 'required' => true),
                    array('key' => 'counterparty_id', 'label' => 'Payee', 'type' => 'counterparty', 'required' => true, 'role' => 'payee'),
                    array('key' => 'tax_kind', 'label' => 'Kind of withholding', 'type' => 'select', 'required' => true,
                        'options' => array('EWT' => 'Expanded (creditable)', 'FWT' => 'Final', 'GVAT' => 'Government: VAT withheld', 'GPT' => 'Government: percentage tax withheld')),
                    array('key' => 'atc_code', 'label' => 'ATC', 'type' => 'atc', 'required' => true),
                    array('key' => 'payment_date', 'label' => 'Payment date', 'type' => 'date', 'required' => true),
                    array('key' => 'nature_of_payment', 'label' => 'Nature of income payment', 'type' => 'text', 'max' => 255),
                    $amount('income_amount', 'Income payment', true),
                    array('key' => 'tax_rate', 'label' => 'Tax rate (%)', 'type' => 'rate'),
                    $amount('tax_withheld', 'Tax withheld'),
                    array('key' => 'rate_exception_note', 'label' => 'Reason when rate or tax differs from the ATC', 'type' => 'text', 'max' => 255),
                    array('key' => 'schedule_tag', 'label' => 'Schedule placement', 'type' => 'select', 'required' => true,
                        'options' => array('A' => 'A: QAP Schedule 1 / annual Schedule 4', 'B' => 'B: second schedule (1601-EQ S2, 1604-E S3, 1604-F S5)', 'C' => 'C: final third schedule (1601-FQ S3, 1604-F S6)')),
                    array('key' => 'reference_no', 'label' => 'Voucher or reference no.', 'type' => 'text', 'max' => 40),
                ),
                'list' => array('payment_date', 'counterparty_id', 'tax_kind', 'atc_code', 'income_amount', 'tax_withheld'),
                'totals' => array('income_amount', 'tax_withheld'),
            ),
            'withheld_on_us' => array(
                'title' => 'Taxes withheld on us', 'singular' => 'tax withheld on us', 'table' => 'withholding_entries', 'party' => 'payor', 'date' => 'payment_date',
                'sort' => 'period_month', 'period_field' => true, 'repeatable' => true, 'repeat_key' => 'reference_no',
                'lock_scope' => 'withholding', 'fixed' => array('direction' => 'payee', 'tax_kind' => 'EWT', 'schedule_tag' => 'A'),
                'help' => 'Creditable taxes customers withheld from this client (Form 2307 received), in the columns of the SAWT. Feeds the SAWT attachment.',
                'fields' => array(
                    array('key' => 'branch_id', 'label' => 'Branch', 'type' => 'branch', 'required' => true),
                    array('key' => 'counterparty_id', 'label' => 'Payor', 'type' => 'counterparty', 'required' => true, 'role' => 'payor'),
                    array('key' => 'period_month', 'label' => 'Month of income payment', 'type' => 'month', 'required' => true),
                    array('key' => 'payment_date', 'label' => 'Date of payment', 'type' => 'date'),
                    array('key' => 'atc_code', 'label' => 'ATC', 'type' => 'atc', 'required' => true),
                    array('key' => 'nature_of_payment', 'label' => 'Nature of payment', 'type' => 'text', 'max' => 255),
                    $amount('income_amount', 'Amount of income payment', true),
                    array('key' => 'tax_rate', 'label' => 'Tax rate (%)', 'type' => 'rate'),
                    array('key' => 'tax_withheld', 'label' => 'Amount of tax withheld', 'type' => 'amount', 'auto' => true),
                    array('key' => 'rate_exception_note', 'label' => 'Note on the rate or tax', 'type' => 'text', 'max' => 255),
                    array('key' => 'reference_no', 'label' => 'Certificate (2307) reference', 'type' => 'text', 'max' => 40),
                ),
                'list' => array('period_month', 'counterparty_id', 'atc_code', 'nature_of_payment', 'income_amount', 'tax_rate', 'tax_withheld'),
                'totals' => array('income_amount', 'tax_withheld'),
            ),
        );
    }

    public static function module($key)
    {
        $m = self::modules();
        if (!isset($m[$key])) {
            throw new RuntimeException('Unknown record module.');
        }
        $m[$key]['key'] = $key;
        return $m[$key];
    }

    public static function compFields()
    {
        $L = array(
            'Previous employer' => array('prev_gross' => 'Gross compensation', 'prev_nt_smw' => 'Non-taxable SMW / basic', 'prev_nt_13th' => 'Non-taxable 13th month and other benefits',
                'prev_nt_deminimis' => 'De minimis benefits', 'prev_nt_contrib' => 'SSS, GSIS, PHIC, HDMF and union dues', 'prev_nt_other' => 'Other non-taxable compensation',
                'prev_nt_total' => 'Total non-taxable', 'prev_tx_basic' => 'Taxable basic salary', 'prev_tx_13th' => 'Taxable 13th month and other benefits',
                'prev_tx_other' => 'Other taxable compensation', 'prev_tx_total' => 'Total taxable'),
            'Present employer' => array('pres_gross' => 'Gross compensation', 'pres_nt_smw' => 'Non-taxable SMW / basic', 'pres_nt_13th' => 'Non-taxable 13th month and other benefits',
                'pres_nt_deminimis' => 'De minimis benefits', 'pres_nt_contrib' => 'SSS, GSIS, PHIC, HDMF and union dues', 'pres_nt_other' => 'Other non-taxable compensation',
                'pres_nt_total' => 'Total non-taxable', 'pres_tx_basic' => 'Taxable basic salary', 'pres_tx_13th' => 'Taxable 13th month and other benefits',
                'pres_tx_other' => 'Other taxable compensation', 'pres_tx_total' => 'Total taxable'),
            'Tax computation' => array('taxable_comp_total' => 'Taxable compensation, present and previous', 'net_taxable_comp' => 'Net taxable compensation',
                'tax_due' => 'Tax due, January to December', 'prev_tax_withheld' => 'Tax withheld by previous employer', 'pres_withheld_jan_nov' => 'Tax withheld, January to November',
                'pres_withheld_dec' => 'Additional tax withheld in December', 'over_withheld_refunded' => 'Over-withheld tax refunded', 'tax_withheld_adjusted' => 'Tax withheld as adjusted',
                'pera_credit' => '5% PERA tax credit'),
            'Minimum wage earner details' => array('smw_per_day' => 'SMW per day', 'smw_per_month' => 'SMW per month', 'smw_per_year' => 'SMW per year',
                'mwe_holiday_pay' => 'Holiday pay', 'mwe_overtime_pay' => 'Overtime pay', 'mwe_night_diff' => 'Night shift differential', 'mwe_hazard_pay' => 'Hazard pay'),
        );
        return $L;
    }

    // ---------------------------------------------------------------- period locks
    public static function lockedPeriod($clientId, $periodMonth, $scope)
    {
        return DB::row('SELECT * FROM period_locks WHERE client_id = ? AND period_month = ? AND unlocked_at IS NULL AND (scope = ? OR scope = ?) LIMIT 1',
            array($clientId, $periodMonth, 'all', $scope));
    }

    // ---------------------------------------------------------------- tax codes
    public static function taxCode($atc, $kind, $onDate)
    {
        return DB::row('SELECT * FROM tax_codes WHERE atc_code = ? AND tax_kind = ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)
            AND status <> ? ORDER BY effective_from DESC LIMIT 1', array($atc, $kind, $onDate, $onDate, 'retired'));
    }

    public static function atcOptions($kind = null, $onDate = null)
    {
        $onDate = $onDate ?: today();
        $sql = 'SELECT * FROM tax_codes WHERE status <> ? AND effective_from <= ? AND (effective_to IS NULL OR effective_to >= ?)';
        $p = array('retired', $onDate, $onDate);
        if ($kind) {
            $sql .= ' AND tax_kind = ?';
            $p[] = $kind;
        }
        return DB::all($sql . ' ORDER BY atc_code, effective_from DESC', $p);
    }

    // ---------------------------------------------------------------- validation
    /**
     * Validate a posted record. Returns array($data, $errors, $warnings).
     * $input uses field keys; amounts as strings; dates as YYYY-MM-DD or MM/DD/YYYY.
     */
    public static function validate($mod, $input, $clientId, $options = array())
    {
        $data = array();
        $errors = array();
        $warnings = array();
        foreach ($mod['fields'] as $f) {
            $k = $f['key'];
            if (isset($mod['fixed'][$k])) {
                continue;
            }
            $v = isset($input[$k]) ? (is_string($input[$k]) ? trim($input[$k]) : $input[$k]) : '';
            $req = !empty($f['required']);
            switch ($f['type']) {
                case 'amount':
                    $p = Money::parse($v);
                    if (!$p['ok']) {
                        $errors[$k] = $f['label'] . ': ' . $p['error'];
                    } else {
                        if ($req && $p['blank']) {
                            $errors[$k] = $f['label'] . ' is required';
                        }
                        if ($p['rounded']) {
                            $warnings[$k] = $f['label'] . ' had more than 2 decimals and was rounded to ' . Money::toPlain($p['cents']);
                        }
                        // Fields computed when left blank (input VAT) keep "blank" so the rule can fill them.
                        $data[$k] = $p['blank'] && !empty($f['auto']) ? null : Money::toPlain($p['cents']);
                    }
                    break;
                case 'rate':
                    if ($v === '' || $v === null) {
                        $data[$k] = null;
                    } else {
                        $u = Money::rateUnits($v);
                        if ($u === null || $u > 1000000) {
                            $errors[$k] = $f['label'] . ' must be a percentage such as 1, 2 or 10';
                        } else {
                            $data[$k] = Money::rateString($u);
                        }
                    }
                    break;
                case 'month':
                    $mth = self::parseMonth($v);
                    if ($v === '' || $v === null) {
                        $data[$k] = null;
                    } elseif (!$mth) {
                        $errors[$k] = $f['label'] . ' is not a valid month (use MM/YYYY)';
                    } else {
                        $data[$k] = $mth;
                        if ($mth > date('Y-m', strtotime('first day of next month'))) {
                            $warnings[$k] = $f['label'] . ' is in the future';
                        }
                    }
                    break;
                case 'date':
                    $d = self::parseDate($v, isset($options['date_format']) ? $options['date_format'] : 'auto');
                    if ($v === '' || $v === null) {
                        if ($req) {
                            $errors[$k] = $f['label'] . ' is required';
                        }
                        $data[$k] = null;
                    } elseif (!$d) {
                        $errors[$k] = $f['label'] . ' is not a valid date (use MM/DD/YYYY)';
                    } else {
                        $data[$k] = $d;
                        if ($d > date('Y-m-d', strtotime('+31 days'))) {
                            $warnings[$k] = $f['label'] . ' is in the future';
                        }
                    }
                    break;
                case 'branch':
                    if (!Ctx::isSuper() && !Ctx::branchAllowed($v) && empty($options['skip_scope'])) {
                        $errors[$k] = 'Choose a branch you have access to';
                    } elseif (!DB::val('SELECT id FROM branches WHERE id = ? AND client_id = ?', array($v, $clientId))) {
                        $errors[$k] = 'Choose a branch of this client';
                    }
                    $data[$k] = (int)$v;
                    break;
                case 'counterparty':
                    if (!empty($options['skip_party'])) {
                        $data[$k] = 0;
                        break;
                    }
                    $cp = DB::row('SELECT * FROM counterparties WHERE id = ? AND client_id = ? AND deleted_at IS NULL', array($v, $clientId));
                    if (!$cp) {
                        $errors[$k] = 'Choose a ' . strtolower($f['label']) . ' from the directory';
                    }
                    $data[$k] = (int)$v;
                    break;
                case 'select':
                    if ($v === '' && $req) {
                        $errors[$k] = $f['label'] . ' is required';
                    } elseif ($v !== '' && !isset($f['options'][$v])) {
                        $errors[$k] = $f['label'] . ' has an unknown value';
                    }
                    $data[$k] = $v;
                    break;
                case 'atc':
                    $v = strtoupper(preg_replace('/\s+/', '', (string)$v));
                    if ($v === '' && $req) {
                        $errors[$k] = 'ATC is required';
                    } elseif ($v !== '' && !preg_match('/^[A-Z0-9]{3,10}$/', $v)) {
                        $errors[$k] = 'ATC has an invalid format';
                    }
                    $data[$k] = $v;
                    break;
                default:
                    $v = (string)$v;
                    if ($req && $v === '') {
                        $errors[$k] = $f['label'] . ' is required';
                    }
                    if (isset($f['max']) && mb_strlen($v) > $f['max']) {
                        $errors[$k] = $f['label'] . ' is longer than ' . $f['max'] . ' characters';
                    }
                    $data[$k] = $v === '' ? null : $v;
            }
        }
        if (!empty($mod['fixed'])) {
            foreach ($mod['fixed'] as $k => $v) {
                $data[$k] = $v;
            }
        }
        $dateKey = $mod['date'];
        if (!empty($mod['period_field'])) {
            // The month is its own field; an invoice date is optional but must fall inside it.
            $fromDate = !empty($data[$dateKey]) ? substr($data[$dateKey], 0, 7) : null;
            if (empty($data['period_month']) && $fromDate) {
                $data['period_month'] = $fromDate;
                unset($errors['period_month']);
            }
            $labelOf = array();
            foreach ($mod['fields'] as $f) {
                $labelOf[$f['key']] = $f['label'];
            }
            if (empty($data['period_month']) && !isset($errors['period_month'])) {
                $errors['period_month'] = 'Choose the ' . strtolower($labelOf['period_month']) . ' (or give the ' . strtolower($labelOf[$dateKey]) . ')';
            } elseif ($fromDate && !empty($data['period_month']) && $fromDate !== $data['period_month']) {
                $errors[$dateKey] = $labelOf[$dateKey] . ' ' . fmt_date($data[$dateKey], 'm/d/Y') . ' is outside the ' . strtolower($labelOf['period_month']) . ', ' . month_label($data['period_month']);
            }
        } elseif (!empty($data[$dateKey])) {
            $data['period_month'] = substr($data[$dateKey], 0, 7);
        }
        if ($mod['table'] === 'purchase_entries' && !isset($errors['vat_rate'])) {
            self::checkPurchase($data, $errors, $warnings);
        }
        if ($mod['table'] === 'sales_entries' && !isset($errors['vat_rate']) && !isset($errors['taxable_amount']) && !isset($errors['output_vat'])) {
            self::checkSales($data, $errors, $warnings, isset($input['gross_taxable']) ? $input['gross_taxable'] : '');
        }
        if ($mod['table'] === 'withholding_entries' && empty($errors)) {
            self::checkWithholding($data, $errors, $warnings, $clientId, isset($options['party_entity_type']) ? $options['party_entity_type'] : null);
        }
        return array($data, $errors, $warnings);
    }

    /**
     * ATC validity, payee-type match, rate and tax arithmetic. Mutates $data (fills rate/tax).
     * The ATC is looked up on the payment date, or on the last day of the month when a line has none
     * (SAWT lines are per month). For taxes withheld on us the payor certified the amounts, so a rate or
     * tax that differs from the registry is kept and flagged instead of refused.
     */
    public static function checkWithholding(&$data, &$errors, &$warnings, $clientId, $partyType = null)
    {
        $onDate = !empty($data['payment_date']) ? $data['payment_date'] : (!empty($data['period_month']) ? last_day($data['period_month']) : today());
        $tc = self::taxCode($data['atc_code'], $data['tax_kind'], $onDate);
        if (!$tc) {
            $errors['atc_code'] = 'ATC ' . $data['atc_code'] . ' is not registered for ' . $data['tax_kind'] . ' on ' . fmt_date($onDate, 'm/d/Y') . '. Add it under Admin, Tax codes.';
            return;
        }
        if ($tc['status'] !== 'approved') {
            $warnings['atc_code'] = 'ATC ' . $data['atc_code'] . ' is still pending review in the tax code registry. Files using it stay unvalidated until it is approved.';
        }
        $received = isset($data['direction']) && $data['direction'] === 'payee';
        if ($received) {
            // Taxes withheld on the client: the client is the payee, so the ATC must fit the client.
            $cp = array('entity_type' => DB::val('SELECT taxpayer_type FROM client_entities WHERE id = ?', array($clientId)));
        } else {
            $cp = $partyType ? array('entity_type' => $partyType) : DB::row('SELECT entity_type FROM counterparties WHERE id = ?', array($data['counterparty_id']));
        }
        if ($cp && $tc['payee_type'] !== 'any') {
            $want = $cp['entity_type'] === 'individual' ? 'individual' : 'corporate';
            if ($tc['payee_type'] !== $want) {
                $errors['atc_code'] = $received
                    ? 'ATC ' . $data['atc_code'] . ' is for ' . ($tc['payee_type'] === 'individual' ? 'individual' : 'non-individual') . ' payees, but this client is ' . ($want === 'individual' ? 'an individual' : 'a non-individual') . '.'
                    : 'ATC ' . $data['atc_code'] . ' applies to ' . ($tc['payee_type'] === 'individual' ? 'individual' : 'non-individual') . ' payees, but this payee is ' . ($want === 'individual' ? 'an individual' : 'a non-individual') . '.';
                return;
            }
        }
        if (empty($data['nature_of_payment']) && $received) {
            $data['nature_of_payment'] = $tc['description'];
        }
        $atcUnits = Money::rateUnits($tc['rate']);
        $units = ($data['tax_rate'] === null || $data['tax_rate'] === '') ? $atcUnits : Money::rateUnits($data['tax_rate']);
        if ($received && $units > 0 && $units < 10000 && $units * 100 === $atcUnits) {
            // A rate written as a fraction (0.02) where the ATC says 2%.
            $warnings['tax_rate'] = 'Tax rate ' . rtrim(rtrim(Money::rateString($units), '0'), '.') . ' was read as ' . Money::rateFormat($atcUnits, 'pct2') . '%.';
            $units = $atcUnits;
        }
        $data['tax_rate'] = Money::rateString($units);
        $income = Money::fromDb($data['income_amount']);
        $expected = Money::applyRate($income, $units);
        $given = Money::parse(isset($data['tax_withheld']) ? $data['tax_withheld'] : '');
        if (!isset($data['tax_withheld']) || $given['blank'] || ($given['cents'] === 0 && $expected !== 0 && empty($data['rate_exception_note']) && !$received)) {
            $data['tax_withheld'] = Money::toPlain($expected);
        } else {
            $diff = abs($given['cents'] - $expected);
            $msg = 'Tax withheld ' . Money::fmt($given['cents']) . ' differs from ' . Money::fmt($expected) . ' (' . Money::rateFormat($units, 'pct2') . '% of ' . Money::fmt($income) . ')';
            if ($diff > 1 && $received) {
                $warnings['tax_withheld'] = $msg . '. It is kept as certified by the payor; check it against the 2307.';
            } elseif ($diff > 1 && empty($data['rate_exception_note'])) {
                $errors['tax_withheld'] = 'Tax withheld should be ' . Money::fmt($expected) . ' (' . Money::rateFormat($units, 'pct2') . '% of ' . Money::fmt($income) . '). Enter a reason to keep a different amount.';
            } elseif ($diff === 1) {
                $warnings['tax_withheld'] = 'Tax withheld differs from the computed amount by 0.01.';
            }
        }
        if ($units !== $atcUnits) {
            if ($received) {
                $warnings['tax_rate'] = 'The rate ' . Money::rateFormat($units, 'pct2') . '% differs from the ' . Money::rateFormat($atcUnits, 'pct2') . '% registered for ' . $data['atc_code'] . '. It is kept as certified by the payor.';
            } elseif (empty($data['rate_exception_note'])) {
                $errors['tax_rate'] = 'The rate differs from the ' . Money::rateFormat($atcUnits, 'pct2') . '% registered for ' . $data['atc_code'] . '. Enter a reason to keep it.';
            }
        }
    }

    /** Taxable purchases net of VAT, in centavos: services + capital goods + other goods. */
    public static function purchaseTaxable($d)
    {
        return Money::fromDb(isset($d['services_amount']) ? $d['services_amount'] : 0) + Money::fromDb(isset($d['capital_goods_amount']) ? $d['capital_goods_amount'] : 0)
            + Money::fromDb(isset($d['other_goods_amount']) ? $d['other_goods_amount'] : 0);
    }

    /** Total purchases, in centavos: exempt + zero-rated + taxable (VAT not included), as in the R_Purchases workbook. */
    public static function purchaseTotal($d)
    {
        return Money::fromDb(isset($d['exempt_amount']) ? $d['exempt_amount'] : 0) + Money::fromDb(isset($d['zero_rated_amount']) ? $d['zero_rated_amount'] : 0) + self::purchaseTaxable($d);
    }

    /**
     * Purchase rules from the R_Purchases workbook: taxable = ROUND(services + capital + other, 2),
     * input VAT = ROUND(taxable x rate / 100, 2), rate defaults to 12. Mutates $data (fills rate and VAT).
     */
    public static function checkPurchase(&$data, &$errors, &$warnings)
    {
        $units = ($data['vat_rate'] === null || $data['vat_rate'] === '') ? 120000 : Money::rateUnits($data['vat_rate']);
        if ($units === null || $units > 1000000) {
            $errors['vat_rate'] = 'VAT rate must be a percentage such as 12';
            return;
        }
        if ($units > 0 && $units < 10000) {
            $errors['vat_rate'] = 'VAT rate ' . Money::rateFormat($units, 'pct2') . '% looks like a fraction. Enter 12 for 12%.';
            return;
        }
        $data['vat_rate'] = Money::rateString($units);
        if ($units !== 120000 && $units !== 0) {
            $warnings['vat_rate'] = 'VAT rate is ' . Money::rateFormat($units, 'pct2') . '%, not the standard 12%.';
        }
        $amountKeys = array('exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount');
        $any = false;
        foreach ($amountKeys as $k) {
            $c = Money::fromDb(isset($data[$k]) ? $data[$k] : 0);
            if ($c !== 0) {
                $any = true;
            }
            if ($c < 0) {
                $warnings[$k] = 'Negative amount. Use it only for a debit or credit memo.';
            }
        }
        if (!$any) {
            $errors['other_goods_amount'] = 'Enter at least one purchase amount';
            return;
        }
        $taxable = self::purchaseTaxable($data);
        $expected = Money::applyRate($taxable, $units);
        $given = Money::parse(isset($data['input_vat']) ? $data['input_vat'] : '');
        if (!isset($data['input_vat']) || $data['input_vat'] === null || $given['blank']) {
            $data['input_vat'] = Money::toPlain($expected);
        } elseif ($taxable === 0 && $given['cents'] !== 0) {
            $errors['input_vat'] = 'Input VAT needs a taxable amount (services, capital goods or other goods)';
        } elseif (abs($given['cents'] - $expected) > 1) {
            $warnings['input_vat'] = 'Input VAT ' . Money::fmt($given['cents']) . ' differs from ' . Money::rateFormat($units, 'pct2') . '% of ' . Money::fmt($taxable) . ' (' . Money::fmt($expected) . ').';
        }
        $nc = Money::fromDb(isset($data['non_creditable_vat']) ? $data['non_creditable_vat'] : 0);
        if ($nc > 0 && $nc > Money::fromDb($data['input_vat'])) {
            $errors['non_creditable_vat'] = 'Non-creditable input VAT cannot be more than the input VAT';
        }
    }

    /** Gross taxable sales, VAT included, in centavos: taxable sales net of VAT + output VAT. */
    public static function salesGross($d)
    {
        return Money::fromDb(isset($d['taxable_amount']) ? $d['taxable_amount'] : 0) + Money::fromDb(isset($d['output_vat']) ? $d['output_vat'] : 0);
    }

    /**
     * Sales rules from the VAT sales workbook. GROSS TAXABLE includes VAT; TAXABLE SALES is net of VAT;
     * OUTPUT VAT = TAXABLE SALES x VAT RATE / 100; VAT RATE defaults to 12. Given the gross, the taxable
     * sales are ROUND(gross / (1 + rate / 100), 2) and the VAT is the rest, so the two always add up to the
     * gross. Given the taxable sales instead, the VAT is ROUND(taxable x rate / 100, 2).
     * Mutates $data (fills the rate, taxable sales and VAT); $gross is the GROSS TAXABLE input, not stored.
     */
    public static function checkSales(&$data, &$errors, &$warnings, $gross)
    {
        $units = ($data['vat_rate'] === null || $data['vat_rate'] === '') ? 120000 : Money::rateUnits($data['vat_rate']);
        if ($units === null || $units > 1000000) {
            $errors['vat_rate'] = 'VAT rate must be a percentage such as 12';
            return;
        }
        if ($units > 0 && $units < 10000) {
            $errors['vat_rate'] = 'VAT rate ' . Money::rateFormat($units, 'pct2') . '% looks like a fraction. Enter 12 for 12%.';
            return;
        }
        $data['vat_rate'] = Money::rateString($units);
        if ($units !== 120000 && $units !== 0) {
            $warnings['vat_rate'] = 'VAT rate is ' . Money::rateFormat($units, 'pct2') . '%, not the standard 12%.';
        }
        $g = Money::parse(is_string($gross) || is_numeric($gross) ? (string)$gross : '');
        if (!$g['ok']) {
            $errors['gross_taxable'] = 'Gross taxable: ' . $g['error'];
            return;
        }
        $n = Money::parse($data['taxable_amount'] === null ? '' : $data['taxable_amount']);
        $v = Money::parse($data['output_vat'] === null ? '' : $data['output_vat']);
        if (!$g['blank']) {
            // Gross includes the VAT: net = gross / (1 + rate), half away from zero; VAT = gross - net.
            $net = self::divRound($g['cents'] * 1000000, 1000000 + $units);
            if ($n['blank'] && $v['blank']) {
                $n['cents'] = $net;
                $v['cents'] = $g['cents'] - $net;
            } elseif ($n['blank']) {
                $n['cents'] = $g['cents'] - $v['cents'];
            } elseif ($v['blank']) {
                $v['cents'] = $g['cents'] - $n['cents'];
            }
            if ($n['cents'] + $v['cents'] !== $g['cents']) {
                // The common mistake: the same amount typed under GROSS TAXABLE and under ZERO RATED or EXEMPT SALES.
                foreach (array('zero_rated_amount' => 'zero-rated sales', 'exempt_amount' => 'exempt sales') as $k => $what) {
                    if (Money::fromDb(isset($data[$k]) ? $data[$k] : 0) === $g['cents'] && $n['cents'] === 0) {
                        $errors['gross_taxable'] = 'Gross taxable ' . Money::fmt($g['cents']) . ' is the same amount as the ' . $what . '. Gross taxable is only for sales with VAT; '
                            . 'for ' . $what . ' leave gross taxable blank or 0, and leave taxable sales and output VAT at 0.';
                        return;
                    }
                }
                $errors['gross_taxable'] = 'Gross taxable is ' . Money::fmt($g['cents']) . ' but taxable sales ' . Money::fmt($n['cents']) . ' + output VAT ' . Money::fmt($v['cents'])
                    . ' is ' . Money::fmt($n['cents'] + $v['cents']) . '. Fix one of them, or leave taxable sales and VAT blank to work them out.';
                return;
            }
            if (abs($n['cents'] - $net) > 1) {
                $warnings['taxable_amount'] = 'Taxable sales ' . Money::fmt($n['cents']) . ' differ from gross taxable / ' . (1 + $units / 1000000) . ' (' . Money::fmt($net) . ').';
            }
            $n['blank'] = $v['blank'] = false;
        }
        if ($n['blank']) {
            $n['cents'] = 0;
        }
        $expected = Money::applyRate($n['cents'], $units);
        if ($v['blank']) {
            $v['cents'] = $expected;
        } elseif ($n['cents'] === 0 && $v['cents'] !== 0) {
            $errors['output_vat'] = 'Output VAT needs taxable sales (or a gross taxable amount)';
            return;
        } elseif (abs($v['cents'] - $expected) > 1) {
            $warnings['output_vat'] = 'Output VAT ' . Money::fmt($v['cents']) . ' differs from ' . Money::rateFormat($units, 'pct2') . '% of ' . Money::fmt($n['cents']) . ' (' . Money::fmt($expected) . ').';
        }
        $data['taxable_amount'] = Money::toPlain($n['cents']);
        $data['output_vat'] = Money::toPlain($v['cents']);
        $any = false;
        foreach (array('exempt_amount', 'zero_rated_amount', 'taxable_amount') as $k) {
            $c = Money::fromDb(isset($data[$k]) ? $data[$k] : 0);
            if ($c !== 0) {
                $any = true;
            }
            if ($c < 0) {
                $warnings[$k] = 'Negative amount. Use it only for a credit memo or a returned sale.';
            }
        }
        if (!$any) {
            $errors['gross_taxable'] = 'Enter at least one sales amount: gross taxable, exempt, zero-rated or taxable sales';
            return;
        }
        if ($units === 0 && $n['cents'] !== 0) {
            $warnings['vat_rate'] = 'VAT rate is 0% on taxable sales. Sales taxed at 0% belong under zero-rated sales.';
        }
    }

    /** $a / $b rounded half away from zero, for integers (centavo arithmetic without floats). */
    private static function divRound($a, $b)
    {
        $neg = ($a < 0) !== ($b < 0);
        $a = abs($a);
        $b = abs($b);
        if ($a > PHP_INT_MAX / 4) {
            $q = (int)floor($a / $b + 0.5);
            return $neg ? -$q : $q;
        }
        $q = intdiv($a, $b);
        if (($a % $b) * 2 >= $b) {
            $q++;
        }
        return $neg ? -$q : $q;
    }

    /** YYYY-MM from 2026-03, 03/2026, 3/2026, 2026-03-15, 03/15/2026 or an Excel date serial. */
    public static function parseMonth($v)
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})$/', $v, $m) || preg_match('/^(\d{1,2})[\/\-.](\d{4})$/', $v, $mm)) {
            if (isset($mm[0])) {
                $m = array(0, $mm[2], $mm[1]);
            }
            $mo = (int)$m[2];
            return $mo >= 1 && $mo <= 12 && (int)$m[1] >= 2000 && (int)$m[1] <= 2099 ? sprintf('%04d-%02d', $m[1], $mo) : null;
        }
        $d = self::parseDate($v);
        return $d ? substr($d, 0, 7) : null;
    }

    public static function parseDate($v, $fmt = 'auto')
    {
        $v = trim((string)$v);
        if ($v === '') {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})(?:[ T].*)?$/', $v, $m)) {
            return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? sprintf('%04d-%02d-%02d', $m[1], $m[2], $m[3]) : null;
        }
        if (preg_match('/^(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})$/', $v, $m)) {
            if ($fmt === 'dmy') {
                list($d, $mo) = array((int)$m[1], (int)$m[2]);
            } else {
                list($mo, $d) = array((int)$m[1], (int)$m[2]);
            }
            return checkdate($mo, $d, (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $mo, $d) : null;
        }
        if (is_numeric($v) && (float)$v > 20000 && (float)$v < 80000) {
            return XlsxReader::serialToDate($v);
        }
        return null;
    }

    /** Stable identity of a counterparty for duplicate detection: TIN and branch, else the upper-cased name. */
    public static function partyKey($tin, $branch, $reg, $last, $first)
    {
        if ($tin) {
            return 'T' . $tin . '-' . ($branch ?: '0000');
        }
        return 'N' . strtoupper(trim(preg_replace('/\s+/', ' ', $reg ? $reg : $last . ', ' . $first)));
    }

    public static function rowHash($mod, $data)
    {
        $parts = array($mod['key']);
        foreach ($mod['fields'] as $f) {
            $k = $f['key'];
            if (in_array($k, array('description', 'rate_exception_note', 'nature_of_payment', 'branch_id'), true)) {
                continue;
            }
            $v = isset($data[$k]) ? (string)$data[$k] : '';
            if ($f['type'] === 'counterparty' && ctype_digit($v) && (int)$v > 0) {
                $cp = DB::row('SELECT tin, branch_code, registered_name, last_name, first_name FROM counterparties WHERE id = ?', array((int)$v));
                $v = $cp ? self::partyKey($cp['tin'], $cp['branch_code'], $cp['registered_name'], $cp['last_name'], $cp['first_name']) : $v;
            }
            $parts[] = $v;
        }
        if (isset($data['branch_id'])) {
            $parts[] = 'B' . (int)$data['branch_id'];
        }
        return sha1(implode('|', $parts));
    }

    // ---------------------------------------------------------------- persistence
    public static function save($mod, $data, $id = null, $reason = null, $source = 'manual', $jobId = null)
    {
        Ctx::requireActive();
        $clientId = Ctx::clientId();
        $lock = self::lockedPeriod($clientId, $data['period_month'], $mod['lock_scope']);
        if ($lock) {
            throw new RuntimeException('The period ' . month_label($data['period_month']) . ' is locked. Ask a reviewer to unlock it first.');
        }
        // Imports pass the hash they checked for duplicates (it counts repeated identical rows); otherwise compute it.
        $data['row_hash'] = !$id && !empty($data['row_hash']) ? $data['row_hash'] : self::rowHash($mod, $data);
        $data['updated_by'] = Auth::id();
        $data['updated_at'] = now();
        if ($id) {
            $old = DB::row('SELECT * FROM ' . $mod['table'] . ' WHERE id = ? AND client_id = ?', array($id, $clientId));
            if (!$old) {
                throw new RuntimeException('Record not found.');
            }
            if ($old['status'] === 'void') {
                throw new RuntimeException('Void records cannot be edited. Restore it first.');
            }
            $oldLock = self::lockedPeriod($clientId, $old['period_month'], $mod['lock_scope']);
            if ($oldLock) {
                throw new RuntimeException('The original period ' . month_label($old['period_month']) . ' is locked.');
            }
            self::revision($mod['table'], $old, $reason);
            DB::update($mod['table'], $data, 'id = ? AND client_id = ?', array($id, $clientId));
            Audit::log('record.update', $mod['table'], $id, 'Edited ' . $mod['singular'], self::diffOld($old, $data), $data, $reason);
            return $id;
        }
        $data['organization_id'] = Ctx::orgId();
        $data['client_id'] = $clientId;
        $data['status'] = 'posted';
        $data['source'] = $source;
        $data['import_job_id'] = $jobId;
        $data['created_by'] = Auth::id();
        $data['created_at'] = now();
        $newId = DB::insert($mod['table'], $data);
        if ($source === 'manual') {
            Audit::log('record.create', $mod['table'], $newId, 'Added ' . $mod['singular'], null, $data);
        }
        return $newId;
    }

    private static function diffOld($old, $new)
    {
        $d = array();
        foreach ($new as $k => $v) {
            if (array_key_exists($k, $old) && (string)$old[$k] !== (string)$v && !in_array($k, array('updated_at', 'updated_by', 'row_hash'), true)) {
                $d[$k] = $old[$k];
            }
        }
        return $d;
    }

    public static function revision($table, $old, $reason)
    {
        $n = (int)DB::val('SELECT COUNT(*) FROM revisions WHERE subject_type = ? AND subject_id = ?', array($table, $old['id'])) + 1;
        DB::insert('revisions', array('organization_id' => $old['organization_id'], 'subject_type' => $table, 'subject_id' => $old['id'],
            'revision_no' => $n, 'data_json' => json_encode($old), 'changed_by' => Auth::id(), 'reason' => $reason, 'changed_at' => now()));
    }

    public static function setVoid($mod, $id, $void, $reason)
    {
        Ctx::requireActive();
        $clientId = Ctx::clientId();
        $old = DB::row('SELECT * FROM ' . $mod['table'] . ' WHERE id = ? AND client_id = ?', array($id, $clientId));
        if (!$old) {
            throw new RuntimeException('Record not found.');
        }
        if (self::lockedPeriod($clientId, $old['period_month'], $mod['lock_scope'])) {
            throw new RuntimeException('The period ' . month_label($old['period_month']) . ' is locked.');
        }
        self::revision($mod['table'], $old, $reason);
        if ($void) {
            DB::update($mod['table'], array('status' => 'void', 'void_reason' => $reason, 'voided_at' => now(), 'updated_by' => Auth::id(), 'updated_at' => now()), 'id = ?', array($id));
            Audit::log('record.void', $mod['table'], $id, 'Voided ' . $mod['singular'], array('status' => $old['status']), array('status' => 'void'), $reason);
        } else {
            DB::update($mod['table'], array('status' => 'posted', 'void_reason' => null, 'voided_at' => null, 'updated_by' => Auth::id(), 'updated_at' => now()), 'id = ?', array($id));
            Audit::log('record.restore', $mod['table'], $id, 'Restored ' . $mod['singular'], array('status' => 'void'), array('status' => 'posted'), $reason);
        }
    }

    /**
     * The vendor of a purchase typed as in the R_Purchases sheet (v_tin, v_branch, v_company, v_last, v_first,
     * v_middle, v_address1, v_address2). Returns array($party, $errors): $party['id'] is set when the TIN
     * is already in the directory; otherwise $party['fields'] holds a new directory entry.
     */
    public static function purchaseVendor($in, $clientId)
    {
        return self::inlineParty($in, $clientId, 'is_supplier', 'vendor');
    }

    /**
     * The same inline party for taxes withheld on us: the payor of the SAWT line (TIN with branch,
     * corporation registered name, or an individual's last, first and middle name).
     */
    public static function salesCustomer($in, $clientId)
    {
        return self::inlineParty($in, $clientId, 'is_customer', 'customer');
    }

    public static function sawtPayor($in, $clientId)
    {
        return self::inlineParty($in, $clientId, 'is_payor', 'payor');
    }

    private static function inlineParty($in, $clientId, $flag, $noun)
    {
        $g = function ($k) use ($in) {
            return isset($in[$k]) ? trim(preg_replace('/\s+/', ' ', (string)$in[$k])) : '';
        };
        $errors = array();
        $tin = null;
        $branch = '0000';
        if ($g('v_tin') !== '') {
            $t = Tin::parse($g('v_tin'));
            if (!$t['ok']) {
                $errors['v_tin'] = $t['error'];
            } else {
                $tin = $t['tin'];
                if ($t['branch'] !== null) {
                    $branch = $t['branch'];
                }
            }
        }
        if ($g('v_branch') !== '') {
            $b = Tin::branch($g('v_branch'));
            if ($b === null) {
                $errors['v_branch'] = 'Branch code must have 4 or 5 digits';
            } else {
                $branch = $b;
            }
        }
        if ($tin && !$errors) {
            $cp = DB::row('SELECT * FROM counterparties WHERE client_id = ? AND tin = ? AND branch_code = ? AND deleted_at IS NULL', array($clientId, $tin, $branch));
            if ($cp) {
                return array(array('id' => (int)$cp['id'], 'entity_type' => $cp['entity_type'], 'fields' => null), array());
            }
        }
        $company = $g('v_company');
        $fields = array('tin' => $tin, 'branch_code' => $branch, 'entity_type' => $company !== '' || $g('v_last') === '' ? 'non_individual' : 'individual',
            'registered_name' => $company, 'last_name' => $g('v_last'), 'first_name' => $g('v_first'), 'middle_name' => $g('v_middle'),
            'address1' => $g('v_address1'), 'address2' => $g('v_address2'), $flag => 1);
        // "DISTRICT, CITY, ZIP CODE": a 4-digit ZIP code at the end of the second line also fills the ZIP code.
        if (preg_match('/(?:^|[\s,])(\d{4})\s*$/', $fields['address2'], $zm)) {
            $fields['zip_code'] = $zm[1];
        }
        if (!$tin && $company === '' && $g('v_last') === '' && !isset($errors['v_tin'])) {
            $errors['v_tin'] = 'Enter the ' . $noun . ' TIN, or at least the ' . $noun . ' name';
            return array(null, $errors);
        }
        list($d, $e) = self::counterpartyValidate(array_merge($fields, array('tin' => $tin ? $tin : '', 'branch_code' => $branch)), $clientId);
        unset($e['tin']);
        $keys = array('registered_name' => 'v_company', 'last_name' => 'v_last', 'first_name' => 'v_first', 'middle_name' => 'v_middle', 'branch_code' => 'v_branch', 'address1' => 'v_address1', 'address2' => 'v_address2');
        foreach ($e as $k => $msg) {
            $errors[isset($keys[$k]) ? $keys[$k] : 'v_tin'] = $k === 'registered_name' ? 'Enter the company name, or the last and first name of a person' : $msg;
        }
        $d['tin'] = $tin;
        $d['branch_code'] = $branch;
        $d[$flag] = 1;
        return array(array('id' => null, 'entity_type' => $d['entity_type'], 'fields' => $d), $errors);
    }

    /** Form values for a purchase vendor from a directory entry. */
    public static function vendorValues($cp)
    {
        return array('counterparty_id' => (int)$cp['id'], 'v_tin' => $cp['tin'] ? Tin::display($cp['tin']) : '', 'v_branch' => $cp['branch_code'],
            'v_company' => (string)$cp['registered_name'], 'v_last' => (string)$cp['last_name'], 'v_first' => (string)$cp['first_name'],
            'v_middle' => (string)$cp['middle_name'], 'v_address1' => (string)$cp['address1'], 'v_address2' => (string)$cp['address2']);
    }

    // ---------------------------------------------------------------- counterparties
    const DUP_TIN = 'This TIN and branch code already exist in the directory';

    public static function counterpartyValidate($in, $clientId, $id = null)
    {
        $e = array();
        $d = array();
        $d['entity_type'] = isset($in['entity_type']) && $in['entity_type'] === 'individual' ? 'individual' : 'non_individual';
        $tinRaw = isset($in['tin']) ? trim($in['tin']) : '';
        $d['tin'] = null;
        if ($tinRaw !== '') {
            $t = Tin::parse($tinRaw);
            if (!$t['ok']) {
                $e['tin'] = $t['error'];
            } else {
                $d['tin'] = $t['tin'];
                if ($t['branch'] !== null && (!isset($in['branch_code']) || trim($in['branch_code']) === '')) {
                    $in['branch_code'] = $t['branch'];
                }
            }
        }
        $br = Tin::branch(isset($in['branch_code']) ? $in['branch_code'] : '0000');
        if ($br === null) {
            $e['branch_code'] = 'Branch code must have 4 or 5 digits';
        }
        $d['branch_code'] = $br ?: '0000';
        foreach (array('registered_name' => 150, 'last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'address1' => 150, 'address2' => 150,
                     'zip_code' => 6, 'foreign_address' => 200, 'email' => 190, 'default_atc' => 10, 'notes' => 255) as $k => $max) {
            $v = isset($in[$k]) ? trim((string)$in[$k]) : '';
            if (mb_strlen($v) > $max) {
                $e[$k] = 'Too long (max ' . $max . ')';
            }
            $d[$k] = $v === '' ? null : $v;
        }
        if ($d['entity_type'] === 'individual') {
            if (!$d['last_name'] || !$d['first_name']) {
                $e['last_name'] = 'Individuals need a last name and first name';
            }
            $d['registered_name'] = null;
        } else {
            if (!$d['registered_name']) {
                $e['registered_name'] = 'Registered name is required for non-individuals';
            }
            $d['last_name'] = $d['first_name'] = $d['middle_name'] = null;
        }
        if ($d['email'] && !filter_var($d['email'], FILTER_VALIDATE_EMAIL)) {
            $e['email'] = 'Enter a valid email';
        }
        if ($d['zip_code'] && !preg_match('/^\d{4}$/', $d['zip_code'])) {
            $e['zip_code'] = 'ZIP code has 4 digits';
        }
        foreach (array('is_supplier', 'is_customer', 'is_payee', 'is_payor') as $flag) {
            $d[$flag] = !empty($in[$flag]) ? 1 : 0;
        }
        if ($d['tin'] && !isset($e['tin'])) {
            $dup = DB::val('SELECT id FROM counterparties WHERE client_id = ? AND tin = ? AND branch_code = ? AND deleted_at IS NULL' . ($id ? ' AND id <> ?' : ''),
                $id ? array($clientId, $d['tin'], $d['branch_code'], $id) : array($clientId, $d['tin'], $d['branch_code']));
            if ($dup) {
                $e['tin'] = self::DUP_TIN;
            }
        }
        return array($d, $e);
    }

    /** Find or create a counterparty from an imported row. Returns id. */
    public static function resolveCounterparty($clientId, $p, $role, $source, $jobId)
    {
        if (!empty($p['tin'])) {
            $cp = DB::row('SELECT * FROM counterparties WHERE client_id = ? AND tin = ? AND branch_code = ? AND deleted_at IS NULL', array($clientId, $p['tin'], $p['branch_code']));
        } else {
            $name = $p['entity_type'] === 'individual' ? array($p['last_name'], $p['first_name']) : array($p['registered_name']);
            $cp = $p['entity_type'] === 'individual'
                ? DB::row('SELECT * FROM counterparties WHERE client_id = ? AND tin IS NULL AND last_name = ? AND first_name = ? AND deleted_at IS NULL', array($clientId, $name[0], $name[1]))
                : DB::row('SELECT * FROM counterparties WHERE client_id = ? AND tin IS NULL AND registered_name = ? AND deleted_at IS NULL', array($clientId, $name[0]));
        }
        $flag = 'is_' . $role;
        if ($cp) {
            if (empty($cp[$flag])) {
                DB::update('counterparties', array($flag => 1), 'id = ?', array($cp['id']));
            }
            return (int)$cp['id'];
        }
        $row = array_merge(array('organization_id' => Ctx::orgId(), 'client_id' => $clientId, 'status' => 'active', 'source' => $source,
            'created_by' => Auth::id(), 'created_at' => now(), 'updated_at' => now()), $p);
        $row[$flag] = 1;
        return DB::insert('counterparties', $row);
    }
}

/**
 * Employees and annual compensation (1604-C and 2316 source records).
 */
class Payroll
{
    const STATUSES = 'R,C,CP,S,P,AL';
    const SEPARATIONS = 'NA,T,TR,R,D';

    public static function statusOptions()
    {
        return array('R' => 'R: Regular', 'C' => 'C: Casual', 'CP' => 'CP: Contractual or project-based', 'S' => 'S: Seasonal', 'P' => 'P: Probationary', 'AL' => 'AL: Apprentice or learner');
    }

    public static function separationOptions()
    {
        return array('NA' => 'NA: Not applicable', 'T' => 'T: Terminated', 'TR' => 'TR: Transferred', 'R' => 'R: Retired', 'D' => 'D: Death');
    }

    public static function regions()
    {
        return array('NCR', 'CAR', 'I', 'II', 'III', 'IV-A', 'IV-B', 'V', 'VI', 'VII', 'VIII', 'IX', 'X', 'XI', 'XII', 'XIII', 'BARMM', 'NIR');
    }

    public static function employeeValidate($in, $clientId, $id = null)
    {
        $e = array();
        $d = array();
        $t = Tin::parse(isset($in['tin']) ? $in['tin'] : '');
        if (!$t['ok']) {
            $e['tin'] = $t['error'];
            $d['tin'] = null;
        } else {
            $d['tin'] = $t['tin'];
        }
        $br = Tin::branch(isset($in['tin_branch']) && $in['tin_branch'] !== '' ? $in['tin_branch'] : ($t['branch'] !== null ? $t['branch'] : '0000'));
        $d['tin_branch'] = $br ?: '0000';
        foreach (array('employee_no' => 30, 'last_name' => 60, 'first_name' => 60, 'middle_name' => 60, 'address' => 200, 'zip_code' => 6, 'contact_no' => 40, 'nationality' => 40) as $k => $max) {
            $v = isset($in[$k]) ? trim((string)$in[$k]) : '';
            if (mb_strlen($v) > $max) {
                $e[$k] = 'Too long';
            }
            $d[$k] = $v === '' ? null : $v;
        }
        if (!$d['last_name']) {
            $e['last_name'] = 'Last name is required';
        }
        if (!$d['first_name']) {
            $e['first_name'] = 'First name is required';
        }
        $d['nationality'] = $d['nationality'] ? strtoupper($d['nationality']) : 'FILIPINO';
        $d['region_code'] = isset($in['region_code']) && in_array($in['region_code'], self::regions(), true) ? $in['region_code'] : null;
        $d['employment_status'] = isset($in['employment_status']) && in_array($in['employment_status'], explode(',', self::STATUSES), true) ? $in['employment_status'] : 'R';
        $d['is_mwe'] = !empty($in['is_mwe']) ? 1 : 0;
        foreach (array('birth_date', 'hired_on', 'separated_on') as $k) {
            $v = isset($in[$k]) ? $in[$k] : '';
            $d[$k] = $v === '' ? null : Records::parseDate($v);
            if ($v !== '' && !$d[$k]) {
                $e[$k] = 'Invalid date';
            }
        }
        $sep = isset($in['separation_reason']) ? $in['separation_reason'] : '';
        $d['separation_reason'] = in_array($sep, explode(',', self::SEPARATIONS), true) ? $sep : null;
        $d['branch_id'] = !empty($in['branch_id']) ? (int)$in['branch_id'] : null;
        if ($d['tin']) {
            $dup = DB::val('SELECT id FROM employees WHERE client_id = ? AND tin = ? AND deleted_at IS NULL' . ($id ? ' AND id <> ?' : ''), $id ? array($clientId, $d['tin'], $id) : array($clientId, $d['tin']));
            if ($dup) {
                $e['tin'] = 'An employee with this TIN already exists';
            }
        }
        return array($d, $e);
    }

    public static function compValidate($in, $clientId, $options = array())
    {
        $e = array();
        $w = array();
        $d = array();
        $year = (int)(isset($in['tax_year']) ? $in['tax_year'] : 0);
        if ($year < 2000 || $year > 2100) {
            $e['tax_year'] = 'Enter a tax year such as ' . date('Y');
        }
        $d['tax_year'] = $year;
        $d['schedule_code'] = isset($in['schedule_code']) && $in['schedule_code'] === 'S2' ? 'S2' : 'S1';
        if (empty($options['skip_party'])) {
            $emp = DB::row('SELECT * FROM employees WHERE id = ? AND client_id = ? AND deleted_at IS NULL', array(isset($in['employee_id']) ? $in['employee_id'] : 0, $clientId));
            if (!$emp) {
                $e['employee_id'] = 'Choose an employee';
            }
            $d['employee_id'] = $emp ? (int)$emp['id'] : 0;
        }
        $bid = isset($in['branch_id']) ? (int)$in['branch_id'] : 0;
        if (!DB::val('SELECT id FROM branches WHERE id = ? AND client_id = ?', array($bid, $clientId))) {
            $e['branch_id'] = 'Choose a branch';
        }
        $d['branch_id'] = $bid;
        $reg = isset($in['region_code']) ? strtoupper(trim($in['region_code'])) : '';
        if (!in_array($reg, self::regions(), true)) {
            $e['region_code'] = 'Choose a region';
        }
        $d['region_code'] = $reg;
        foreach (array('employment_from', 'employment_to') as $k) {
            $v = isset($in[$k]) ? $in[$k] : '';
            $d[$k] = Records::parseDate($v, isset($options['date_format']) ? $options['date_format'] : 'auto');
            if (!$d[$k]) {
                $e[$k] = 'Enter a valid date (MM/DD/YYYY)';
            } elseif ($year && substr($d[$k], 0, 4) != $year) {
                $w[$k] = 'Date is outside tax year ' . $year;
            }
        }
        if ($d['employment_from'] && $d['employment_to'] && $d['employment_from'] > $d['employment_to']) {
            $e['employment_to'] = 'End date is before start date';
        }
        $d['has_previous_employer'] = !empty($in['has_previous_employer']) ? 1 : 0;
        foreach (Records::compFields() as $group => $fields) {
            foreach ($fields as $k => $label) {
                $p = Money::parse(isset($in[$k]) ? $in[$k] : '');
                if (!$p['ok']) {
                    $e[$k] = $label . ': ' . $p['error'];
                    $d[$k] = '0.00';
                } else {
                    if ($p['rounded']) {
                        $w[$k] = $label . ' was rounded to 2 decimals';
                    }
                    if ($p['cents'] < 0) {
                        $e[$k] = $label . ' cannot be negative';
                    }
                    $d[$k] = Money::toPlain($p['cents']);
                }
            }
        }
        $fd = isset($in['factor_days']) ? trim($in['factor_days']) : '';
        $d['factor_days'] = $fd === '' ? null : (int)$fd;
        $d['nationality'] = strtoupper(trim(isset($in['nationality']) && $in['nationality'] !== '' ? $in['nationality'] : 'FILIPINO'));
        $st = isset($in['employment_status']) ? strtoupper(trim($in['employment_status'])) : 'R';
        if (!in_array($st, explode(',', self::STATUSES), true)) {
            $e['employment_status'] = 'Status must be one of ' . self::STATUSES;
        }
        $d['employment_status'] = $st;
        $sep = isset($in['separation_reason']) && $in['separation_reason'] !== '' ? strtoupper(trim($in['separation_reason'])) : 'NA';
        if (!in_array($sep, explode(',', self::SEPARATIONS), true)) {
            $e['separation_reason'] = 'Reason must be one of ' . self::SEPARATIONS;
        }
        $d['separation_reason'] = $sep;
        $sf = isset($in['substituted_filing']) ? strtoupper(trim($in['substituted_filing'])) : 'Y';
        $d['substituted_filing'] = $sf === 'N' ? 'N' : 'Y';
        if (!$d['has_previous_employer']) {
            foreach (Records::compFields()['Previous employer'] as $k => $l) {
                if ($d[$k] !== '0.00') {
                    $w[$k] = 'Previous employer amounts are filled but "has previous employer" is off';
                    break;
                }
            }
        }
        foreach (Checks::compArithmetic($d) as $msg) {
            $w['arith_' . md5($msg)] = $msg;
        }
        return array($d, $e, $w);
    }

    public static function compSave($d, $id = null, $reason = null, $source = 'manual', $jobId = null)
    {
        Ctx::requireActive();
        $clientId = Ctx::clientId();
        $mod = array('key' => 'compensation', 'fields' => array());
        $d['row_hash'] = sha1('comp|' . $d['employee_id'] . '|' . $d['tax_year'] . '|' . $d['schedule_code'] . '|' . $d['pres_gross'] . '|' . $d['tax_due']);
        $d['updated_by'] = Auth::id();
        $d['updated_at'] = now();
        $lockMonth = $d['tax_year'] . '-12';
        if (Records::lockedPeriod($clientId, $lockMonth, 'compensation')) {
            throw new RuntimeException('Compensation for ' . $d['tax_year'] . ' is locked.');
        }
        if ($id) {
            $old = DB::row('SELECT * FROM compensation_records WHERE id = ? AND client_id = ?', array($id, $clientId));
            if (!$old) {
                throw new RuntimeException('Record not found.');
            }
            Records::revision('compensation_records', $old, $reason);
            DB::update('compensation_records', $d, 'id = ? AND client_id = ?', array($id, $clientId));
            Audit::log('record.update', 'compensation_records', $id, 'Edited compensation record', null, $d, $reason);
            return $id;
        }
        $exists = DB::val('SELECT id FROM compensation_records WHERE client_id = ? AND employee_id = ? AND tax_year = ? AND schedule_code = ? AND status = ?',
            array($clientId, $d['employee_id'], $d['tax_year'], $d['schedule_code'], 'posted'));
        if ($exists) {
            throw new RuntimeException('This employee already has a ' . $d['tax_year'] . ' compensation record for this schedule. Edit that record instead.');
        }
        $d = array_merge($d, array('organization_id' => Ctx::orgId(), 'client_id' => $clientId, 'status' => 'posted', 'source' => $source,
            'import_job_id' => $jobId, 'created_by' => Auth::id(), 'created_at' => now()));
        $nid = DB::insert('compensation_records', $d);
        if ($source === 'manual') {
            Audit::log('record.create', 'compensation_records', $nid, 'Added compensation record', null, $d);
        }
        return $nid;
    }
}
