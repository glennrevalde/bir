<?php
/**
 * Seed definitions for the DAT/CSV layout registry (layout_specs, spec_records, spec_fields).
 *
 * These rows are DATA, not code: after install, every layout can be corrected in
 * Reference data > File layouts without touching PHP. Each field carries its own
 * confidence so reviewers can see exactly which bytes rest on what evidence.
 *
 * Confidence vocabulary
 *   official   - quoted from a BIR annex table (RMC 25-2024 Annex A/B via a verbatim reproduction)
 *   validated  - matches a sample file that the official v7.4 validator reported "No Errors Encountered"
 *   medium     - two or more independent secondary sources agree
 *   low        - one secondary source
 *   analog     - no source found; mirrors a sibling layout so the module can be exercised (DRAFT ONLY)
 *
 * Status vocabulary
 *   draft                - not sourced; files are always labelled UNVALIDATED DRAFT
 *   pending_verification - sourced but not yet proven with this installation's validator evidence
 *   approved             - a reviewer promoted it after official validator success
 *   retired              - kept for history and re-generation of old periods
 *
 * Field source expressions
 *   lit:X            literal text X                 tpl:H{sawt_form}  template using context values
 *   agent.KEY        filer (withholding agent / taxpayer) value
 *   row.KEY          detail row value               sum.KEY           total of a detail column
 *   period.KEY       mm_yyyy | mm_dd_yyyy_end | yyyy | mm
 *   seq              running row number             blank             empty field
 *
 * Data types: text (fmt name|addr), code, digits (zero-padded to max_len), amount (fmt 2dp|trim),
 *             rate (fmt pct2|frac2), date (MM/DD/YYYY), int, raw
 */

function gsb_f($key, $label, $src, $type = 'text', $max = null, $req = 0, $quote = 'never', $fmt = null, $conf = 'low', $notes = null)
{
    return array('field_key' => $key, 'label' => $label, 'source_expr' => $src, 'data_type' => $type,
        'max_len' => $max, 'required' => $req, 'quote_mode' => $quote, 'fmt' => $fmt,
        'confidence' => $conf, 'notes' => $notes);
}

/** Withholding-style detail used by QAP / MAP style records (HQAP shape). */
function gsb_qap_detail($recCode, $formLit, $conf, $rateNote)
{
    return array(
        gsb_f('rec_type', 'Alphalist type code', 'lit:' . $recCode, 'raw', 2, 1, 'never', null, $conf),
        gsb_f('form_type', 'Form type code', 'lit:' . $formLit, 'raw', 7, 1, 'never', null, $conf),
        gsb_f('seq_no', 'Sequence number', 'seq', 'int', 8, 1, 'never', null, $conf),
        gsb_f('payee_tin', 'Payee TIN', 'row.tin', 'digits', 9, 1, 'never', null, $conf),
        gsb_f('payee_branch', 'Payee branch code', 'row.branch', 'digits', 4, 1, 'never', null, $conf),
        gsb_f('registered_name', 'Registered name (non-individual)', 'row.registered_name', 'text', 50, 0, 'nonempty', 'name', $conf),
        gsb_f('last_name', 'Last name (individual)', 'row.last_name', 'text', 30, 0, 'nonempty', 'name', $conf),
        gsb_f('first_name', 'First name (individual)', 'row.first_name', 'text', 30, 0, 'nonempty', 'name', $conf),
        gsb_f('middle_name', 'Middle name (individual)', 'row.middle_name', 'text', 30, 0, 'nonempty', 'name', $conf),
        gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, $conf),
        gsb_f('atc', 'ATC', 'row.atc', 'code', 5, 1, 'never', null, $conf),
        gsb_f('tax_rate', 'Tax rate', 'row.rate', 'rate', 6, 1, 'never', 'pct2', 'low', $rateNote),
        gsb_f('income_payment', 'Amount of income payment', 'row.income', 'amount', 15, 1, 'never', '2dp', $conf),
        gsb_f('tax_withheld', 'Amount of tax withheld', 'row.tax', 'amount', 15, 1, 'never', '2dp', $conf),
    );
}

function gsb_relief_agent_head($code, $conf)
{
    return array(
        gsb_f('rec_type', 'Record type', 'lit:H', 'raw', 1, 1, 'never', null, 'medium'),
        gsb_f('trans_type', 'Transaction type', 'lit:' . $code, 'raw', 1, 1, 'never', null, 'medium'),
        gsb_f('tin', 'Taxpayer TIN', 'agent.tin', 'digits', 9, 1, 'always', null, $conf, 'Quoted in the RELIEF v2.3 generator; unquoted in another source.'),
        gsb_f('registered_name', 'Registered name', 'agent.registered_name', 'text', 50, 0, 'always', 'name', $conf),
        gsb_f('last_name', 'Last name', 'agent.last_name', 'text', 30, 0, 'always', 'name', $conf),
        gsb_f('first_name', 'First name', 'agent.first_name', 'text', 30, 0, 'always', 'name', $conf),
        gsb_f('middle_name', 'Middle name', 'agent.middle_name', 'text', 30, 0, 'always', 'name', $conf),
        gsb_f('trade_name', 'Trade name', 'agent.trade_name', 'text', 50, 0, 'always', 'name', $conf),
        gsb_f('address1', 'Address line 1 (substreet/street/barangay)', 'agent.address1', 'text', null, 0, 'always', 'addr', $conf, 'Maximum length not confirmed.'),
        gsb_f('address2', 'Address line 2 (district/town/city)', 'agent.address2', 'text', null, 0, 'always', 'addr', $conf, 'Maximum length not confirmed.'),
    );
}

