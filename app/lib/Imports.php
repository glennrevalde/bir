<?php
/**
 * Spreadsheet import pipeline: templates, column mapping, staging, review, commit, rollback.
 * Excel rows always land in the same tables as direct entry, so both pathways produce the
 * same DAT bytes from the same serializer.
 */
class Imports
{
    const TEMPLATE_VERSION = '1.0';

    private static function party($label)
    {
        return array(
            array('key' => 'p_tin', 'title' => $label . ' TIN', 'format' => 'text', 'width' => 16, 'help' => '9 digits, dashes allowed. Keep the column formatted as Text.', 'example' => '123-456-789'),
            array('key' => 'p_branch', 'title' => $label . ' BRANCH CODE', 'format' => 'text', 'width' => 12, 'help' => '4 digits; 0000 for head office.', 'example' => '0000'),
            array('key' => 'p_registered_name', 'title' => 'REGISTERED NAME', 'format' => 'text', 'width' => 32, 'help' => 'For non-individuals. Leave blank for individuals.', 'example' => 'ACME TRADING CORP'),
            array('key' => 'p_last_name', 'title' => 'LAST NAME', 'format' => 'text', 'width' => 18, 'help' => 'For individuals only.', 'example' => ''),
            array('key' => 'p_first_name', 'title' => 'FIRST NAME', 'format' => 'text', 'width' => 18, 'help' => 'For individuals only.', 'example' => ''),
            array('key' => 'p_middle_name', 'title' => 'MIDDLE NAME', 'format' => 'text', 'width' => 18, 'help' => 'For individuals only.', 'example' => ''),
            array('key' => 'p_address1', 'title' => 'ADDRESS 1', 'format' => 'text', 'width' => 28, 'help' => 'Street, barangay.', 'example' => '12 RIZAL ST BRGY CAPITOL'),
            array('key' => 'p_address2', 'title' => 'ADDRESS 2', 'format' => 'text', 'width' => 22, 'help' => 'Town or city, province.', 'example' => 'CEBU CITY'),
        );
    }

    public static function types()
    {
        $branch = array('key' => 'client_branch', 'title' => 'CLIENT BRANCH CODE', 'format' => 'text', 'width' => 12, 'help' => 'Branch of this client the entry belongs to. Blank uses the branch chosen at upload.', 'example' => '0000');
        $t = array(
            'sales' => array('label' => 'Sales', 'code' => 'GSB-SALES', 'module' => 'sales', 'role' => 'customer', 'columns' => array_merge(self::party('CUSTOMER'), array(
                $branch,
                array('key' => 'invoice_no', 'title' => 'INVOICE NO', 'format' => 'text', 'width' => 14, 'example' => 'SI-000101'),
                array('key' => 'invoice_date', 'title' => 'INVOICE DATE', 'format' => 'text', 'width' => 13, 'required' => true, 'help' => 'MM/DD/YYYY', 'example' => '01/15/2026'),
                array('key' => 'exempt_amount', 'title' => 'EXEMPT SALES', 'format' => 'amount', 'width' => 14, 'example' => '0'),
                array('key' => 'zero_rated_amount', 'title' => 'ZERO RATED SALES', 'format' => 'amount', 'width' => 14, 'example' => '0'),
                array('key' => 'taxable_amount', 'title' => 'TAXABLE SALES', 'format' => 'amount', 'width' => 14, 'help' => 'Net of VAT.', 'example' => '100000.00'),
                array('key' => 'output_vat', 'title' => 'OUTPUT VAT', 'format' => 'amount', 'width' => 14, 'example' => '12000.00'),
                array('key' => 'description', 'title' => 'DESCRIPTION', 'format' => 'text', 'width' => 24, 'example' => ''),
            ))),
            'purchases' => array('label' => 'Purchases', 'code' => 'GSB-PURCHASES', 'module' => 'purchases', 'role' => 'supplier', 'columns' => array_merge(self::party('SUPPLIER'), array(
                $branch,
                array('key' => 'invoice_no', 'title' => 'INVOICE NO', 'format' => 'text', 'width' => 14, 'example' => 'INV-5521'),
                array('key' => 'invoice_date', 'title' => 'INVOICE DATE', 'format' => 'text', 'width' => 13, 'required' => true, 'help' => 'MM/DD/YYYY', 'example' => '01/20/2026'),
                array('key' => 'exempt_amount', 'title' => 'EXEMPT', 'format' => 'amount', 'width' => 12, 'example' => '0'),
                array('key' => 'zero_rated_amount', 'title' => 'ZERO RATED', 'format' => 'amount', 'width' => 12, 'example' => '0'),
                array('key' => 'services_amount', 'title' => 'SERVICES', 'format' => 'amount', 'width' => 12, 'example' => '0'),
                array('key' => 'capital_goods_amount', 'title' => 'CAPITAL GOODS', 'format' => 'amount', 'width' => 14, 'example' => '0'),
                array('key' => 'other_goods_amount', 'title' => 'GOODS OTHER THAN CAPITAL GOODS', 'format' => 'amount', 'width' => 16, 'example' => '50000.00'),
                array('key' => 'input_vat', 'title' => 'INPUT VAT', 'format' => 'amount', 'width' => 12, 'example' => '6000.00'),
                array('key' => 'non_creditable_vat', 'title' => 'NON-CREDITABLE INPUT VAT', 'format' => 'amount', 'width' => 14, 'example' => '0'),
                array('key' => 'description', 'title' => 'DESCRIPTION', 'format' => 'text', 'width' => 24, 'example' => ''),
            ))),
            'importations' => array('label' => 'Importations', 'code' => 'GSB-IMPORTS', 'module' => 'importations', 'role' => 'supplier', 'columns' => array_merge(self::party('SELLER'), array(
                $branch,
                array('key' => 'import_entry_no', 'title' => 'IMPORT ENTRY NO', 'format' => 'text', 'width' => 16, 'required' => true, 'example' => 'IE-2026-0001'),
                array('key' => 'importation_date', 'title' => 'IMPORTATION DATE', 'format' => 'text', 'width' => 13, 'required' => true, 'example' => '01/10/2026'),
                array('key' => 'assessment_date', 'title' => 'ASSESSMENT DATE', 'format' => 'text', 'width' => 13, 'example' => '01/12/2026'),
                array('key' => 'country_origin', 'title' => 'COUNTRY OF ORIGIN', 'format' => 'text', 'width' => 16, 'example' => 'JAPAN'),
                array('key' => 'landed_cost', 'title' => 'LANDED COST', 'format' => 'amount', 'width' => 14, 'example' => '250000.00'),
                array('key' => 'other_charges', 'title' => 'OTHER CHARGES', 'format' => 'amount', 'width' => 14, 'example' => '0'),
                array('key' => 'exempt_amount', 'title' => 'EXEMPT', 'format' => 'amount', 'width' => 12, 'example' => '0'),
                array('key' => 'taxable_amount', 'title' => 'TAXABLE', 'format' => 'amount', 'width' => 14, 'example' => '250000.00'),
                array('key' => 'vat_amount', 'title' => 'VAT PAID', 'format' => 'amount', 'width' => 12, 'example' => '30000.00'),
                array('key' => 'or_no', 'title' => 'OR NO', 'format' => 'text', 'width' => 12, 'example' => ''),
                array('key' => 'vat_payment_date', 'title' => 'VAT PAYMENT DATE', 'format' => 'text', 'width' => 13, 'example' => ''),
            ))),
            'withholding' => array('label' => 'Taxes we withheld (EWT, FWT, government)', 'code' => 'GSB-WH-AGENT', 'module' => 'withholding', 'role' => 'payee', 'columns' => array_merge(self::party('PAYEE'), array(
                $branch,
                array('key' => 'tax_kind', 'title' => 'KIND', 'format' => 'text', 'width' => 8, 'help' => 'EWT, FWT, GVAT or GPT. Blank means EWT.', 'example' => 'EWT', 'list' => array('EWT', 'FWT', 'GVAT', 'GPT')),
                array('key' => 'atc_code', 'title' => 'ATC', 'format' => 'text', 'width' => 9, 'required' => true, 'example' => 'WC158'),
                array('key' => 'payment_date', 'title' => 'PAYMENT DATE', 'format' => 'text', 'width' => 13, 'required' => true, 'example' => '01/31/2026'),
                array('key' => 'nature_of_payment', 'title' => 'NATURE OF PAYMENT', 'format' => 'text', 'width' => 24, 'example' => 'Purchase of goods'),
                array('key' => 'income_amount', 'title' => 'INCOME PAYMENT', 'format' => 'amount', 'width' => 14, 'required' => true, 'example' => '100000.00'),
                array('key' => 'tax_rate', 'title' => 'TAX RATE', 'format' => 'text', 'width' => 9, 'help' => 'Percent. Blank uses the ATC rate.', 'example' => '1'),
                array('key' => 'tax_withheld', 'title' => 'TAX WITHHELD', 'format' => 'amount', 'width' => 13, 'help' => 'Blank computes it from the rate.', 'example' => '1000.00'),
                array('key' => 'schedule_tag', 'title' => 'SCHEDULE', 'format' => 'text', 'width' => 9, 'help' => 'A, B or C. Blank means A.', 'example' => 'A', 'list' => array('A', 'B', 'C')),
                array('key' => 'reference_no', 'title' => 'REFERENCE NO', 'format' => 'text', 'width' => 14, 'example' => ''),
                array('key' => 'rate_exception_note', 'title' => 'RATE EXCEPTION NOTE', 'format' => 'text', 'width' => 24, 'help' => 'Required when rate or tax differs from the ATC.', 'example' => ''),
            ))),
            'withheld_on_us' => array('label' => 'Taxes withheld on us (2307 received, SAWT)', 'code' => 'GSB-WH-RECEIVED', 'module' => 'withheld_on_us', 'role' => 'payor', 'columns' => array_merge(self::party('PAYOR'), array(
                $branch,
                array('key' => 'atc_code', 'title' => 'ATC', 'format' => 'text', 'width' => 9, 'required' => true, 'example' => 'WC160'),
                array('key' => 'payment_date', 'title' => 'PAYMENT DATE', 'format' => 'text', 'width' => 13, 'required' => true, 'example' => '02/28/2026'),
                array('key' => 'income_amount', 'title' => 'INCOME PAYMENT', 'format' => 'amount', 'width' => 14, 'required' => true, 'example' => '80000.00'),
                array('key' => 'tax_rate', 'title' => 'TAX RATE', 'format' => 'text', 'width' => 9, 'example' => '2'),
                array('key' => 'tax_withheld', 'title' => 'TAX WITHHELD', 'format' => 'amount', 'width' => 13, 'example' => '1600.00'),
                array('key' => 'reference_no', 'title' => 'CERTIFICATE REFERENCE', 'format' => 'text', 'width' => 16, 'example' => ''),
                array('key' => 'rate_exception_note', 'title' => 'RATE EXCEPTION NOTE', 'format' => 'text', 'width' => 24, 'example' => ''),
            ))),
            'counterparties' => array('label' => 'Directory: customers, suppliers, payees', 'code' => 'GSB-DIRECTORY', 'module' => null, 'role' => null, 'columns' => array_merge(self::party(''), array(
                array('key' => 'p_zip', 'title' => 'ZIP CODE', 'format' => 'text', 'width' => 9, 'example' => '6000'),
                array('key' => 'roles', 'title' => 'ROLES', 'format' => 'text', 'width' => 26, 'help' => 'Any of: SUPPLIER, CUSTOMER, PAYEE, PAYOR (comma separated).', 'example' => 'SUPPLIER,PAYEE'),
                array('key' => 'default_atc', 'title' => 'DEFAULT ATC', 'format' => 'text', 'width' => 10, 'example' => 'WC158'),
            ))),
            'compensation' => array('label' => 'Annual compensation (1604-C, 2316)', 'code' => 'GSB-COMP', 'module' => null, 'role' => null, 'columns' => self::compColumns($branch)),
        );
        foreach ($t as $k => $v) {
            $t[$k]['key'] = $k;
        }
        return $t;
    }