function gsb_layouts()
{
    $L = array();

    // ------------------------------------------------------------------ RELIEF: Sales
    $L[] = array(
        'form_code' => 'SLS', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'RELIEF SLSP (v2.x observed)',
        'validator_version' => 'RELIEF', 'effective_from' => '2012-01-01', 'status' => 'pending_verification', 'confidence' => 'medium',
        'filename_pattern' => '{TIN}S{MM}{YYYY}.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => "Field order agreed by two independent generators: github.com/cpa-coder/vat-relief (RELIEF v2.3) and github.com/AlmyrCPA/Haki-Bookkeeping-System. Quoting and amount formatting differ between them; this layout follows the RELIEF v2.3 generator. Confirm against the RELIEF validation module before approval.",
        'notes' => 'RELIEF files carry no branch field: one file per TIN per month covering all branches. Detail rows are consolidated per customer TIN.',
        'records' => array(
            array('record_code' => 'H', 'role' => 'header', 'label' => 'Header (filer and totals)', 'fields' => array_merge(
                gsb_relief_agent_head('S', 'medium'),
                array(
                    gsb_f('exempt', 'Total exempt sales', 'sum.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('zero_rated', 'Total zero-rated sales', 'sum.zero_rated', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('taxable', 'Total taxable sales (net of VAT)', 'sum.taxable', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('output_vat', 'Total output VAT', 'sum.output_vat', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('rdo', 'RDO code', 'agent.rdo', 'digits', 3, 1, 'never', null, 'medium'),
                    gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'medium'),
                    gsb_f('fiscal_month_end', 'Fiscal year ending month', 'agent.fiscal_month_end', 'int', 2, 1, 'never', null, 'medium'),
                ))),
            array('record_code' => 'D', 'role' => 'detail', 'label' => 'Detail (per customer)', 'fields' => array(
                gsb_f('rec_type', 'Record type', 'lit:D', 'raw', 1, 1, 'never', null, 'medium'),
                gsb_f('trans_type', 'Transaction type', 'lit:S', 'raw', 1, 1, 'never', null, 'medium'),
                gsb_f('tin', 'Customer TIN', 'row.tin', 'digits', 9, 0, 'always', null, 'medium'),
                gsb_f('registered_name', 'Registered name', 'row.registered_name', 'text', 50, 0, 'always', 'name', 'medium'),
                gsb_f('last_name', 'Last name', 'row.last_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('first_name', 'First name', 'row.first_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('address1', 'Address line 1', 'row.address1', 'text', null, 0, 'always', 'addr', 'medium'),
                gsb_f('address2', 'Address line 2', 'row.address2', 'text', null, 0, 'always', 'addr', 'medium'),
                gsb_f('exempt', 'Exempt sales', 'row.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('zero_rated', 'Zero-rated sales', 'row.zero_rated', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('taxable', 'Taxable sales', 'row.taxable', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('output_vat', 'Output VAT', 'row.output_vat', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('owner_tin', 'Owner TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'medium'),
                gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'medium'),
            )),
        ),
    );

    // ------------------------------------------------------------------ RELIEF: Purchases
    $L[] = array(
        'form_code' => 'SLP', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'RELIEF SLSP (v2.x observed)',
        'validator_version' => 'RELIEF', 'effective_from' => '2012-01-01', 'status' => 'pending_verification', 'confidence' => 'medium',
        'filename_pattern' => '{TIN}P{MM}{YYYY}.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Same sources as Sales. Creditable input tax = input tax less non-creditable input tax (RELIEF v2.3 generator).',
        'notes' => 'Detail rows are consolidated per supplier TIN.',
        'records' => array(
            array('record_code' => 'H', 'role' => 'header', 'label' => 'Header (filer and totals)', 'fields' => array_merge(
                gsb_relief_agent_head('P', 'medium'),
                array(
                    gsb_f('exempt', 'Total exempt purchases', 'sum.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('zero_rated', 'Total zero-rated purchases', 'sum.zero_rated', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('services', 'Total purchase of services', 'sum.services', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('capital_goods', 'Total purchase of capital goods', 'sum.capital_goods', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('other_goods', 'Total goods other than capital goods', 'sum.other_goods', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('input_tax', 'Total input tax', 'sum.input_tax', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('creditable_input', 'Creditable input tax', 'sum.creditable_input', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('non_creditable', 'Non-creditable input tax', 'sum.non_creditable', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('rdo', 'RDO code', 'agent.rdo', 'digits', 3, 1, 'never', null, 'medium'),
                    gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'medium'),
                    gsb_f('fiscal_month_end', 'Fiscal year ending month', 'agent.fiscal_month_end', 'int', 2, 1, 'never', null, 'medium'),
                ))),
            array('record_code' => 'D', 'role' => 'detail', 'label' => 'Detail (per supplier)', 'fields' => array(
                gsb_f('rec_type', 'Record type', 'lit:D', 'raw', 1, 1, 'never', null, 'medium'),
                gsb_f('trans_type', 'Transaction type', 'lit:P', 'raw', 1, 1, 'never', null, 'medium'),
                gsb_f('tin', 'Supplier TIN', 'row.tin', 'digits', 9, 0, 'always', null, 'medium'),
                gsb_f('registered_name', 'Registered name', 'row.registered_name', 'text', 50, 0, 'always', 'name', 'medium'),
                gsb_f('last_name', 'Last name', 'row.last_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('first_name', 'First name', 'row.first_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 30, 0, 'always', 'name', 'medium'),
                gsb_f('address1', 'Address line 1', 'row.address1', 'text', null, 0, 'always', 'addr', 'medium'),
                gsb_f('address2', 'Address line 2', 'row.address2', 'text', null, 0, 'always', 'addr', 'medium'),
                gsb_f('exempt', 'Exempt purchases', 'row.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('zero_rated', 'Zero-rated purchases', 'row.zero_rated', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('services', 'Purchase of services', 'row.services', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('capital_goods', 'Purchase of capital goods', 'row.capital_goods', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('other_goods', 'Goods other than capital goods', 'row.other_goods', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('input_tax', 'Input tax', 'row.input_tax', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('owner_tin', 'Owner TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'medium'),
                gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'medium'),
            )),
        ),
    );

    // ------------------------------------------------------------------ RELIEF: Importations
    $L[] = array(
        'form_code' => 'SLI', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'RELIEF SLSP (single source)',
        'validator_version' => 'RELIEF', 'effective_from' => '2012-01-01', 'status' => 'pending_verification', 'confidence' => 'low',
        'filename_pattern' => '{TIN}I{MM}{YYYY}.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Single secondary source (github.com/AlmyrCPA/Haki-Bookkeeping-System). Confirm with the RELIEF module before approval.',
        'notes' => 'One detail row per import entry.',
        'records' => array(
            array('record_code' => 'H', 'role' => 'header', 'label' => 'Header (filer and totals)', 'fields' => array_merge(
                gsb_relief_agent_head('I', 'low'),
                array(
                    gsb_f('landed_cost', 'Total landed cost', 'sum.landed_cost', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('other_charges', 'Total other charges', 'sum.other_charges', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('exempt', 'Total exempt importations', 'sum.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('taxable', 'Total taxable importations', 'sum.taxable', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('vat', 'Total VAT paid', 'sum.vat', 'amount', 15, 1, 'never', '2dp', 'low'),
                    gsb_f('rdo', 'RDO code', 'agent.rdo', 'digits', 3, 1, 'never', null, 'low'),
                    gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'low'),
                    gsb_f('fiscal_month_end', 'Fiscal year ending month', 'agent.fiscal_month_end', 'int', 2, 1, 'never', null, 'low'),
                ))),
            array('record_code' => 'D', 'role' => 'detail', 'label' => 'Detail (per import entry)', 'fields' => array(
                gsb_f('rec_type', 'Record type', 'lit:D', 'raw', 1, 1, 'never', null, 'low'),
                gsb_f('trans_type', 'Transaction type', 'lit:I', 'raw', 1, 1, 'never', null, 'low'),
                gsb_f('import_entry_no', 'Import entry no.', 'row.import_entry_no', 'text', 30, 1, 'always', 'addr', 'low'),
                gsb_f('assessment_date', 'Assessment/release date', 'row.assessment_date', 'date', 10, 0, 'never', null, 'low'),
                gsb_f('supplier_name', 'Name of seller', 'row.supplier_name', 'text', 50, 1, 'always', 'name', 'low'),
                gsb_f('importation_date', 'Date of importation', 'row.importation_date', 'date', 10, 1, 'never', null, 'low'),
                gsb_f('country', 'Country of origin', 'row.country', 'text', 30, 0, 'always', 'name', 'low'),
                gsb_f('landed_cost', 'Total landed cost', 'row.landed_cost', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('other_charges', 'Other charges', 'row.other_charges', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('exempt', 'Exempt', 'row.exempt', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('taxable', 'Taxable', 'row.taxable', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('vat', 'VAT paid', 'row.vat', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('or_no', 'OR number', 'row.or_no', 'text', 30, 0, 'always', 'addr', 'low'),
                gsb_f('vat_payment_date', 'Date of VAT payment', 'row.vat_payment_date', 'date', 10, 0, 'never', null, 'low'),
                gsb_f('owner_tin', 'Owner TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'low'),
                gsb_f('taxable_month', 'Taxable month (last day)', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'low'),
            )),
        ),
    );

    $rateNote = 'Sources disagree: percent with two decimals (1.00) vs fraction (0.01). Switch fmt to frac2 if the validator rejects pct2.';

    // ------------------------------------------------------------------ QAP 1601-EQ Schedule 1
    $L[] = array(
        'form_code' => '1601EQ', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'Alphalist v7.x QAP',
        'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'pending_verification', 'confidence' => 'medium',
        'filename_pattern' => '{TIN}{BRANCH}{MM}{YYYY}1601EQ.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Header shape mirrors the official MAP header (RMC 25-2024 Annex A, reproduced verbatim by Forvis Mazars). Detail/control order from one secondary generator. BIR job aid (RMC 7-2021 Annex C): QAP produces 3 files per quarter, one per month. Filename follows Annex B pattern TIN(9)+BC(4)+MMYYYY+form.',
        'notes' => 'Generate one file per month of the quarter.',
        'records' => array(
            array('record_code' => 'HQAP', 'role' => 'header', 'label' => 'Header', 'fields' => array(
                gsb_f('alpha_type', 'Alphalist type code', 'lit:HQAP', 'raw', 4, 1, 'never', null, 'medium'),
                gsb_f('form_type', 'Form type code', 'lit:H1601EQ', 'raw', 7, 1, 'never', null, 'medium'),
                gsb_f('tin_wa', 'TIN of withholding agent', 'agent.tin', 'digits', 9, 1, 'never', null, 'official'),
                gsb_f('branch_wa', 'Branch code of withholding agent', 'agent.branch', 'digits', 4, 1, 'never', null, 'official'),
                gsb_f('registered_name', 'Registered name', 'agent.display_name', 'text', 50, 1, 'always', 'name', 'medium'),
                gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, 'medium'),
                gsb_f('rdo', 'RDO code of withholding agent', 'agent.rdo', 'digits', 3, 1, 'never', null, 'medium'),
            )),
            array('record_code' => 'D1', 'role' => 'detail', 'label' => 'Detail (payee and ATC)', 'fields' => gsb_qap_detail('D1', '1601EQ', 'low', $rateNote)),
            array('record_code' => 'C1', 'role' => 'control', 'label' => 'Control totals', 'fields' => array(
                gsb_f('rec_type', 'Control code', 'lit:C1', 'raw', 2, 1, 'never', null, 'low'),
                gsb_f('form_type', 'Form type code', 'lit:1601EQ', 'raw', 6, 1, 'never', null, 'low'),
                gsb_f('tin_wa', 'TIN of withholding agent', 'agent.tin', 'digits', 9, 1, 'never', null, 'low'),
                gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'low'),
                gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, 'low'),
                gsb_f('total_income', 'Total income payment', 'sum.income', 'amount', 15, 1, 'never', '2dp', 'low'),
                gsb_f('total_tax', 'Total tax withheld', 'sum.tax', 'amount', 15, 1, 'never', '2dp', 'low'),
            )),
        ),
    );

    // ------------------------------------------------------------------ Draft analog layouts (no source found)
    $analogs = array(
        array('1601EQ', 'S2', 'HQAP', 'H1601EQ', 'D2', '1601EQ', 'C2', '{TIN}{BRANCH}{MM}{YYYY}1601EQ.DAT', 'period.mm_yyyy'),
        array('1601FQ', 'S1', 'HQAP', 'H1601FQ', 'D1', '1601FQ', 'C1', '{TIN}{BRANCH}{MM}{YYYY}1601FQ.DAT', 'period.mm_yyyy'),
        array('1601FQ', 'S2', 'HQAP', 'H1601FQ', 'D2', '1601FQ', 'C2', '{TIN}{BRANCH}{MM}{YYYY}1601FQ.DAT', 'period.mm_yyyy'),
        array('1601FQ', 'S3', 'HQAP', 'H1601FQ', 'D3', '1601FQ', 'C3', '{TIN}{BRANCH}{MM}{YYYY}1601FQ.DAT', 'period.mm_yyyy'),
    );
    foreach ($analogs as $a) {
        $L[] = array(
            'form_code' => $a[0], 'schedule_code' => $a[1], 'variant_key' => null, 'version_label' => 'Analog draft (unsourced)',
            'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'draft', 'confidence' => 'analog',
            'filename_pattern' => $a[7], 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
            'encoding' => 'Windows-1252', 'column_header_row' => 0,
            'source_ref' => 'No published field table was obtainable at build time (RMC 25-2024 / RMC 15-2025 Annex A are image-only PDFs). Mirrors the 1601-EQ layout so the workflow can be exercised. Replace every field from Annex A before approval.',
            'notes' => 'Research pending. Files from this layout are always labelled UNVALIDATED DRAFT.',
            'records' => array(
                array('record_code' => $a[2], 'role' => 'header', 'label' => 'Header', 'fields' => array(
                    gsb_f('alpha_type', 'Alphalist type code', 'lit:' . $a[2], 'raw', 4, 1, 'never', null, 'analog'),
                    gsb_f('form_type', 'Form type code', 'lit:' . $a[3], 'raw', 7, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'TIN of withholding agent', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('registered_name', 'Registered name', 'agent.display_name', 'text', 50, 1, 'always', 'name', 'analog'),
                    gsb_f('return_period', 'Return period', $a[8], 'raw', 7, 1, 'never', null, 'analog'),
                    gsb_f('rdo', 'RDO code', 'agent.rdo', 'digits', 3, 1, 'never', null, 'analog'),
                )),
                array('record_code' => $a[4], 'role' => 'detail', 'label' => 'Detail', 'fields' => gsb_qap_detail($a[4], $a[5], 'analog', $rateNote)),
                array('record_code' => $a[6], 'role' => 'control', 'label' => 'Control totals', 'fields' => array(
                    gsb_f('rec_type', 'Control code', 'lit:' . $a[6], 'raw', 2, 1, 'never', null, 'analog'),
                    gsb_f('form_type', 'Form type code', 'lit:' . $a[5], 'raw', 6, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'TIN of withholding agent', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('return_period', 'Return period', $a[8], 'raw', 7, 1, 'never', null, 'analog'),
                    gsb_f('total_income', 'Total income payment', 'sum.income', 'amount', 15, 1, 'never', '2dp', 'analog'),
                    gsb_f('total_tax', 'Total tax withheld', 'sum.tax', 'amount', 15, 1, 'never', '2dp', 'analog'),
                )),
            ),
        );
    }

    // ------------------------------------------------------------------ MAP 1600-VT / 1600-PT (header is official)
    foreach (array('1600VT', '1600PT') as $mapForm) {
        $L[] = array(
            'form_code' => $mapForm, 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'Alphalist v7.x MAP',
            'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'draft', 'confidence' => 'analog',
            'filename_pattern' => '{TIN}{BRANCH}{MM}{YYYY}' . $mapForm . '.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
            'encoding' => 'Windows-1252', 'column_header_row' => 0,
            'source_ref' => 'Header record and filename: RMC 25-2024 Annex A/B (verbatim reproduction by Forvis Mazars) - official. Detail and control records: not found, mirror the QAP layout (analog).',
            'notes' => 'Header is official; detail and control are drafts. Annex B misprints 1600VT as 1600VY.',
            'records' => array(
                array('record_code' => 'HMAP', 'role' => 'header', 'label' => 'Header (official)', 'fields' => array(
                    gsb_f('alpha_type', 'ALPHA_TYPE', 'lit:HMAP', 'raw', 5, 1, 'never', null, 'official'),
                    gsb_f('form_type', 'F_TYPE', 'lit:H' . $mapForm, 'raw', 7, 1, 'never', null, 'official'),
                    gsb_f('tin_wa', 'TIN_WA', 'agent.tin', 'digits', 9, 1, 'never', null, 'official'),
                    gsb_f('branch_wa', 'BRANCH CODE_WA', 'agent.branch', 'digits', 4, 1, 'never', null, 'official'),
                    gsb_f('registered_name', 'REGISTERED_NAME', 'agent.display_name', 'text', 50, 1, 'always', 'name', 'official', 'Quoting not stated in the annex table.'),
                    gsb_f('return_period', 'RETRN_PERIOD', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, 'official'),
                    gsb_f('rdo', 'RDO_CODE_WA', 'agent.rdo', 'digits', 3, 1, 'never', null, 'official'),
                )),
                array('record_code' => 'D1', 'role' => 'detail', 'label' => 'Detail (draft)', 'fields' => gsb_qap_detail('D1', $mapForm, 'analog', $rateNote)),
                array('record_code' => 'C1', 'role' => 'control', 'label' => 'Control totals (draft)', 'fields' => array(
                    gsb_f('rec_type', 'Control code', 'lit:C1', 'raw', 2, 1, 'never', null, 'analog'),
                    gsb_f('form_type', 'Form type code', 'lit:' . $mapForm, 'raw', 6, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'TIN of withholding agent', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, 'analog'),
                    gsb_f('total_income', 'Total income payment', 'sum.income', 'amount', 15, 1, 'never', '2dp', 'analog'),
                    gsb_f('total_tax', 'Total tax withheld', 'sum.tax', 'amount', 15, 1, 'never', '2dp', 'analog'),
                )),
            ),
        );
    }

    // ------------------------------------------------------------------ 1604-C Schedule 1 (validated sample)
    $amt = function ($key, $label) {
        return gsb_f($key, $label, 'row.' . $key, 'amount', 15, 1, 'never', '2dp', 'validated');
    };
    $sum = function ($key, $label) {
        return gsb_f($key, $label, 'sum.' . $key, 'amount', 17, 1, 'never', '2dp', 'validated');
    };
    $prev = array('prev_gross' => 'Previous employer: gross compensation', 'prev_nt_smw' => 'Previous: non-taxable SMW/basic', 'prev_nt_13th' => 'Previous: non-taxable 13th month and other benefits',
        'prev_nt_deminimis' => 'Previous: de minimis benefits', 'prev_nt_contrib' => 'Previous: SSS/GSIS/PHIC/HDMF and union dues', 'prev_nt_other' => 'Previous: salaries and other compensation (non-taxable)',
        'prev_nt_total' => 'Previous: total non-taxable', 'prev_tx_basic' => 'Previous: taxable basic salary', 'prev_tx_13th' => 'Previous: taxable 13th month and other benefits',
        'prev_tx_other' => 'Previous: taxable salaries and other compensation', 'prev_tx_total' => 'Previous: total taxable');
    $pres = array('pres_gross' => 'Present employer: gross compensation', 'pres_nt_smw' => 'Present: non-taxable SMW/basic', 'pres_nt_13th' => 'Present: non-taxable 13th month and other benefits',
        'pres_nt_deminimis' => 'Present: de minimis benefits', 'pres_nt_contrib' => 'Present: SSS/GSIS/PHIC/HDMF and union dues', 'pres_nt_other' => 'Present: salaries and other compensation (non-taxable)',
        'pres_nt_total' => 'Present: total non-taxable', 'pres_tx_basic' => 'Present: taxable basic salary', 'pres_tx_13th' => 'Present: taxable 13th month and other benefits',
        'pres_tx_other' => 'Present: taxable salaries and other compensation', 'pres_tx_total' => 'Present: total taxable');
    $tail = array('taxable_comp_total' => 'Taxable compensation, present and previous', 'net_taxable_comp' => 'Net taxable compensation',
        'tax_due' => 'Tax due (Jan to Dec)', 'prev_tax_withheld' => 'Tax withheld, previous employer', 'pres_withheld_jan_nov' => 'Tax withheld, present employer (Jan to Nov)',
        'pres_withheld_dec' => 'Amount withheld and paid in December', 'over_withheld_refunded' => 'Over-withheld tax refunded', 'tax_withheld_adjusted' => 'Tax withheld as adjusted');

    $d1 = array(
        gsb_f('rec_type', 'Schedule code', 'lit:D1', 'raw', 2, 1, 'never', null, 'validated'),
        gsb_f('form_type', 'Form type', 'lit:1604C', 'raw', 5, 1, 'never', null, 'validated'),
        gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'validated'),
        gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'validated'),
        gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'validated'),
        gsb_f('seq_no', 'Sequence number', 'seq', 'int', 8, 1, 'never', null, 'validated', 'Not zero-padded in the validated sample.'),
        gsb_f('emp_tin', 'Employee TIN', 'row.tin', 'digits', 9, 1, 'never', null, 'validated'),
        gsb_f('emp_branch', 'Employee TIN branch', 'row.tin_branch', 'digits', 4, 1, 'never', null, 'validated'),
        gsb_f('last_name', 'Last name', 'row.last_name', 'text', 50, 1, 'always', 'name', 'validated', 'Validator rejected apostrophes and N-tilde in the sample run.'),
        gsb_f('first_name', 'First name', 'row.first_name', 'text', 50, 1, 'always', 'name', 'validated'),
        gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 50, 0, 'always', 'name', 'validated'),
        gsb_f('region', 'Region', 'row.region_code', 'code', 5, 1, 'never', null, 'validated'),
    );
    $c1 = array(
        gsb_f('rec_type', 'Control code', 'lit:C1', 'raw', 2, 1, 'never', null, 'validated'),
        gsb_f('form_type', 'Form type', 'lit:1604C', 'raw', 5, 1, 'never', null, 'validated'),
        gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'validated'),
        gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'validated'),
        gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'validated'),
    );
    foreach ($prev as $k => $lbl) { $d1[] = $amt($k, $lbl); $c1[] = $sum($k, 'Total ' . lcfirst($lbl)); }
    $d1[] = gsb_f('employment_from', 'Period of employment from', 'row.employment_from', 'date', 10, 1, 'never', null, 'validated');
    $d1[] = gsb_f('employment_to', 'Period of employment to', 'row.employment_to', 'date', 10, 1, 'never', null, 'validated');
    foreach ($pres as $k => $lbl) { $d1[] = $amt($k, $lbl); $c1[] = $sum($k, 'Total ' . lcfirst($lbl)); }
    foreach ($tail as $k => $lbl) { $d1[] = $amt($k, $lbl); $c1[] = $sum($k, 'Total ' . lcfirst($lbl)); }
    $d1[] = gsb_f('nationality', 'Nationality', 'row.nationality', 'code', 30, 1, 'never', null, 'validated');
    $d1[] = gsb_f('employment_status', 'Employment status', 'row.employment_status', 'code', 2, 1, 'never', null, 'validated', 'R, C, CP, S, P or AL');
    $d1[] = gsb_f('separation_reason', 'Reason of separation', 'row.separation_reason', 'code', 2, 1, 'never', null, 'validated', 'T, TR, R, D or NA');
    $d1[] = gsb_f('substituted_filing', 'Substituted filing', 'row.substituted_filing', 'code', 1, 1, 'never', null, 'validated');
    $d1[] = $amt('pera_credit', '5% PERA tax credit');
    $c1[] = $sum('pera_credit', 'Total 5% PERA tax credit');

    $L[] = array(
        'form_code' => '1604C', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'Alphalist v7.4 (TY2025 sample)',
        'validator_version' => '7.4', 'effective_from' => '2024-01-01', 'status' => 'pending_verification', 'confidence' => 'validated',
        'filename_pattern' => '{TIN}{BRANCH}{MMDDYYYY}1604C.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Byte layout reproduced from a v7.4 sample (github.com/ReyJrFerrer/BIR-dat, res/6847855100000123120251604C.DAT) whose BIR validator report reads "No Errors Encountered". Code lists from RMC 160-2022 attachment. One sample does not cover previous-employer, refund or PERA cases.',
        'notes' => 'H1604C has 4 fields; each employee is one D1 line of 49 fields; C1 has 36 fields. The test suite reproduces the sample byte for byte.',
        'records' => array(
            array('record_code' => 'H1604C', 'role' => 'header', 'label' => 'Header', 'fields' => array(
                gsb_f('form_type', 'Form type', 'lit:H1604C', 'raw', 6, 1, 'never', null, 'validated'),
                gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'validated'),
                gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'validated'),
                gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'validated'),
            )),
            array('record_code' => 'D1', 'role' => 'detail', 'label' => 'Employee detail (Schedule 1)', 'fields' => $d1),
            array('record_code' => 'C1', 'role' => 'control', 'label' => 'Control totals', 'fields' => $c1),
        ),
    );

    // ------------------------------------------------------------------ 1604-C Schedule 2 (MWE) draft
    $mwe = array(
        gsb_f('rec_type', 'Schedule code', 'lit:D2', 'raw', 2, 1, 'never', null, 'analog'),
        gsb_f('form_type', 'Form type', 'lit:1604C', 'raw', 5, 1, 'never', null, 'analog'),
        gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
        gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
        gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'analog'),
        gsb_f('seq_no', 'Sequence number', 'seq', 'int', 8, 1, 'never', null, 'analog'),
        gsb_f('emp_tin', 'Employee TIN', 'row.tin', 'digits', 9, 1, 'never', null, 'analog'),
        gsb_f('emp_branch', 'Employee TIN branch', 'row.tin_branch', 'digits', 4, 1, 'never', null, 'analog'),
        gsb_f('last_name', 'Last name', 'row.last_name', 'text', 50, 1, 'always', 'name', 'analog'),
        gsb_f('first_name', 'First name', 'row.first_name', 'text', 50, 1, 'always', 'name', 'analog'),
        gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 50, 0, 'always', 'name', 'analog'),
        gsb_f('region', 'Region', 'row.region_code', 'code', 5, 1, 'never', null, 'analog'),
        gsb_f('employment_from', 'Period of employment from', 'row.employment_from', 'date', 10, 1, 'never', null, 'analog'),
        gsb_f('employment_to', 'Period of employment to', 'row.employment_to', 'date', 10, 1, 'never', null, 'analog'),
        gsb_f('pres_gross', 'Gross compensation', 'row.pres_gross', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('smw_per_day', 'SMW per day', 'row.smw_per_day', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('smw_per_month', 'SMW per month', 'row.smw_per_month', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('smw_per_year', 'SMW per year', 'row.smw_per_year', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('factor_days', 'Factor used (days per year)', 'row.factor_days', 'int', 3, 1, 'never', null, 'analog'),
        gsb_f('mwe_holiday_pay', 'Holiday pay', 'row.mwe_holiday_pay', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('mwe_overtime_pay', 'Overtime pay', 'row.mwe_overtime_pay', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('mwe_night_diff', 'Night shift differential', 'row.mwe_night_diff', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('mwe_hazard_pay', 'Hazard pay', 'row.mwe_hazard_pay', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('pres_nt_13th', '13th month and other benefits', 'row.pres_nt_13th', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('pres_nt_deminimis', 'De minimis benefits', 'row.pres_nt_deminimis', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('pres_nt_contrib', 'SSS/GSIS/PHIC/HDMF and union dues', 'row.pres_nt_contrib', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('pres_nt_total', 'Total non-taxable compensation', 'row.pres_nt_total', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('pres_tx_total', 'Taxable compensation (other than SMW)', 'row.pres_tx_total', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('tax_withheld_adjusted', 'Tax withheld as adjusted', 'row.tax_withheld_adjusted', 'amount', 15, 1, 'never', '2dp', 'analog'),
        gsb_f('nationality', 'Nationality', 'row.nationality', 'code', 30, 1, 'never', null, 'analog'),
        gsb_f('employment_status', 'Employment status', 'row.employment_status', 'code', 2, 1, 'never', null, 'analog'),
        gsb_f('separation_reason', 'Reason of separation', 'row.separation_reason', 'code', 2, 1, 'never', null, 'analog'),
    );
    $L[] = array(
        'form_code' => '1604C', 'schedule_code' => 'S2', 'variant_key' => null, 'version_label' => 'Analog draft (unsourced)',
        'validator_version' => '7.4', 'effective_from' => '2024-01-01', 'status' => 'draft', 'confidence' => 'analog',
        'filename_pattern' => '{TIN}{BRANCH}{MMDDYYYY}1604C.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Column set follows the printed 1604-C Schedule 2 in the RMC 160-2022 attachment. DAT positions and the D2 record code are not confirmed.',
        'notes' => 'Research pending. Minimum wage earners must not be inferred from income alone.',
        'records' => array(
            array('record_code' => 'H1604C', 'role' => 'header', 'label' => 'Header', 'fields' => array(
                gsb_f('form_type', 'Form type', 'lit:H1604C', 'raw', 6, 1, 'never', null, 'validated'),
                gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'validated'),
                gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'validated'),
                gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'validated'),
            )),
            array('record_code' => 'D2', 'role' => 'detail', 'label' => 'MWE detail (Schedule 2)', 'fields' => $mwe),
            array('record_code' => 'C2', 'role' => 'control', 'label' => 'Control totals', 'fields' => array(
                gsb_f('rec_type', 'Control code', 'lit:C2', 'raw', 2, 1, 'never', null, 'analog'),
                gsb_f('form_type', 'Form type', 'lit:1604C', 'raw', 5, 1, 'never', null, 'analog'),
                gsb_f('tin_wa', 'Employer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                gsb_f('branch_wa', 'Employer branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'analog'),
                gsb_f('pres_gross', 'Total gross compensation', 'sum.pres_gross', 'amount', 17, 1, 'never', '2dp', 'analog'),
                gsb_f('pres_nt_total', 'Total non-taxable', 'sum.pres_nt_total', 'amount', 17, 1, 'never', '2dp', 'analog'),
                gsb_f('pres_tx_total', 'Total taxable', 'sum.pres_tx_total', 'amount', 17, 1, 'never', '2dp', 'analog'),
                gsb_f('tax_withheld_adjusted', 'Total tax withheld', 'sum.tax_withheld_adjusted', 'amount', 17, 1, 'never', '2dp', 'analog'),
            )),
        ),
    );

    // ------------------------------------------------------------------ 1604-E and 1604-F annual schedules (draft analogs)
    $annual = array(
        array('1604E', 'S3', 'D3', 'C3'), array('1604E', 'S4', 'D4', 'C4'),
        array('1604F', 'S4', 'D4', 'C4'), array('1604F', 'S5', 'D5', 'C5'), array('1604F', 'S6', 'D6', 'C6'),
    );
    foreach ($annual as $a) {
        $form = $a[0];
        $L[] = array(
            'form_code' => $form, 'schedule_code' => $a[1], 'variant_key' => null, 'version_label' => 'Analog draft (unsourced)',
            'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'draft', 'confidence' => 'analog',
            'filename_pattern' => '{TIN}{BRANCH}{MMDDYYYY}' . $form . '.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
            'encoding' => 'Windows-1252', 'column_header_row' => 0,
            'source_ref' => 'Annex A/B of RMC 25-2024 lists these schedules but the PDF is image-only. Header mirrors the validated H1604C shape; detail mirrors QAP. Replace from Annex A before approval.',
            'notes' => 'Research pending. Files are always labelled UNVALIDATED DRAFT.',
            'records' => array(
                array('record_code' => 'H' . $form, 'role' => 'header', 'label' => 'Header', 'fields' => array(
                    gsb_f('form_type', 'Form type', 'lit:H' . $form, 'raw', 6, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'Withholding agent TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'analog'),
                )),
                array('record_code' => $a[2], 'role' => 'detail', 'label' => 'Detail', 'fields' => array(
                    gsb_f('rec_type', 'Schedule code', 'lit:' . $a[2], 'raw', 2, 1, 'never', null, 'analog'),
                    gsb_f('form_type', 'Form type', 'lit:' . $form, 'raw', 5, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'Withholding agent TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'analog'),
                    gsb_f('seq_no', 'Sequence number', 'seq', 'int', 8, 1, 'never', null, 'analog'),
                    gsb_f('payee_tin', 'Payee TIN', 'row.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('payee_branch', 'Payee branch code', 'row.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('registered_name', 'Registered name', 'row.registered_name', 'text', 50, 0, 'nonempty', 'name', 'analog'),
                    gsb_f('last_name', 'Last name', 'row.last_name', 'text', 30, 0, 'nonempty', 'name', 'analog'),
                    gsb_f('first_name', 'First name', 'row.first_name', 'text', 30, 0, 'nonempty', 'name', 'analog'),
                    gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 30, 0, 'nonempty', 'name', 'analog'),
                    gsb_f('atc', 'ATC', 'row.atc', 'code', 5, 1, 'never', null, 'analog'),
                    gsb_f('income_payment', 'Amount of income payment', 'row.income', 'amount', 15, 1, 'never', '2dp', 'analog'),
                    gsb_f('tax_rate', 'Tax rate', 'row.rate', 'rate', 6, 1, 'never', 'pct2', 'analog'),
                    gsb_f('tax_withheld', 'Amount of tax withheld', 'row.tax', 'amount', 15, 1, 'never', '2dp', 'analog'),
                )),
                array('record_code' => $a[3], 'role' => 'control', 'label' => 'Control totals', 'fields' => array(
                    gsb_f('rec_type', 'Control code', 'lit:' . $a[3], 'raw', 2, 1, 'never', null, 'analog'),
                    gsb_f('form_type', 'Form type', 'lit:' . $form, 'raw', 5, 1, 'never', null, 'analog'),
                    gsb_f('tin_wa', 'Withholding agent TIN', 'agent.tin', 'digits', 9, 1, 'never', null, 'analog'),
                    gsb_f('branch_wa', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, 'analog'),
                    gsb_f('return_period', 'Period end', 'period.mm_dd_yyyy_end', 'raw', 10, 1, 'never', null, 'analog'),
                    gsb_f('total_income', 'Total income payment', 'sum.income', 'amount', 17, 1, 'never', '2dp', 'analog'),
                    gsb_f('total_tax', 'Total tax withheld', 'sum.tax', 'amount', 17, 1, 'never', '2dp', 'analog'),
                )),
            ),
        );
    }

    // ------------------------------------------------------------------ SAWT (generic quoted variant)
    $sawtHead = function ($quote, $conf) {
        return array(
            gsb_f('alpha_type', 'Alphalist type code', 'lit:HSAWT', 'raw', 5, 1, 'never', null, $conf),
            gsb_f('form_type', 'Form type code', 'tpl:H{sawt_form}', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('tin', 'Taxpayer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, $conf),
            gsb_f('branch', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, $conf),
            gsb_f('registered_name', 'Registered name', 'agent.registered_name', 'text', 50, 0, $quote, 'name', $conf),
            gsb_f('last_name', 'Last name', 'agent.last_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('first_name', 'First name', 'agent.first_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('middle_name', 'Middle name', 'agent.middle_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('rdo', 'RDO code', 'agent.rdo', 'digits', 3, 1, 'never', null, $conf),
        );
    };
    $sawtDetail = function ($quote, $amtFmt, $conf) {
        return array(
            gsb_f('alpha_type', 'Alphalist type code', 'lit:DSAWT', 'raw', 5, 1, 'never', null, $conf),
            gsb_f('form_type', 'Form type code', 'tpl:D{sawt_form}', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('seq_no', 'Sequence number', 'seq', 'int', 8, 1, 'never', null, $conf),
            gsb_f('payor_tin', 'Payor TIN', 'row.tin', 'digits', 9, 1, 'never', null, $conf),
            gsb_f('payor_branch', 'Payor branch code', 'row.branch', 'digits', 4, 1, 'never', null, $conf),
            gsb_f('registered_name', 'Payor registered name', 'row.registered_name', 'text', 50, 0, $quote, 'name', $conf),
            gsb_f('last_name', 'Payor last name', 'row.last_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('first_name', 'Payor first name', 'row.first_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('middle_name', 'Payor middle name', 'row.middle_name', 'text', 30, 0, $quote, 'name', $conf),
            gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('reserved', 'Reserved (empty)', 'blank', 'raw', null, 0, 'never', null, $conf, 'Empty position observed in the source generator.'),
            gsb_f('atc', 'ATC', 'row.atc', 'code', 5, 1, 'never', null, $conf),
            gsb_f('tax_rate', 'Tax rate', 'row.rate', 'rate', 6, 1, 'never', $amtFmt === 'trim' ? 'pct2' : 'pct2', 'low'),
            gsb_f('income_payment', 'Income payment', 'row.income', 'amount', 15, 1, 'never', $amtFmt, $conf),
            gsb_f('tax_withheld', 'Tax withheld', 'row.tax', 'amount', 15, 1, 'never', $amtFmt, $conf),
        );
    };
    $sawtControl = function ($amtFmt, $conf) {
        return array(
            gsb_f('alpha_type', 'Alphalist type code', 'lit:CSAWT', 'raw', 5, 1, 'never', null, $conf),
            gsb_f('form_type', 'Form type code', 'tpl:C{sawt_form}', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('tin', 'Taxpayer TIN', 'agent.tin', 'digits', 9, 1, 'never', null, $conf),
            gsb_f('branch', 'Branch code', 'agent.branch', 'digits', 4, 1, 'never', null, $conf),
            gsb_f('return_period', 'Return period', 'period.mm_yyyy', 'raw', 7, 1, 'never', null, $conf),
            gsb_f('total_income', 'Total income payment', 'sum.income', 'amount', 17, 1, 'never', $amtFmt, $conf),
            gsb_f('total_tax', 'Total tax withheld', 'sum.tax', 'amount', 17, 1, 'never', $amtFmt, $conf),
        );
    };
    $L[] = array(
        'form_code' => 'SAWT', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'Alphalist v7.x SAWT',
        'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'pending_verification', 'confidence' => 'low',
        'filename_pattern' => '{TIN}{BRANCH}{MM}{YYYY}{SAWT_FORM}.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Single secondary generator (github.com/AlmyrCPA/Haki-Bookkeeping-System), which notes this quoted shape for 1701/1701Q. No source found for 1700, 1702, 2550M/Q, 2551Q or 2553.',
        'notes' => 'Used for every attached return except where a variant layout exists.',
        'records' => array(
            array('record_code' => 'HSAWT', 'role' => 'header', 'label' => 'Header', 'fields' => $sawtHead('always', 'low')),
            array('record_code' => 'DSAWT', 'role' => 'detail', 'label' => 'Detail (payor and ATC)', 'fields' => $sawtDetail('nonempty', '2dp', 'low')),
            array('record_code' => 'CSAWT', 'role' => 'control', 'label' => 'Control totals', 'fields' => $sawtControl('2dp', 'low')),
        ),
    );
    $h1702 = $sawtHead('never', 'low');
    for ($i = 1; $i <= 5; $i++) { $h1702[] = gsb_f('trail' . $i, 'Trailing empty field ' . $i, 'blank', 'raw', null, 0, 'never', null, 'low'); }
    $c1702 = $sawtControl('trim', 'low');
    for ($i = 1; $i <= 8; $i++) { $c1702[] = gsb_f('trail' . $i, 'Trailing empty field ' . $i, 'blank', 'raw', null, 0, 'never', null, 'low'); }
    $L[] = array(
        'form_code' => 'SAWT', 'schedule_code' => 'S1', 'variant_key' => '1702Q', 'version_label' => 'Alphalist v7.x SAWT (1702Q shape)',
        'validator_version' => '7.4', 'effective_from' => '2018-01-01', 'status' => 'pending_verification', 'confidence' => 'low',
        'filename_pattern' => '{TIN}{BRANCH}{MM}{YYYY}{SAWT_FORM}.DAT', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 0,
        'source_ref' => 'Single secondary generator that describes this 1702Q shape as confirmed against validated files: names unquoted, amounts not padded, 5 trailing empty header fields and 8 trailing empty control fields. Unverified here.',
        'notes' => 'Applied automatically when the attached return is 1702Q.',
        'records' => array(
            array('record_code' => 'HSAWT', 'role' => 'header', 'label' => 'Header', 'fields' => $h1702),
            array('record_code' => 'DSAWT', 'role' => 'detail', 'label' => 'Detail (payor and ATC)', 'fields' => $sawtDetail('never', 'trim', 'low')),
            array('record_code' => 'CSAWT', 'role' => 'control', 'label' => 'Control totals', 'fields' => $c1702),
        ),
    );

    // ------------------------------------------------------------------ SRS (CSV) draft
    $L[] = array(
        'form_code' => 'SRS', 'schedule_code' => 'S1', 'variant_key' => null, 'version_label' => 'Consolidation CSV (unsourced)',
        'validator_version' => null, 'effective_from' => '2008-01-01', 'status' => 'draft', 'confidence' => 'analog',
        'filename_pattern' => '{TIN}{BRANCH}SRS{SEM}{YYYY}.csv', 'delimiter' => ',', 'line_ending' => 'CRLF', 'trailing_newline' => 1,
        'encoding' => 'Windows-1252', 'column_header_row' => 1,
        'source_ref' => 'SRS was required by RR 14-2008 (format under RMC 5-2009). A secondary source reports that RMC 122-2019 terminated SRS submission. Confirm current applicability before use; the official column layout was not obtainable.',
        'notes' => 'Six-month consolidation of supplier purchases subject to the selected ATCs.',
        'records' => array(
            array('record_code' => 'ROW', 'role' => 'detail', 'label' => 'Supplier row', 'fields' => array(
                gsb_f('tin', 'TIN', 'row.tin', 'digits', 9, 1, 'never', null, 'analog'),
                gsb_f('branch', 'Branch code', 'row.branch', 'digits', 4, 1, 'never', null, 'analog'),
                gsb_f('registered_name', 'Registered name', 'row.registered_name', 'text', 50, 0, 'always', 'name', 'analog'),
                gsb_f('last_name', 'Last name', 'row.last_name', 'text', 30, 0, 'always', 'name', 'analog'),
                gsb_f('first_name', 'First name', 'row.first_name', 'text', 30, 0, 'always', 'name', 'analog'),
                gsb_f('middle_name', 'Middle name', 'row.middle_name', 'text', 30, 0, 'always', 'name', 'analog'),
                gsb_f('address', 'Address', 'row.address1', 'text', null, 0, 'always', 'addr', 'analog'),
                gsb_f('atc', 'ATC', 'row.atc', 'code', 5, 1, 'never', null, 'analog'),
                gsb_f('income', 'Amount of purchases', 'row.income', 'amount', 15, 1, 'never', '2dp', 'analog'),
                gsb_f('tax', 'Tax withheld', 'row.tax', 'amount', 15, 1, 'never', '2dp', 'analog'),
                gsb_f('months', 'Months included', 'row.months', 'raw', 20, 0, 'always', null, 'analog'),
            )),
        ),
    );

    return $L;
}