    private static function compColumns($branch)
    {
        $c = array(
            array('key' => 'e_tin', 'title' => 'EMPLOYEE TIN', 'format' => 'text', 'width' => 16, 'required' => true, 'example' => '901-234-567'),
            array('key' => 'e_branch', 'title' => 'EMPLOYEE TIN BRANCH', 'format' => 'text', 'width' => 10, 'example' => '0000'),
            array('key' => 'e_no', 'title' => 'EMPLOYEE NO', 'format' => 'text', 'width' => 12, 'example' => 'E-001'),
            array('key' => 'e_last', 'title' => 'LAST NAME', 'format' => 'text', 'width' => 16, 'required' => true, 'example' => 'REYES'),
            array('key' => 'e_first', 'title' => 'FIRST NAME', 'format' => 'text', 'width' => 16, 'required' => true, 'example' => 'MARIA'),
            array('key' => 'e_middle', 'title' => 'MIDDLE NAME', 'format' => 'text', 'width' => 16, 'example' => 'SANTOS'),
            $branch,
            array('key' => 'tax_year', 'title' => 'TAX YEAR', 'format' => 'text', 'width' => 9, 'required' => true, 'example' => '2025'),
            array('key' => 'schedule_code', 'title' => 'SCHEDULE', 'format' => 'text', 'width' => 9, 'help' => 'S1 (other than MWE) or S2 (MWE).', 'example' => 'S1', 'list' => array('S1', 'S2')),
            array('key' => 'region_code', 'title' => 'REGION', 'format' => 'text', 'width' => 9, 'required' => true, 'example' => 'NCR', 'list' => Payroll::regions()),
            array('key' => 'employment_from', 'title' => 'EMPLOYMENT FROM', 'format' => 'text', 'width' => 13, 'required' => true, 'example' => '01/01/2025'),
            array('key' => 'employment_to', 'title' => 'EMPLOYMENT TO', 'format' => 'text', 'width' => 13, 'required' => true, 'example' => '12/31/2025'),
            array('key' => 'has_previous_employer', 'title' => 'HAS PREVIOUS EMPLOYER', 'format' => 'text', 'width' => 10, 'help' => 'Y or N', 'example' => 'N', 'list' => array('Y', 'N')),
        );
        foreach (Records::compFields() as $group => $fields) {
            foreach ($fields as $k => $label) {
                $c[] = array('key' => $k, 'title' => strtoupper(($group === 'Previous employer' ? 'PREV ' : ($group === 'Present employer' ? 'PRES ' : '')) . $label), 'format' => 'amount', 'width' => 14, 'example' => '0');
            }
        }
        $c[] = array('key' => 'factor_days', 'title' => 'FACTOR DAYS', 'format' => 'text', 'width' => 9, 'example' => '');
        $c[] = array('key' => 'nationality', 'title' => 'NATIONALITY', 'format' => 'text', 'width' => 12, 'example' => 'FILIPINO');
        $c[] = array('key' => 'employment_status', 'title' => 'EMPLOYMENT STATUS', 'format' => 'text', 'width' => 10, 'example' => 'R', 'list' => explode(',', Payroll::STATUSES));
        $c[] = array('key' => 'separation_reason', 'title' => 'SEPARATION REASON', 'format' => 'text', 'width' => 10, 'example' => 'NA', 'list' => explode(',', Payroll::SEPARATIONS));
        $c[] = array('key' => 'substituted_filing', 'title' => 'SUBSTITUTED FILING', 'format' => 'text', 'width' => 10, 'example' => 'Y', 'list' => array('Y', 'N'));
        return $c;
    }

    public static function type($key)
    {
        $t = self::types();
        if (!isset($t[$key])) {
            throw new RuntimeException('Unknown import type.');
        }
        return $t[$key];
    }

    // ------------------------------------------------------------------ templates
    public static function template($typeKey, $withSamples)
    {
        $type = self::type($typeKey);
        $cols = $type['columns'];
        $rows = array();
        if ($withSamples) {
            $rows = self::sampleRows($typeKey, $cols);
        }
        $lists = array();
        foreach ($cols as $i => $c) {
            if (!empty($c['list'])) {
                $lists[$i] = $c['list'];
            }
        }
        $fieldRows = array();
        foreach ($cols as $c) {
            $fieldRows[] = array($c['title'], !empty($c['required']) ? 'Yes' : 'No', $c['format'] === 'amount' ? 'Amount, 2 decimals' : 'Text',
                isset($c['help']) ? $c['help'] : '', !empty($c['list']) ? implode(', ', $c['list']) : '', isset($c['example']) ? $c['example'] : '');
        }
        return XlsxWriter::build(array(
            array('name' => 'Data', 'columns' => $cols, 'rows' => $rows, 'freeze' => true, 'protect_header' => true, 'lists' => $lists),
            array('name' => 'Fields', 'columns' => array(
                array('title' => 'Column', 'width' => 34, 'format' => 'text'), array('title' => 'Required', 'width' => 10, 'format' => 'text'),
                array('title' => 'Type', 'width' => 18, 'format' => 'text'), array('title' => 'How to fill', 'width' => 60, 'format' => 'text'),
                array('title' => 'Allowed values', 'width' => 30, 'format' => 'text'), array('title' => 'Example', 'width' => 22, 'format' => 'text')),
                'rows' => $fieldRows, 'freeze' => true),
            array('name' => 'About', 'columns' => array(array('title' => 'Key', 'width' => 22, 'format' => 'text'), array('title' => 'Value', 'width' => 80, 'format' => 'text')),
                'rows' => array(
                    array('Template code', $type['code']), array('Template version', self::TEMPLATE_VERSION), array('Import type', $type['label']),
                    array('Generated', date('Y-m-d H:i')), array('Rules', 'Keep the header row unchanged. Enter one record per row on the Data sheet. Keep TIN columns as Text so leading zeros stay. Dates as MM/DD/YYYY.'),
                    array('Note', 'This workbook is an input template for G-SMaRT BIR. It is not a BIR form and is not submitted to the BIR.'),
                )),
        ), array('title' => $type['label'] . ' import template'));
    }

    private static function sampleRows($typeKey, $cols)
    {
        $samples = array();
        $base = array();
        foreach ($cols as $c) {
            $base[$c['key']] = isset($c['example']) ? $c['example'] : '';
        }
        if ($typeKey === 'withholding') {
            $samples[] = $base;
            $b = $base;
            $b['p_tin'] = '234-567-890';
            $b['p_registered_name'] = '';
            $b['p_last_name'] = 'DELA CRUZ';
            $b['p_first_name'] = 'JUAN';
            $b['p_middle_name'] = 'SANTOS';
            $b['atc_code'] = 'WI010';
            $b['nature_of_payment'] = 'Professional fees';
            $b['income_amount'] = '20000.00';
            $b['tax_rate'] = '5';
            $b['tax_withheld'] = '1000.00';
            $samples[] = $b;
        } else {
            $samples[] = $base;
        }
        $out = array();
        foreach ($samples as $s) {
            $row = array();
            foreach ($cols as $c) {
                $row[] = $s[$c['key']];
            }
            $out[] = $row;
        }
        return $out;
    }

    // ------------------------------------------------------------------ reading + mapping
    public static function normHeader($s)
    {
        return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));
    }

    public static function readRows($job, $limit = null)
    {
        $abs = storage_path($job['stored_path']);
        $ext = strtolower(pathinfo($job['stored_path'], PATHINFO_EXTENSION));
        if ($ext === 'csv' || $ext === 'txt') {
            return CsvReader::rows($abs, $limit);
        }
        $r = new XlsxReader($abs);
        return $r->rows($job['sheet_name'] ?: null, $limit);
    }

    public static function sheetNames($job)
    {
        $ext = strtolower(pathinfo($job['stored_path'], PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return array();
        }
        $r = new XlsxReader(storage_path($job['stored_path']));
        return $r->sheetNames();
    }

    public static function headersOf($rows, $headerRow)
    {
        $h = array();
        if (isset($rows[$headerRow])) {
            foreach ($rows[$headerRow] as $i => $cell) {
                if (trim($cell['v']) !== '') {
                    $h[$i] = trim($cell['v']);
                }
            }
        }
        return $h;
    }

    /** Suggest target->source mapping; returns array($mapping, $exactTemplate). */
    public static function suggestMapping($type, $headers)
    {
        $byNorm = array();
        foreach ($headers as $i => $h) {
            $byNorm[self::normHeader($h)] = $i;
        }
        $map = array();
        $exact = true;
        $aliases = array('p_tin' => array('TIN', 'TINNO', 'TAXIDENTIFICATIONNUMBER', 'PAYEETIN', 'SUPPLIERTIN', 'CUSTOMERTIN', 'PAYORTIN'),
            'p_registered_name' => array('NAME', 'COMPANYNAME', 'CORPORATENAME', 'SUPPLIERSNAME', 'CUSTOMERSNAME', 'PAYEENAME', 'PAYORNAME'),
            'p_last_name' => array('SURNAME', 'LASTNAME'), 'income_amount' => array('INCOMEPAYMENT', 'AMOUNTOFINCOMEPAYMENT', 'TAXBASE', 'AMOUNT'),
            'tax_withheld' => array('TAXWITHHELD', 'AMOUNTOFTAXWITHHELD', 'EWT', 'WITHHOLDINGTAX', 'CWT'), 'atc_code' => array('ATC', 'ATCCODE'),
            'invoice_date' => array('DATE', 'INVOICEDATE', 'TRANSACTIONDATE'), 'payment_date' => array('DATE', 'PAYMENTDATE', 'DATEOFPAYMENT'),
            'taxable_amount' => array('TAXABLESALES', 'TAXABLE', 'NETTAXABLE'), 'output_vat' => array('OUTPUTVAT', 'OUTPUTTAX', 'VAT'), 'input_vat' => array('INPUTVAT', 'INPUTTAX'));
        foreach ($type['columns'] as $c) {
            $n = self::normHeader($c['title']);
            if (isset($byNorm[$n])) {
                $map[$c['key']] = $byNorm[$n];
                continue;
            }
            $exact = false;
            if (isset($aliases[$c['key']])) {
                foreach ($aliases[$c['key']] as $a) {
                    if (isset($byNorm[$a]) && !in_array($byNorm[$a], $map, true)) {
                        $map[$c['key']] = $byNorm[$a];
                        break;
                    }
                }
            }
        }
        return array($map, $exact && count($headers) === count($type['columns']));
    }

    public static function mappingProblems($type, $map)
    {
        $p = array();
        $used = array();
        foreach ($map as $target => $src) {
            if ($src === '' || $src === null) {
                continue;
            }
            if (isset($used[$src])) {
                $p[] = 'Column ' . ($src + 1) . ' is mapped to two fields (' . $used[$src] . ' and ' . $target . '). Each source column can feed one field.';
            }
            $used[$src] = $target;
        }
        foreach ($type['columns'] as $c) {
            if (!empty($c['required']) && (!isset($map[$c['key']]) || $map[$c['key']] === '' || $map[$c['key']] === null)) {
                $p[] = 'Map a column to "' . $c['title'] . '".';
            }
        }
        if (in_array($type['key'], array('sales', 'purchases', 'importations', 'withholding', 'withheld_on_us', 'counterparties'), true)) {
            $hasName = isset($map['p_registered_name']) && $map['p_registered_name'] !== '' || isset($map['p_last_name']) && $map['p_last_name'] !== '';
            if (!$hasName) {
                $p[] = 'Map a registered name or last name column so each row has a counterparty name.';
            }
        }
        return $p;
    }

    // ------------------------------------------------------------------ staging
    /** Parse the whole file once and store raw rows. */
    public static function prepare($jobId)
    {
        $job = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($jobId));
        $rows = self::readRows($job);
        $map = json_decode($job['mapping_json'], true);
        DB::q('DELETE FROM import_rows WHERE job_id = ?', array($jobId));
        $batch = array();
        $count = 0;
        foreach ($rows as $rowNo => $cells) {
            if ($rowNo <= (int)$job['header_row']) {
                continue;
            }
            $raw = array();
            $numeric = array();
            $empty = true;
            foreach ($map as $target => $src) {
                if ($src === '' || $src === null) {
                    continue;
                }
                $cell = isset($cells[(int)$src]) ? $cells[(int)$src] : array('v' => '', 'num' => false, 'date' => null);
                $v = $cell['date'] ? $cell['date'] : $cell['v'];
                $raw[$target] = $v;
                if ($cell['num']) {
                    $numeric[] = $target;
                }
                if (trim((string)$v) !== '') {
                    $empty = false;
                }
            }
            if ($empty) {
                continue;
            }
            $raw['__numeric'] = $numeric;
            $batch[] = array($jobId, $rowNo, json_encode($raw));
            $count++;
            if (count($batch) >= 400) {
                self::flushRaw($batch);
                $batch = array();
            }
        }
        if ($batch) {
            self::flushRaw($batch);
        }
        DB::update('import_jobs', array('total_rows' => $count, 'processed_rows' => 0, 'valid_rows' => 0, 'error_rows' => 0, 'duplicate_rows' => 0,
            'status' => 'mapped', 'updated_at' => now()), 'id = ?', array($jobId));
        return $count;
    }

    private static function flushRaw($batch)
    {
        $sql = 'INSERT INTO import_rows (job_id, row_no, raw_json, status) VALUES ';
        $vals = array();
        $p = array();
        foreach ($batch as $b) {
            $vals[] = '(?, ?, ?, ?)';
            $p[] = $b[0];
            $p[] = $b[1];
            $p[] = $b[2];
            $p[] = 'pending';
        }
        DB::q($sql . implode(',', $vals), $p);
    }

    /** Validate the next chunk of pending rows. Returns progress array. */
    public static function stageChunk($jobId, $limit = 300)
    {
        $job = DB::row('SELECT * FROM import_jobs WHERE id = ?', array($jobId));
        $type = self::type($job['import_type']);
        $opts = json_decode((string)$job['options_json'], true) ?: array();
        $rows = DB::all('SELECT * FROM import_rows WHERE job_id = ? AND status = ? ORDER BY row_no LIMIT ' . (int)$limit, array($jobId, 'pending'));
        foreach ($rows as $r) {
            $raw = json_decode($r['raw_json'], true);
            list($norm, $errors, $warnings, $hash) = self::normalizeRow($type, $raw, $job, $opts);
            $status = $errors ? 'error' : 'valid';
            if (!$errors && $hash) {
                $inFile = DB::val('SELECT row_no FROM import_rows WHERE job_id = ? AND row_hash = ? AND id <> ? AND status IN (?, ?) LIMIT 1', array($jobId, $hash, $r['id'], 'valid', 'duplicate'));
                if ($inFile) {
                    $status = 'duplicate';
                    $warnings['dup'] = 'Same as row ' . $inFile . ' of this file';
                } elseif (self::existsInTarget($type, $hash, $job['client_id'])) {
                    $status = 'duplicate';
                    $warnings['dup'] = 'An identical record already exists';
                }
            }
            DB::update('import_rows', array('normalized_json' => json_encode($norm), 'errors_json' => $errors ? json_encode($errors) : null,
                'warnings_json' => $warnings ? json_encode($warnings) : null, 'status' => $status, 'row_hash' => $hash), 'id = ?', array($r['id']));
        }
        $counts = DB::row('SELECT SUM(status <> ?) AS processed, SUM(status = ?) AS valid, SUM(status = ?) AS errors, SUM(status = ?) AS dups FROM import_rows WHERE job_id = ?',
            array('pending', 'valid', 'error', 'duplicate', $jobId));
        $done = (int)$counts['processed'] >= (int)$job['total_rows'];
        DB::update('import_jobs', array('processed_rows' => (int)$counts['processed'], 'valid_rows' => (int)$counts['valid'], 'error_rows' => (int)$counts['errors'],
            'duplicate_rows' => (int)$counts['dups'], 'status' => $done ? 'staged' : 'mapped', 'updated_at' => now()), 'id = ?', array($jobId));
        return array('processed' => (int)$counts['processed'], 'total' => (int)$job['total_rows'], 'done' => $done,
            'valid' => (int)$counts['valid'], 'errors' => (int)$counts['errors'], 'duplicates' => (int)$counts['dups']);
    }

    private static function existsInTarget($type, $hash, $clientId)
    {
        if ($type['key'] === 'counterparties') {
            return false;
        }
        $table = $type['key'] === 'compensation' ? 'compensation_records' : Records::module($type['module'])['table'];
        return (bool)DB::val('SELECT id FROM ' . $table . ' WHERE client_id = ? AND row_hash = ? AND status = ? LIMIT 1', array($clientId, $hash, 'posted'));
    }

    /** Party columns to directory data. */
    private static function partyFrom($raw, &$errors, &$warnings)
    {
        $tinRaw = isset($raw['p_tin']) ? trim((string)$raw['p_tin']) : '';
        $numeric = in_array('p_tin', $raw['__numeric'], true);
        $p = array('tin' => null, 'branch_code' => '0000');
        if ($tinRaw !== '') {
            $t = Tin::parse($tinRaw, $numeric);
            if (!$t['ok']) {
                $errors['p_tin'] = $t['error'];
            } else {
                $p['tin'] = $t['tin'];
                if ($t['warning']) {
                    $warnings['p_tin'] = $t['warning'];
                }
                if ($t['branch'] !== null) {
                    $p['branch_code'] = $t['branch'];
                }
            }
        }
        if (isset($raw['p_branch']) && trim((string)$raw['p_branch']) !== '') {
            $b = Tin::branch($raw['p_branch']);
            if ($b === null) {
                $errors['p_branch'] = 'Branch code must have 4 or 5 digits';
            } else {
                $p['branch_code'] = $b;
            }
        }
        foreach (array('registered_name', 'last_name', 'first_name', 'middle_name', 'address1', 'address2') as $k) {
            $v = isset($raw['p_' . $k]) ? trim(preg_replace('/\s+/', ' ', (string)$raw['p_' . $k])) : '';
            $p[$k] = $v === '' ? null : $v;
        }
        if (isset($raw['p_zip'])) {
            $p['zip_code'] = trim((string)$raw['p_zip']) ?: null;
        }
        $p['entity_type'] = $p['registered_name'] ? 'non_individual' : 'individual';
        if (!$p['registered_name'] && (!$p['last_name'] || !$p['first_name'])) {
            $errors['p_registered_name'] = 'Each row needs a registered name, or a last and first name';
        }
        if ($p['entity_type'] === 'non_individual') {
            $p['last_name'] = $p['first_name'] = $p['middle_name'] = null;
        }
        if (!$p['tin']) {
            $warnings['p_tin'] = 'No TIN: the counterparty will be matched by name';
        }
        return $p;
    }

    public static function normalizeRow($type, $raw, $job, $opts)
    {
        $errors = array();
        $warnings = array();
        $clientId = (int)$job['client_id'];
        $dateFmt = isset($opts['date_format']) ? $opts['date_format'] : 'auto';
        $branchId = (int)$job['branch_id'];
        if (isset($raw['client_branch']) && trim((string)$raw['client_branch']) !== '') {
            $code = Tin::branch($raw['client_branch']);
            $bid = DB::val('SELECT id FROM branches WHERE client_id = ? AND branch_code = ?', array($clientId, $code));
            if (!$bid) {
                $errors['client_branch'] = 'Branch ' . $raw['client_branch'] . ' does not exist for this client';
            } else {
                $branchId = (int)$bid;
            }
        }
        if ($type['key'] === 'counterparties') {
            $p = self::partyFrom($raw, $errors, $warnings);
            $roles = strtoupper(isset($raw['roles']) ? (string)$raw['roles'] : '');
            foreach (array('supplier', 'customer', 'payee', 'payor') as $r) {
                $p['is_' . $r] = strpos($roles, strtoupper($r)) !== false ? 1 : 0;
            }
            $p['default_atc'] = isset($raw['default_atc']) && trim($raw['default_atc']) !== '' ? strtoupper(trim($raw['default_atc'])) : null;
            $hash = sha1('cp|' . $p['tin'] . '|' . $p['branch_code'] . '|' . $p['registered_name'] . '|' . $p['last_name'] . '|' . $p['first_name']);
            return array(array('party' => $p), $errors, $warnings, $hash);
        }
        if ($type['key'] === 'compensation') {
            $t = Tin::parse(isset($raw['e_tin']) ? $raw['e_tin'] : '', in_array('e_tin', $raw['__numeric'], true));
            if (!$t['ok']) {
                $errors['e_tin'] = $t['error'];
            }
            $emp = array('tin' => $t['ok'] ? $t['tin'] : null, 'tin_branch' => Tin::branch(isset($raw['e_branch']) && $raw['e_branch'] !== '' ? $raw['e_branch'] : ($t['branch'] ?: '0000')) ?: '0000',
                'employee_no' => isset($raw['e_no']) && trim($raw['e_no']) !== '' ? trim($raw['e_no']) : null,
                'last_name' => isset($raw['e_last']) ? trim($raw['e_last']) : '', 'first_name' => isset($raw['e_first']) ? trim($raw['e_first']) : '',
                'middle_name' => isset($raw['e_middle']) && trim($raw['e_middle']) !== '' ? trim($raw['e_middle']) : null);
            if ($emp['last_name'] === '' || $emp['first_name'] === '') {
                $errors['e_last'] = 'Employee last and first name are required';
            }
            $in = $raw;
            $in['branch_id'] = $branchId;
            $in['has_previous_employer'] = isset($raw['has_previous_employer']) && strtoupper(trim($raw['has_previous_employer'])) === 'Y' ? 1 : 0;
            list($d, $e2, $w2) = Payroll::compValidate($in, $clientId, array('skip_party' => true, 'date_format' => $dateFmt));
            $errors = array_merge($errors, $e2);
            $warnings = array_merge($warnings, $w2);
            if ($d['tax_year'] && Records::lockedPeriod($clientId, $d['tax_year'] . '-12', 'compensation')) {
                $errors['tax_year'] = 'Compensation for ' . $d['tax_year'] . ' is locked';
            }
            $hash = sha1('comp|' . $emp['tin'] . '|' . $d['tax_year'] . '|' . $d['schedule_code'] . '|' . $d['pres_gross'] . '|' . $d['tax_due']);
            if ($emp['tin'] && !$errors) {
                $eid = DB::val('SELECT id FROM employees WHERE client_id = ? AND tin = ? AND deleted_at IS NULL', array($clientId, $emp['tin']));
                if ($eid) {
                    $existing = DB::row('SELECT id, row_hash FROM compensation_records WHERE client_id = ? AND employee_id = ? AND tax_year = ? AND schedule_code = ? AND status = ?',
                        array($clientId, $eid, $d['tax_year'], $d['schedule_code'], 'posted'));
                    if ($existing && $existing['row_hash'] !== sha1('comp|' . $eid . '|' . $d['tax_year'] . '|' . $d['schedule_code'] . '|' . $d['pres_gross'] . '|' . $d['tax_due'])) {
                        $errors['e_tin'] = 'This employee already has a ' . $d['tax_year'] . ' ' . $d['schedule_code'] . ' record with different amounts. Edit or void it first.';
                    } elseif ($existing) {
                        $hash = $existing['row_hash'];
                    }
                }
            }
            return array(array('employee' => $emp, 'record' => $d), $errors, $warnings, $hash);
        }
        $mod = Records::module($type['module']);
        $p = self::partyFrom($raw, $errors, $warnings);
        $in = $raw;
        $in['branch_id'] = $branchId;
        if ($type['key'] === 'withholding') {
            $in['tax_kind'] = isset($raw['tax_kind']) && trim($raw['tax_kind']) !== '' ? strtoupper(trim($raw['tax_kind'])) : 'EWT';
            $in['schedule_tag'] = isset($raw['schedule_tag']) && trim($raw['schedule_tag']) !== '' ? strtoupper(trim($raw['schedule_tag'])) : 'A';
        }
        list($d, $e2, $w2) = Records::validate($mod, $in, $clientId, array('skip_party' => true, 'skip_scope' => true, 'date_format' => $dateFmt, 'party_entity_type' => $p['entity_type']));
        $errors = array_merge($errors, $e2);
        $warnings = array_merge($warnings, $w2);
        if (!empty($d['period_month']) && Records::lockedPeriod($clientId, $d['period_month'], $mod['lock_scope'])) {
            $errors['period'] = 'Period ' . month_label($d['period_month']) . ' is locked';
        }
        $hashData = $d;
        $hashData['counterparty_id'] = Records::partyKey($p['tin'], $p['branch_code'], $p['registered_name'], $p['last_name'], $p['first_name']);
        $mod['key'] = $type['module'];
        $hash = Records::rowHash($mod, $hashData);
        return array(array('party' => $p, 'record' => $d), $errors, $warnings, $hash);
    }

    // ------------------------------------------------------------------ commit / rollback
    public static function commit($jobId, $mode, $replacePeriods = false, $reason = null)
    {
        Ctx::requireActive();
        $job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array($jobId, Ctx::clientId()));
        if (!$job || $job['status'] !== 'staged') {
            throw new RuntimeException('This import is not ready to commit.');
        }
        if ($mode === 'all' && (int)$job['error_rows'] > 0) {
            throw new RuntimeException('There are ' . $job['error_rows'] . ' rows with errors. Fix them, or choose to commit valid rows only.');
        }
        $type = self::type($job['import_type']);
        return DB::tx(function () use ($job, $type, $replacePeriods, $reason) {
            $clientId = (int)$job['client_id'];
            $rows = DB::all('SELECT * FROM import_rows WHERE job_id = ? AND status = ? ORDER BY row_no', array($job['id'], 'valid'));
            $mod = $type['module'] ? Records::module($type['module']) : null;
            if ($replacePeriods && $mod) {
                $months = array();
                foreach ($rows as $r) {
                    $n = json_decode($r['normalized_json'], true);
                    $months[$n['record']['period_month']] = true;
                }
                foreach (array_keys($months) as $m) {
                    $extra = '';
                    $params = array('void', 'Replaced by import #' . $job['id'] . ($reason ? ': ' . $reason : ''), now(), Auth::id(), $clientId, $m, 'posted', 'import');
                    if ($mod['table'] === 'withholding_entries') {
                        $extra = ' AND direction = ?';
                        $params[] = $mod['fixed']['direction'];
                    }
                    DB::q('UPDATE ' . $mod['table'] . ' SET status = ?, void_reason = ?, voided_at = ?, updated_by = ? WHERE client_id = ? AND period_month = ? AND status = ? AND source = ?' . $extra, $params);
                }
                Audit::log('import.replace', 'import_jobs', $job['id'], 'Voided prior imported records for ' . implode(', ', array_keys($months)), null, null, $reason);
            }
            $n = 0;
            foreach ($rows as $r) {
                $norm = json_decode($r['normalized_json'], true);
                if ($type['key'] === 'counterparties') {
                    $id = Records::resolveCounterparty($clientId, $norm['party'], 'supplier', 'import', $job['id']);
                    $flags = array();
                    foreach (array('is_supplier', 'is_customer', 'is_payee', 'is_payor') as $f) {
                        if (!empty($norm['party'][$f])) {
                            $flags[$f] = 1;
                        }
                    }
                    if ($flags) {
                        DB::update('counterparties', $flags, 'id = ?', array($id));
                    }
                } elseif ($type['key'] === 'compensation') {
                    $e = $norm['employee'];
                    $emp = $e['tin'] ? DB::row('SELECT id FROM employees WHERE client_id = ? AND tin = ? AND deleted_at IS NULL', array($clientId, $e['tin'])) : null;
                    $empId = $emp ? (int)$emp['id'] : DB::insert('employees', array_merge($e, array('organization_id' => Ctx::orgId(), 'client_id' => $clientId,
                        'branch_id' => $norm['record']['branch_id'], 'region_code' => $norm['record']['region_code'], 'nationality' => $norm['record']['nationality'],
                        'employment_status' => $norm['record']['employment_status'], 'is_mwe' => $norm['record']['schedule_code'] === 'S2' ? 1 : 0,
                        'status' => 'active', 'source' => 'import', 'created_by' => Auth::id(), 'created_at' => now())));
                    $rec = $norm['record'];
                    $rec['employee_id'] = $empId;
                    $id = Payroll::compSave($rec, null, null, 'import', $job['id']);
                } else {
                    $cpId = Records::resolveCounterparty($clientId, $norm['party'], $type['role'], 'import', $job['id']);
                    $rec = $norm['record'];
                    $rec['counterparty_id'] = $cpId;
                    $id = Records::save($mod, $rec, null, null, 'import', $job['id']);
                }
                DB::update('import_rows', array('status' => 'committed', 'target_id' => $id), 'id = ?', array($r['id']));
                $n++;
            }
            DB::update('import_jobs', array('status' => 'committed', 'committed_rows' => $n, 'committed_by' => Auth::id(), 'committed_at' => now(), 'updated_at' => now()), 'id = ?', array($job['id']));
            Audit::log('import.commit', 'import_jobs', $job['id'], 'Committed ' . $n . ' rows from ' . $job['original_filename'], null, array('rows' => $n, 'type' => $job['import_type']));
            return $n;
        });
    }

    public static function rollback($jobId, $reason)
    {
        $job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array($jobId, Ctx::clientId()));
        if (!$job || $job['status'] !== 'committed') {
            throw new RuntimeException('Only committed imports can be rolled back.');
        }
        $type = self::type($job['import_type']);
        if ($type['key'] === 'counterparties') {
            throw new RuntimeException('Directory imports cannot be rolled back automatically because other records may now use those entries.');
        }
        $table = $type['key'] === 'compensation' ? 'compensation_records' : Records::module($type['module'])['table'];
        $scope = $type['key'] === 'compensation' ? 'compensation' : Records::module($type['module'])['lock_scope'];
        return DB::tx(function () use ($job, $table, $scope, $reason) {
            $months = DB::col('SELECT DISTINCT ' . ($table === 'compensation_records' ? "CONCAT(tax_year, '-12')" : 'period_month') . ' FROM ' . $table . ' WHERE import_job_id = ? AND status = ?', array($job['id'], 'posted'));
            foreach ($months as $m) {
                if (Records::lockedPeriod($job['client_id'], $m, $scope)) {
                    throw new RuntimeException('Period ' . month_label($m) . ' is locked; unlock it before rolling back.');
                }
            }
            $n = DB::q('UPDATE ' . $table . ' SET status = ?, void_reason = ?, voided_at = ?, updated_by = ? WHERE import_job_id = ? AND status = ?',
                array('void', 'Import #' . $job['id'] . ' rolled back: ' . $reason, now(), Auth::id(), $job['id'], 'posted'))->rowCount();
            DB::update('import_jobs', array('status' => 'rolled_back', 'rolled_back_by' => Auth::id(), 'rolled_back_at' => now(), 'message' => mb_substr($reason, 0, 255)), 'id = ?', array($job['id']));
            Audit::log('import.rollback', 'import_jobs', $job['id'], 'Rolled back ' . $n . ' records', null, null, $reason);
            return $n;
        });
    }

    /** Workbook of rejected rows with an ERRORS column, for correction and re-upload. */
    public static function correctionsWorkbook($jobId)
    {
        $job = DB::row('SELECT * FROM import_jobs WHERE id = ? AND client_id = ?', array($jobId, Ctx::clientId()));
        $type = self::type($job['import_type']);
        $cols = $type['columns'];
        $cols[] = array('title' => 'ERRORS', 'format' => 'note', 'width' => 60, 'key' => '__errors');
        $cols[] = array('title' => 'SOURCE ROW', 'format' => 'text', 'width' => 10, 'key' => '__row');
        $out = array();
        foreach (DB::all('SELECT * FROM import_rows WHERE job_id = ? AND status = ? ORDER BY row_no', array($jobId, 'error')) as $r) {
            $raw = json_decode($r['raw_json'], true);
            $errs = json_decode((string)$r['errors_json'], true) ?: array();
            $row = array();
            foreach ($type['columns'] as $c) {
                $row[] = isset($raw[$c['key']]) ? (string)$raw[$c['key']] : '';
            }
            $row[] = implode('; ', $errs);
            $row[] = (string)$r['row_no'];
            $out[] = $row;
        }
        return XlsxWriter::build(array(array('name' => 'Data', 'columns' => $cols, 'rows' => $out, 'freeze' => true)), array('title' => 'Corrections'));
    }
}
