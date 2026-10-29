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
            // The 17 columns of the VAT sales workbook, in its order, with its headers and widths. GROSS TAXABLE
            // includes VAT; TAXABLE SALES and OUTPUT VAT may be left blank and are worked out from it and the rate.
            'sales' => array('label' => 'Sales (VAT sales format)', 'code' => 'GSB-SALES', 'module' => 'sales', 'role' => 'customer', 'sheet' => 'Sheet1', 'version' => '2.0', 'columns' => array(
                array('key' => 'p_tin', 'title' => 'CUSTOMER TIN', 'format' => 'text', 'width' => 13.86, 'help' => 'Customer TIN, 9 digits, dashes allowed. Keep the column as Text so leading zeros stay. Leave blank for walk-in customers without a TIN.', 'example' => '123-456-789'),
                array('key' => 'p_branch', 'title' => 'CUSTOMER BRANCH CODE', 'format' => 'text', 'width' => 22.27, 'help' => '4 digits; 0000 for the head office or when blank.', 'example' => '0000'),
                array('key' => 'p_registered_name', 'title' => 'REGISTERED NAME', 'format' => 'text', 'width' => 16.07, 'help' => 'For a company or other non-individual. Leave blank for individuals.', 'example' => 'ACME TRADING CORP'),
                array('key' => 'p_last_name', 'title' => 'LAST NAME', 'format' => 'text', 'width' => 13.2, 'help' => 'Individual customers only.', 'example' => ''),
                array('key' => 'p_first_name', 'title' => 'FIRST NAME', 'format' => 'text', 'width' => 13.66, 'help' => 'Individual customers only.', 'example' => ''),
                array('key' => 'p_middle_name', 'title' => 'MIDDLE NAME', 'format' => 'text', 'width' => 12.6, 'help' => 'Individual customers only.', 'example' => ''),
                array('key' => 'p_address1', 'title' => 'SUBSTREET, STREET, BARANGAY', 'format' => 'text', 'width' => 28, 'help' => 'Address line 1.', 'example' => '12 RIZAL ST BRGY CAPITOL'),
                array('key' => 'p_address2', 'title' => 'DISTRICT, CITY, ZIP CODE', 'format' => 'text', 'width' => 20.73, 'help' => 'Address line 2. A 4-digit ZIP code at the end also fills the customer\'s ZIP code.', 'example' => 'CEBU CITY 6000'),
                array_merge($branch, array('width' => 18.53)),
                array('key' => 'invoice_no', 'title' => 'INVOICE NO', 'format' => 'text', 'width' => 10.53, 'help' => 'Sales invoice or official receipt number.', 'example' => 'SI-000101'),
                array('key' => 'invoice_date', 'title' => 'INVOICE DATE', 'format' => 'text', 'width' => 12.2, 'required' => true, 'help' => 'MM/DD/YYYY. Sets the month of the sale.', 'example' => '01/15/2026'),
                array('key' => 'gross_taxable', 'title' => 'GROSS TAXABLE', 'format' => 'amount', 'width' => 13.93, 'help' => 'Taxable sales including VAT. TAXABLE SALES and OUTPUT VAT are worked out from it when left blank.', 'example' => '112000.00'),
                array('key' => 'exempt_amount', 'title' => 'EXEMPT SALES', 'format' => 'amount', 'width' => 12.53, 'help' => 'VAT-exempt sales.', 'example' => '0'),
                array('key' => 'zero_rated_amount', 'title' => 'ZERO RATED SALES', 'format' => 'amount', 'width' => 16.13, 'help' => 'Zero-rated sales.', 'example' => '0'),
                array('key' => 'taxable_amount', 'title' => 'TAXABLE SALES', 'format' => 'amount', 'width' => 13.27, 'help' => 'Taxable sales net of VAT. Blank: GROSS TAXABLE / (1 + VAT RATE / 100), rounded to the centavo.', 'example' => '100000.00'),
                array('key' => 'vat_rate', 'title' => 'VAT RATE', 'format' => 'amount', 'width' => 13.27, 'weak' => true, 'help' => 'Percent. Blank means 12; 0 for sales without VAT.', 'example' => '12'),
                array('key' => 'output_vat', 'title' => 'OUTPUT VAT', 'format' => 'amount', 'width' => 15.4, 'help' => 'Blank: GROSS TAXABLE - TAXABLE SALES, or TAXABLE SALES x VAT RATE / 100. A different amount is kept and flagged.', 'example' => '12000.00'),
                array('key' => 'description', 'title' => 'DESCRIPTION', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
            )),
            // Exactly the 16 columns of the R_Purchases workbook, in its order and with its headers.
            // Columns marked 'extra' are optional, never in the template, and only used when a file has them.
            'purchases' => array('label' => 'Purchases (R_Purchases format)', 'code' => 'GSB-PURCHASES', 'module' => 'purchases', 'role' => 'supplier',
                'sheet' => 'R_Purchases', 'version' => '2.1', 'needs_month' => true, 'repeatable' => true, 'columns' => array(
                array('key' => 'p_tin', 'title' => 'Vendor_TIN', 'format' => 'text', 'width' => 18.73, 'help' => 'Vendor TIN, 9 digits. Keep the column as Text so leading zeros stay. A 13-digit TIN includes the branch code.', 'example' => '000488793'),
                array('key' => 'p_registered_name', 'title' => 'companyName', 'format' => 'text', 'width' => 69.13, 'help' => 'Registered name of a non-individual vendor. Leave blank for individuals.', 'example' => 'PLDT, INC.'),
                array('key' => 'p_last_name', 'title' => 'lastName', 'format' => 'text', 'width' => 10, 'help' => 'Individual vendors only.', 'example' => ''),
                array('key' => 'p_first_name', 'title' => 'firstName', 'format' => 'text', 'width' => 10.4, 'help' => 'Individual vendors only.', 'example' => ''),
                array('key' => 'p_middle_name', 'title' => 'middleName', 'format' => 'text', 'width' => 13.6, 'help' => 'Individual vendors only.', 'example' => ''),
                array('key' => 'p_address1', 'title' => 'address1', 'format' => 'text', 'width' => 9.4, 'help' => 'Street and barangay.', 'example' => ''),
                array('key' => 'p_address2', 'title' => 'address2', 'format' => 'text', 'width' => 9, 'help' => 'Town or city and province.', 'example' => ''),
                array('key' => 'exempt_amount', 'title' => 'exempt', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 8.4, 'help' => 'Exempt purchases.', 'example' => '0'),
                array('key' => 'zero_rated_amount', 'title' => 'zeroRated', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 10.6, 'help' => 'Zero-rated purchases.', 'example' => '0'),
                array('key' => 'services_amount', 'title' => 'services', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 9.13, 'help' => 'Purchase of services, net of VAT.', 'example' => '0'),
                array('key' => 'capital_goods_amount', 'title' => 'capitalGoods', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 13.6, 'help' => 'Purchase of capital goods, net of VAT.', 'example' => '0'),
                array('key' => 'other_goods_amount', 'title' => 'otherThancapitalGoods', 'format' => 'amount', 'xlsx_format' => 'red_negative', 'width' => 19.66, 'help' => 'Purchase of goods other than capital goods, net of VAT.', 'example' => '1695.54'),
                array('key' => 'taxable_net', 'title' => 'taxableNetofVat', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 15.27, 'computed' => true,
                    'formula' => 'ROUND(SUM(J{r}:L{r}),2)', 'help' => 'Formula: services + capitalGoods + otherThancapitalGoods. Checked, not typed.', 'example' => ''),
                array('key' => 'vat_rate', 'title' => 'vatRate', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 8.13, 'weak' => true, 'help' => 'VAT rate in percent. Blank means 12.', 'example' => '12'),
                array('key' => 'input_vat', 'title' => 'inputVat', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 9.27, 'weak' => true,
                    'formula' => 'ROUND(M{r}*N{r}/100,2)', 'help' => 'Formula: taxableNetofVat x vatRate / 100. A typed amount is kept and compared.', 'example' => ''),
                array('key' => 'total_purchases', 'title' => 'totalPurchases', 'format' => 'amount', 'xlsx_format' => '0.00', 'width' => 15.13, 'computed' => true,
                    'formula' => 'ROUND(SUM(H{r}:L{r})+O{r},2)', 'help' => 'Formula: exempt + zeroRated + services + capitalGoods + otherThancapitalGoods + inputVat, so each taxable purchase counts with its VAT. Checked, not typed.', 'example' => ''),
                array('key' => 'p_branch', 'title' => 'Vendor branch code', 'format' => 'text', 'extra' => true, 'help' => 'Optional. 4 digits; 0000 when blank.'),
                $branch + array('extra' => true),
                array('key' => 'invoice_no', 'title' => 'Invoice no', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
                array('key' => 'invoice_date', 'title' => 'Invoice date', 'format' => 'text', 'extra' => true, 'help' => 'Optional. MM/DD/YYYY. When mapped, it sets the month of each row.'),
                array('key' => 'non_creditable_vat', 'title' => 'Non-creditable input VAT', 'format' => 'amount', 'extra' => true, 'help' => 'Optional.'),
                array('key' => 'description', 'title' => 'Description', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
            )),
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
            // Taxes we withheld: 17 columns. ATC is a dropdown of the codes saved in the system (filled when the
            // template is made); SCHEDULE is 1 or 2 (3 only for final tax). Reference no. and rate exception note
            // are no longer in the template but are still read when a file has them.
            'withholding' => array('label' => 'Taxes we withheld (EWT, FWT, government)', 'code' => 'GSB-WH-AGENT', 'module' => 'withholding', 'role' => 'payee', 'version' => '2.0', 'columns' => array(
                array('key' => 'p_tin', 'title' => 'PAYEE TIN', 'format' => 'text', 'width' => 18.46, 'help' => '9 digits, dashes allowed; a branch code at the end is read too. Keep the column as Text.', 'example' => '123-456-789'),
                array('key' => 'p_branch', 'title' => 'PAYEE BRANCH CODE', 'format' => 'text', 'width' => 18.13, 'help' => '4 digits; 0000 for the head office or when blank.', 'example' => '0000'),
                array('key' => 'p_registered_name', 'title' => 'REGISTERED NAME', 'format' => 'text', 'width' => 22.66, 'help' => 'For non-individuals. Leave blank for individuals.', 'example' => 'ACME TRADING CORP'),
                array('key' => 'p_last_name', 'title' => 'LAST NAME', 'format' => 'text', 'width' => 10.13, 'help' => 'For individuals only.', 'example' => ''),
                array('key' => 'p_first_name', 'title' => 'FIRST NAME', 'format' => 'text', 'width' => 10.6, 'help' => 'For individuals only.', 'example' => ''),
                array('key' => 'p_middle_name', 'title' => 'MIDDLE NAME', 'format' => 'text', 'width' => 12.6, 'help' => 'For individuals only.', 'example' => ''),
                array('key' => 'p_address1', 'title' => 'ADDRESS 1', 'format' => 'text', 'width' => 42.8, 'help' => 'Street, barangay.', 'example' => '12 RIZAL ST BRGY CAPITOL'),
                array('key' => 'p_address2', 'title' => 'ADDRESS 2', 'format' => 'text', 'width' => 9.66, 'help' => 'Town or city, province.', 'example' => 'CEBU CITY'),
                array_merge($branch, array('width' => 18.53)),
                array('key' => 'tax_kind', 'title' => 'KIND', 'format' => 'text', 'width' => 8, 'help' => 'EWT, FWT, GVAT or GPT. Blank means EWT.', 'example' => 'EWT', 'list' => array('EWT', 'FWT', 'GVAT', 'GPT')),
                array('key' => 'atc_code', 'title' => 'ATC', 'format' => 'text', 'width' => 11, 'required' => true, 'atc_list' => true, 'help' => 'Choose from the list of tax codes saved in the system (sheet ATC codes). Add missing codes under Admin, Tax codes.', 'example' => 'WC158'),
                array('key' => 'payment_date', 'title' => 'PAYMENT DATE', 'format' => 'text', 'width' => 13.53, 'required' => true, 'help' => 'MM/DD/YYYY', 'example' => '01/31/2026'),
                array('key' => 'nature_of_payment', 'title' => 'NATURE OF PAYMENT', 'format' => 'text', 'width' => 18.66, 'help' => 'Blank uses the ATC description.', 'example' => 'Purchase of goods'),
                array('key' => 'income_amount', 'title' => 'INCOME PAYMENT', 'format' => 'amount', 'width' => 16, 'required' => true, 'example' => '100000.00'),
                array('key' => 'tax_rate', 'title' => 'TAX RATE', 'format' => 'text', 'width' => 8.53, 'help' => 'Percent (1 for 1%) or a decimal (0.01). Blank uses the ATC rate.', 'example' => '1'),
                array('key' => 'tax_withheld', 'title' => 'TAX WITHHELD', 'format' => 'amount', 'width' => 13, 'help' => 'Blank computes income x rate. A different amount is refused, so a typing error is caught.', 'example' => '1000.00'),
                array('key' => 'schedule_tag', 'title' => 'SCHEDULE', 'format' => 'text', 'width' => 9, 'help' => '1 or 2. Blank means 1. Use 3 only for final tax (1601-FQ Schedule 3).', 'example' => '1', 'list' => array('1', '2')),
                array('key' => 'reference_no', 'title' => 'REFERENCE NO', 'format' => 'text', 'extra' => true, 'help' => 'Optional; no longer in the template.'),
                array('key' => 'rate_exception_note', 'title' => 'RATE EXCEPTION NOTE', 'format' => 'text', 'extra' => true, 'help' => 'Optional; no longer in the template.'),
            )),
            // The SAWT report layout: title lines (form, month, TIN, payee's name) above a three-row header,
            // a "(1)".."(8)" line, dashes, one line per payor and ATC, then Grand Total and END OF REPORT.
            // Only the nine columns are read; the title lines are checked against this client.
            'withheld_on_us' => array('label' => 'Taxes withheld on us (SAWT)', 'code' => 'GSB-SAWT', 'module' => 'withheld_on_us', 'role' => 'payor',
                'sheet' => 'Sheet1', 'version' => '2.0', 'needs_month' => true, 'month_from_file' => true, 'repeatable' => true, 'report' => 'sawt', 'columns' => array(
                array('key' => 'seq_no', 'title' => 'SEQ NO', 'format' => 'text', 'width' => 25.69, 'weak' => true, 'help' => 'Line number (1). Not stored.', 'example' => '1'),
                array('key' => 'p_tin', 'title' => 'TAXPAYER IDENTIFICATION NUMBER', 'format' => 'text', 'width' => 18, 'help' => 'Payor TIN with branch code, as 000-000-000-0000 (2). Keep the column as Text.', 'example' => '000-123-456-0000'),
                array('key' => 'p_registered_name', 'title' => 'CORPORATION (Registered Name)', 'format' => 'text', 'width' => 50.69, 'help' => 'Registered name of a non-individual payor (3).', 'example' => 'SAMPLE BANKING CORPORATION'),
                array('key' => 'p_individual', 'title' => 'INDIVIDUAL (Last Name, First Name, Middle Name)', 'format' => 'text', 'width' => 36.44, 'help' => 'An individual payor as LAST NAME, FIRST NAME, MIDDLE NAME (4). Leave blank for a corporation.', 'example' => ''),
                array('key' => 'atc_code', 'title' => 'ATC CODE', 'format' => 'text', 'width' => 25.69, 'required' => true, 'help' => 'Alphanumeric tax code (5), for example WC160 or WC120.', 'example' => 'WC160'),
                array('key' => 'nature_of_payment', 'title' => 'NATURE OF PAYMENT', 'format' => 'text', 'width' => 50.69, 'help' => 'Description of the income payment. Blank uses the ATC description.', 'example' => ''),
                array('key' => 'income_amount', 'title' => 'AMOUNT OF INCOME PAYMENT', 'format' => 'amount', 'xlsx_format' => 'sawt', 'width' => 25.69, 'required' => true, 'help' => 'Income payment (6).', 'example' => '100000.00'),
                array('key' => 'tax_rate', 'title' => 'TAX RATE', 'format' => 'amount', 'xlsx_format' => 'sawt', 'width' => 12, 'weak' => true, 'help' => 'Percent (7), for example 2. Blank uses the ATC rate.', 'example' => '2'),
                array('key' => 'tax_withheld', 'title' => 'AMOUNT OF TAX WITHHELD', 'format' => 'amount', 'xlsx_format' => 'sawt', 'width' => 18, 'weak' => true,
                    'formula' => 'IF(G{r}="","",ROUND(G{r}*H{r}/100,2))', 'help' => 'Tax withheld (8), as on the 2307. Blank computes income x rate.', 'example' => ''),
                array('key' => 'p_branch', 'title' => 'Payor branch code', 'format' => 'text', 'extra' => true, 'help' => 'Optional when the TIN already ends in the branch code.'),
                array('key' => 'p_last_name', 'title' => 'Last name', 'format' => 'text', 'extra' => true, 'help' => 'Optional: an individual payor in separate columns.'),
                array('key' => 'p_first_name', 'title' => 'First name', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
                array('key' => 'p_middle_name', 'title' => 'Middle name', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
                $branch + array('extra' => true),
                array('key' => 'payment_date', 'title' => 'Date of payment', 'format' => 'text', 'extra' => true, 'help' => 'Optional. MM/DD/YYYY. When mapped, it sets the month of each row.'),
                array('key' => 'reference_no', 'title' => 'Certificate reference', 'format' => 'text', 'extra' => true, 'help' => 'Optional. The 2307 number or reference.'),
                array('key' => 'rate_exception_note', 'title' => 'Note', 'format' => 'text', 'extra' => true, 'help' => 'Optional.'),
            )),
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
    /** Columns that belong in the template and in an exact-match file (optional extras excluded). */
    public static function templateColumns($type)
    {
        $out = array();
        foreach ($type['columns'] as $c) {
            if (empty($c['extra'])) {
                $out[] = $c;
            }
        }
        return $out;
    }

    public static function template($typeKey, $withSamples, $ctx = array())
    {
        $type = self::type($typeKey);
        if ($typeKey === 'withheld_on_us') {
            $client = isset($ctx['client']) ? $ctx['client'] : Ctx::client();
            $month = isset($ctx['month']) && $ctx['month'] ? $ctx['month'] : date('Y-m', strtotime('first day of last month'));
            $lines = array();
            if ($withSamples) {
                foreach (array(
                             array('000-123-456-0000', 'SAMPLE BANKING CORPORATION', '', 'WC160', 100000, 2, 2000),
                             array('000-234-567-0000', 'SAMPLE PROPERTY HOLDINGS INC', '', 'WC120', 45250.5, 2, 905.01),
                             array('000-345-678-0000', '', 'DELA CRUZ, JUAN, SANTOS', 'WC160', 12000, 2, 240),
                         ) as $v) {
                    $tc = Records::taxCode($v[3], 'EWT', last_day($month));
                    $lines[] = array('tin' => $v[0], 'company' => $v[1], 'individual' => $v[2], 'atc' => $v[3], 'nature' => $tc ? $tc['description'] : '',
                        'income' => $v[4], 'rate' => $v[5], 'tax' => $v[6]);
                }
            }
            return self::sawtWorkbook($client, isset($ctx['form']) ? $ctx['form'] : null, $month, $lines, true);
        }
        $cols = self::templateColumns($type);
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
            $fieldRows[] = array($c['title'], !empty($c['required']) ? 'Yes' : 'No', !empty($c['formula']) ? 'Formula (pre-filled)' : ($c['format'] === 'amount' ? 'Amount, 2 decimals' : 'Text'),
                isset($c['help']) ? $c['help'] : '', !empty($c['list']) ? implode(', ', $c['list']) : (!empty($c['atc_list']) ? 'The codes on the sheet ATC codes' : ''), isset($c['example']) ? $c['example'] : '');
        }
        // A dropdown of the tax codes saved in the system, read from a sheet of its own.
        $atcSheet = null;
        foreach ($cols as $i => $c) {
            if (!empty($c['atc_list'])) {
                $codes = TaxCodes::current();
                if ($codes) {
                    $lists[$i] = "'ATC codes'!\$A\$2:\$A\$" . (count($codes) + 1);
                    $atcRows = array();
                    foreach ($codes as $tc) {
                        $atcRows[] = array($tc['atc_code'], $tc['tax_kind'], TaxCodes::payeeLabel($tc['payee_type']), Money::rateFormat(Money::rateUnits($tc['rate']), 'pct2') . '%', $tc['description'],
                            $tc['status'] === 'approved' ? 'Approved' : 'Pending review');
                    }
                    $atcSheet = array('name' => 'ATC codes', 'columns' => array(array('title' => 'ATC', 'width' => 11, 'format' => 'text'), array('title' => 'KIND', 'width' => 8, 'format' => 'text'),
                        array('title' => 'PAYEE TYPE', 'width' => 16, 'format' => 'text'), array('title' => 'RATE', 'width' => 9, 'format' => 'text'),
                        array('title' => 'DESCRIPTION', 'width' => 70, 'format' => 'text'), array('title' => 'STATUS', 'width' => 16, 'format' => 'text')), 'rows' => $atcRows, 'freeze' => true);
                }
            }
        }
        $data = array('name' => isset($type['sheet']) ? $type['sheet'] : 'Data', 'columns' => $cols, 'rows' => $rows, 'freeze' => true, 'protect_header' => true, 'lists' => $lists);
        $rules = 'Keep the header row unchanged. Enter one record per row on the Data sheet. Keep TIN columns as Text so leading zeros stay. Dates as MM/DD/YYYY.';
        if ($typeKey === 'sales') {
            // Same layout as the VAT sales workbook: the shaded header row, no frozen pane, no sheet protection.
            $data['freeze'] = false;
            $data['protect_header'] = false;
            $rules = 'Keep the header row unchanged. One invoice per row. Keep CUSTOMER TIN as Text so leading zeros stay. Enter GROSS TAXABLE (with VAT) or TAXABLE SALES (net of VAT); the other and OUTPUT VAT are worked out when left blank. VAT RATE blank means 12. Dates as MM/DD/YYYY.';
        }
        if ($typeKey === 'purchases') {
            // Same layout as the R_Purchases workbook: plain bold headers, no frozen pane, formulas in
            // taxableNetofVat, inputVat and totalPurchases, vatRate 12, on every row ready for data.
            $data['header'] = 'bold';
            $data['freeze'] = false;
            $data['protect_header'] = false;
            $data['formula_rows'] = 500;
            $data['defaults'] = array('vat_rate' => 12, 'exempt_amount' => 0, 'zero_rated_amount' => 0, 'services_amount' => 0, 'capital_goods_amount' => 0);
            $rules = 'Keep the header row unchanged. One purchase per row. Keep Vendor_TIN as Text so leading zeros stay. Do not type over taxableNetofVat or totalPurchases. The purchase month is chosen when you upload, because this sheet has no date column.';
        }
        if ($typeKey === 'withholding') {
            $data['freeze'] = false;
            $data['protect_header'] = false;
            $rules = 'Keep the header row unchanged. One payment per row. Keep PAYEE TIN as Text so leading zeros stay. Pick the ATC from the list (sheet ATC codes). SCHEDULE is 1 or 2. Dates as MM/DD/YYYY.';
        }
        $sheetsOut = array($data);
        if ($atcSheet) {
            $sheetsOut[] = $atcSheet;
        }
        return XlsxWriter::build(array_merge($sheetsOut, array(
            array('name' => 'Fields', 'columns' => array(
                array('title' => 'Column', 'width' => 34, 'format' => 'text'), array('title' => 'Required', 'width' => 10, 'format' => 'text'),
                array('title' => 'Type', 'width' => 18, 'format' => 'text'), array('title' => 'How to fill', 'width' => 60, 'format' => 'text'),
                array('title' => 'Allowed values', 'width' => 30, 'format' => 'text'), array('title' => 'Example', 'width' => 22, 'format' => 'text')),
                'rows' => $fieldRows, 'freeze' => true),
            array('name' => 'About', 'columns' => array(array('title' => 'Key', 'width' => 22, 'format' => 'text'), array('title' => 'Value', 'width' => 80, 'format' => 'text')),
                'rows' => array(
                    array('Template code', $type['code']), array('Template version', isset($type['version']) ? $type['version'] : self::TEMPLATE_VERSION), array('Import type', $type['label']),
                    array('Generated', date('Y-m-d H:i')), array('Rules', $rules),
                    array('Note', 'This workbook is an input template for G-SMaRT BIR. It is not a BIR form and is not submitted to the BIR.'),
                )),
        )), array('title' => $type['label'] . ' import template'));
    }

    /**
     * A workbook in the SAWT report layout (the same rows, columns, widths and number format as the BIR
     * SAWT printout): title lines, the three-row header, "(1)".."(8)", dashes, one line per payor and ATC,
     * Grand Total and END OF REPORT. As a template ($blankRows) the lines are left open with formulas for
     * the tax and the totals; as an export the amounts are written as values.
     * $lines: tin, company, individual, atc, nature, income, rate, tax.
     */
    public static function sawtWorkbook($client, $form, $month, $lines, $template = false)
    {
        $form = $form ?: ($client && $client['taxpayer_type'] === 'individual' ? '1701Q' : '1702Q');
        $name = $client ? Ctx::clientName($client) : '';
        $branch = '0000';
        if ($client) {
            $ho = Ctx::headOffice($client['id']);
            $branch = $ho ? $ho['branch_code'] : '0000';
        }
        $b = function ($v) {
            return array('v' => $v, 's' => 'bold');
        };
        $cells = array(
            1 => array(0 => $b('BIR FORM ' . $form)),
            2 => array(0 => $b('SUMMARY ALPHALIST OF WITHHOLDING TAXES (SAWT)')),
            3 => array(0 => $b('FOR THE MONTH OF ' . strtoupper(date('F', strtotime($month . '-01'))) . ', ' . substr($month, 0, 4))),
            6 => array(0 => $b('TIN : ' . ($client ? $client['tin'] . '-' . $branch : ''))),
            7 => array(0 => $b("PAYEE'S NAME: " . strtoupper($name))),
            11 => array(0 => $b('SEQ'), 1 => $b('TAXPAYER'), 2 => $b('CORPORATION'), 3 => $b('INDIVIDUAL'), 4 => $b('ATC CODE'), 5 => $b('NATURE OF PAYMENT'), 6 => $b('AMOUNT OF'), 7 => $b('TAX RATE'), 8 => $b('AMOUNT OF')),
            12 => array(0 => $b('NO'), 1 => $b('IDENTIFICATION'), 2 => $b('(Registered Name)'), 3 => $b('(Last Name, First Name, Middle Name)'), 6 => $b('INCOME PAYMENT'), 8 => $b('TAX WITHHELD')),
            13 => array(1 => $b('NUMBER')),
            14 => array(0 => $b('(1)'), 1 => $b('(2)'), 2 => $b('(3)'), 3 => $b('(4)'), 4 => $b('(5)'), 6 => $b('(6)'), 7 => $b('(7)'), 8 => $b('(8)')),
            15 => array_fill(0, 9, str_repeat('-', 30)),
        );
        $r = 16;
        $income = 0;
        $tax = 0;
        foreach (array_values($lines) as $i => $l) {
            $cells[$r] = array(0 => $i + 1, 1 => array('v' => (string)$l['tin'], 's' => 'text'), 2 => (string)$l['company'], 3 => (string)$l['individual'],
                4 => (string)$l['atc'], 5 => (string)$l['nature'], 6 => array('v' => (float)$l['income'], 's' => 'amount'), 7 => array('v' => (float)$l['rate'], 's' => 'amount'));
            $cells[$r][8] = $template ? array('f' => 'IF(OR(G' . $r . '="",H' . $r . '=""),"",ROUND(G' . $r . '*H' . $r . '/100,2))', 's' => 'amount') : array('v' => (float)$l['tax'], 's' => 'amount');
            $income += Money::parse((string)$l['income'])['cents'];
            $tax += Money::parse((string)$l['tax'])['cents'];
            $r++;
        }
        if ($template) {
            // Open lines ready for data: the tax works itself out once the income and rate are typed.
            for ($n = count($lines); $n < 30; $n++, $r++) {
                $cells[$r] = array(1 => array('v' => '', 's' => 'text'), 6 => array('v' => '', 's' => 'amount'), 7 => array('v' => '', 's' => 'amount'),
                    8 => array('f' => 'IF(OR(G' . $r . '="",H' . $r . '=""),"",ROUND(G' . $r . '*H' . $r . '/100,2))', 's' => 'amount'));
            }
        }
        $dash = $r;
        $cells[$dash] = array(6 => str_repeat('-', 18), 7 => str_repeat('-', 18), 8 => str_repeat('-', 18));
        // The sums run from the dashes above the lines to the dashes below, so lines inserted anywhere between are counted.
        $cells[$dash + 1] = array(0 => $b('Grand Total :'),
            6 => $template ? array('f' => 'SUM(G15:G' . $dash . ')', 's' => 'amount_bold') : array('v' => $income / 100, 's' => 'amount_bold'),
            8 => $template ? array('f' => 'SUM(I15:I' . $dash . ')', 's' => 'amount_bold') : array('v' => $tax / 100, 's' => 'amount_bold'));
        $cells[$dash + 2] = array(6 => str_repeat('=', 18), 7 => str_repeat('=', 18), 8 => str_repeat('=', 18));
        $cells[$dash + 3] = array(0 => $b('END OF REPORT'));
        $cols = array();
        foreach (self::templateColumns(self::type('withheld_on_us')) as $c) {
            $cols[] = array('title' => $c['title'], 'width' => $c['width'], 'format' => $c['format'] === 'amount' ? 'amount' : 'general', 'xlsx_format' => $c['format'] === 'amount' ? 'sawt' : null);
        }
        foreach ($cols as $k => $c) {
            if ($c['xlsx_format'] === null) {
                unset($cols[$k]['xlsx_format']);
            }
            if ($k === 1) {
                $cols[$k]['format'] = 'text';
            }
        }
        $sheets = array(array('name' => 'Sheet1', 'columns' => $cols, 'cells' => $cells));
        if ($template) {
            $fieldRows = array();
            foreach (self::templateColumns(self::type('withheld_on_us')) as $c) {
                $fieldRows[] = array($c['title'], !empty($c['required']) ? 'Yes' : 'No', isset($c['help']) ? $c['help'] : '');
            }
            $sheets[] = array('name' => 'Fields', 'columns' => array(array('title' => 'Column', 'width' => 46, 'format' => 'text'), array('title' => 'Required', 'width' => 10, 'format' => 'text'),
                array('title' => 'How to fill', 'width' => 80, 'format' => 'text')), 'rows' => $fieldRows, 'freeze' => true);
            $sheets[] = array('name' => 'About', 'columns' => array(array('title' => 'Key', 'width' => 22, 'format' => 'text'), array('title' => 'Value', 'width' => 90, 'format' => 'text')),
                'rows' => array(array('Template code', 'GSB-SAWT'), array('Template version', '2.0'), array('Import type', 'Taxes withheld on us (SAWT)'), array('Generated', date('Y-m-d H:i')),
                    array('Rules', 'Keep the title lines and the header as they are. One line per payor and ATC, between the two lines of dashes; insert rows there for more payors. TIN with branch code as 000-000-000-0000. A corporation goes under CORPORATION; a person under INDIVIDUAL as LAST NAME, FIRST NAME, MIDDLE NAME. The month is read from the line FOR THE MONTH OF.'),
                    array('Note', 'This workbook is an input template for G-SMaRT BIR in the layout of the SAWT. It is not the SAWT DAT file and is not submitted to the BIR.')));
        }
        return XlsxWriter::build($sheets, array('title' => 'SAWT ' . $form . ' ' . month_label($month)));
    }

    /**
     * Saved taxes withheld on us as SAWT lines: one per payor, ATC and rate for the months given, in the
     * order of the SAWT (by payor name), for the SAWT layout export.
     */
    public static function sawtLines($clientId, $branchId, $fromMonth, $toMonth = null)
    {
        $sql = 'SELECT w.*, cp.tin, cp.branch_code AS cp_branch, cp.entity_type, cp.registered_name, cp.last_name, cp.first_name, cp.middle_name
            FROM withholding_entries w JOIN counterparties cp ON cp.id = w.counterparty_id
            WHERE w.client_id = ? AND w.direction = ? AND w.status = ? AND w.period_month >= ? AND w.period_month <= ?';
        $p = array($clientId, 'payee', 'posted', $fromMonth, $toMonth ?: $fromMonth);
        if ($branchId) {
            $sql .= ' AND w.branch_id = ?';
            $p[] = $branchId;
        }
        $out = array();
        foreach (DB::all($sql . ' ORDER BY COALESCE(cp.registered_name, cp.last_name), cp.first_name, cp.tin, w.atc_code, w.id', $p) as $r) {
            $k = $r['counterparty_id'] . '|' . $r['atc_code'] . '|' . $r['tax_rate'];
            if (!isset($out[$k])) {
                $ind = $r['entity_type'] === 'individual' ? trim($r['last_name'] . ', ' . $r['first_name'] . ($r['middle_name'] ? ', ' . $r['middle_name'] : ''), ', ') : '';
                $out[$k] = array('tin' => $r['tin'] ? Tin::display($r['tin'], $r['cp_branch'] ?: '0000') : '', 'company' => $r['entity_type'] === 'individual' ? '' : (string)$r['registered_name'],
                    'individual' => $ind, 'atc' => $r['atc_code'], 'nature' => (string)$r['nature_of_payment'], 'income_c' => 0, 'tax_c' => 0,
                    'rate' => (float)Money::rateFormat(Money::rateUnits($r['tax_rate']), 'pct2'));
            }
            if ($out[$k]['nature'] === '' && $r['nature_of_payment']) {
                $out[$k]['nature'] = $r['nature_of_payment'];
            }
            $out[$k]['income_c'] += Money::fromDb($r['income_amount']);
            $out[$k]['tax_c'] += Money::fromDb($r['tax_withheld']);
        }
        foreach ($out as &$l) {
            $l['income'] = Money::toPlain($l['income_c']);
            $l['tax'] = Money::toPlain($l['tax_c']);
        }
        unset($l);
        return array_values($out);
    }

    private static function sampleRows($typeKey, $cols)
    {
        $samples = array();
        $base = array();
        foreach ($cols as $c) {
            $base[$c['key']] = isset($c['example']) ? $c['example'] : '';
        }
        if ($typeKey === 'sales') {
            $row = function ($tin, $br, $co, $last, $first, $mid, $a1, $a2, $no, $date, $gross, $ex, $zr, $tx, $rate, $vat) {
                return array('p_tin' => $tin, 'p_branch' => $br, 'p_registered_name' => $co, 'p_last_name' => $last, 'p_first_name' => $first, 'p_middle_name' => $mid,
                    'p_address1' => $a1, 'p_address2' => $a2, 'client_branch' => '0000', 'invoice_no' => $no, 'invoice_date' => $date, 'gross_taxable' => $gross,
                    'exempt_amount' => $ex, 'zero_rated_amount' => $zr, 'taxable_amount' => $tx, 'vat_rate' => $rate, 'output_vat' => $vat);
            };
            $samples[] = $row('123-456-789', '0000', 'SAMPLE TRADING CORPORATION', '', '', '', '12 RIZAL ST BRGY CAPITOL', 'CEBU CITY 6000', 'SI-000101', '01/15/2026', '112000.00', '', '', '', '12', '');
            $samples[] = $row('234-567-890', '0001', 'SAMPLE FOODS INC', '', '', '', '8 OSMENA BLVD', 'CEBU CITY 6000', 'SI-000102', '01/18/2026', '', '', '', '45000.00', '12', '5400.00');
            $samples[] = $row('345-678-901', '0000', '', 'DELA CRUZ', 'JUAN', 'SANTOS', '3 MABINI ST BRGY POBLACION', 'TALISAY CITY 6045', 'SI-000103', '01/20/2026', '', '8500.00', '', '', '', '');
            $samples[] = $row('456-789-012', '0000', 'SAMPLE EXPORTS INC', '', '', '', 'MEPZ 1', 'LAPU-LAPU CITY 6015', 'SI-000104', '01/25/2026', '', '', '250000.00', '', '0', '');
        } elseif ($typeKey === 'withholding') {
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
            $b['schedule_tag'] = '1';
            $samples[] = $b;
        } elseif ($typeKey === 'purchases') {
            foreach (array(
                         array('000123456', 'SAMPLE TELECOM SERVICES INC', '', '', '', '', '', 0, 0, 1695.54, 0, 0),
                         array('000234567', 'SAMPLE PHARMACY CORPORATION', '', '', '', '', '', 0, 0, 0, 0, 421.875),
                         array('000345678', '', 'DELA CRUZ', 'JUAN', 'SANTOS', '12 RIZAL ST', 'CEBU CITY', 1500, 0, 0, 0, 0),
                         array('000456789', 'SAMPLE EQUIPMENT TRADING INC', '', '', '', '', '', 0, 0, 0, 85000, 0),
                     ) as $v) {
                $samples[] = array('p_tin' => $v[0], 'p_registered_name' => $v[1], 'p_last_name' => $v[2], 'p_first_name' => $v[3], 'p_middle_name' => $v[4],
                    'p_address1' => $v[5], 'p_address2' => $v[6], 'exempt_amount' => $v[7], 'zero_rated_amount' => $v[8], 'services_amount' => $v[9],
                    'capital_goods_amount' => $v[10], 'other_goods_amount' => $v[11], 'taxable_net' => '', 'vat_rate' => 12, 'input_vat' => '', 'total_purchases' => '');
            }
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

    /**
     * Column titles of the header. A header may span stacked rows ($from to $headerRow), as in the SAWT
     * where "AMOUNT OF" sits above "INCOME PAYMENT": the pieces of each column are joined with a space.
     */
    public static function headersOf($rows, $headerRow, $from = null)
    {
        $from = $from && $from <= $headerRow ? (int)$from : (int)$headerRow;
        $h = array();
        for ($r = $from; $r <= $headerRow; $r++) {
            if (!isset($rows[$r])) {
                continue;
            }
            foreach ($rows[$r] as $i => $cell) {
                $v = trim(preg_replace('/\s+/', ' ', (string)$cell['v']));
                if ($v !== '') {
                    $h[$i] = isset($h[$i]) ? $h[$i] . ' ' . $v : $v;
                }
            }
        }
        ksort($h);
        return $h;
    }

    // ------------------------------------------------------------------ printed report layouts (SAWT)
    /** What a row of a printed report is: blank, marker ("(1)".."(8)" or a line of dashes), total, end, or data. */
    public static function rowKind($cells)
    {
        $texts = array();
        foreach ($cells as $c) {
            $v = trim((string)$c['v']);
            if ($v !== '') {
                $texts[] = $v;
            }
        }
        if (!$texts) {
            return 'blank';
        }
        $marker = true;
        foreach ($texts as $t) {
            if (preg_match('/^(grand\s*total|sub\s*-?\s*total|totals?)\s*:?$/i', $t)) {
                return 'total';
            }
            if (preg_match('/^\**\s*end\s+of\s+(the\s+)?report\b/i', $t)) {
                return 'end';
            }
            if (!preg_match('/^\(\d{1,2}\)$/', $t) && !preg_match('/^[-=_*~.\s]+$/', $t)) {
                $marker = false;
            }
        }
        return $marker ? 'marker' : 'data';
    }

    private static function isTitleRow($cells)
    {
        $n = 0;
        foreach ($cells as $c) {
            if ($c['num'] && trim((string)$c['v']) !== '') {
                return false;
            }
            if (trim((string)$c['v']) !== '') {
                $n++;
            }
        }
        return $n > 0 && self::rowKind($cells) === 'data';
    }

    /**
     * Find the header in the first 60 rows: the row, or 2 to 4 stacked rows, whose titles match the most
     * fields. Returns array(from, to) or null when nothing matches at least three fields.
     */
    public static function detectHeader($type, $rows)
    {
        $best = null;
        $bestN = 2;
        foreach ($rows as $r => $cells) {
            if ($r > 60) {
                break;
            }
            for ($depth = 1; $depth <= 4; $depth++) {
                $to = $r + $depth - 1;
                if (!isset($rows[$to]) || !self::isTitleRow($rows[$to])) {
                    break;
                }
                list($map) = self::suggestMapping($type, self::headersOf($rows, $to, $r));
                if (count($map) > $bestN) {
                    $bestN = count($map);
                    $best = array($r, $to);
                }
            }
        }
        return $best;
    }

    /** First row of a stacked header that ends on $headerRow: the title rows right above it with two or more cells. */
    public static function headerStart($rows, $headerRow)
    {
        $from = (int)$headerRow;
        for ($i = 1; $i <= 3; $i++) {
            $r = $headerRow - $i;
            if (!isset($rows[$r]) || !self::isTitleRow($rows[$r])) {
                break;
            }
            $n = 0;
            foreach ($rows[$r] as $c) {
                if (trim((string)$c['v']) !== '') {
                    $n++;
                }
            }
            if ($n < 2) {
                break;
            }
            $from = $r;
        }
        return $from;
    }

    /** YYYY-MM of the last "MONTH [day,] YEAR" in a title line, such as "FOR THE MONTH OF MARCH, 2025". */
    public static function monthIn($s)
    {
        $months = array('JAN' => 1, 'FEB' => 2, 'MAR' => 3, 'APR' => 4, 'MAY' => 5, 'JUN' => 6, 'JUL' => 7, 'AUG' => 8, 'SEP' => 9, 'OCT' => 10, 'NOV' => 11, 'DEC' => 12);
        if (preg_match_all('/\b(JAN(?:UARY)?|FEB(?:RUARY)?|MAR(?:CH)?|APR(?:IL)?|MAY|JUNE?|JULY?|AUG(?:UST)?|SEPT?(?:EMBER)?|OCT(?:OBER)?|NOV(?:EMBER)?|DEC(?:EMBER)?)\b\.?\s*(?:\d{1,2}\s*)?,?\s*((?:19|20)\d{2})\b/i', $s, $m, PREG_SET_ORDER)) {
            $last = end($m);
            return sprintf('%04d-%02d', $last[2], $months[strtoupper(substr($last[1], 0, 3))]);
        }
        if (preg_match('/\b(\d{1,2})\s*[\/-]\s*((?:19|20)\d{2})\b/', $s, $x) && (int)$x[1] >= 1 && (int)$x[1] <= 12) {
            return sprintf('%04d-%02d', $x[2], $x[1]);
        }
        return null;
    }

    /**
     * Title lines above the header of a printed report: BIR form, month, the payee's TIN (with branch)
     * and name. Missing items are null.
     */
    public static function reportMeta($rows, $before)
    {
        $m = array('form' => null, 'month' => null, 'tin' => null, 'branch' => null, 'name' => null, 'lines' => array());
        foreach ($rows as $rn => $cells) {
            if ($rn >= $before) {
                break;
            }
            $parts = array();
            foreach ($cells as $c) {
                if (trim((string)$c['v']) !== '') {
                    $parts[] = trim(preg_replace('/\s+/', ' ', (string)$c['v']));
                }
            }
            $line = implode(' ', $parts);
            if ($line === '') {
                continue;
            }
            if (count($m['lines']) < 8) {
                $m['lines'][] = $line;
            }
            if (!$m['form'] && preg_match('/\bFORM\s*(?:NO\.?\s*)?(\d{4}[A-Z]{0,2})\b/i', $line, $x)) {
                $m['form'] = strtoupper($x[1]);
            }
            if (!$m['tin'] && preg_match('/\bTIN\b\s*(?:NO\.?)?\s*:?\s*(\d[\d\- ]{7,19}\d)/i', $line, $x)) {
                $t = Tin::parse(trim($x[1]));
                if ($t['ok']) {
                    $m['tin'] = $t['tin'];
                    $m['branch'] = $t['branch'];
                }
            }
            if (!$m['name'] && preg_match('/(?:PAYEE|TAXPAYER|REGISTERED)\s*\'?\s*S?\s+NAME\s*:\s*(.+)$/i', $line, $x)) {
                $m['name'] = trim($x[1]);
            }
            if (!$m['month'] && preg_match('/\b(FOR\s+THE|MONTH|QUARTER|PERIOD|YEAR\s+ENDED)\b/i', $line)) {
                $m['month'] = self::monthIn($line);
            }
        }
        return $m;
    }

    /** Names compared loosely: case, punctuation and spacing ignored. */
    public static function sameName($a, $b)
    {
        $n = function ($s) {
            return preg_replace('/[^A-Z0-9]/', '', strtoupper((string)$s));
        };
        return $n($a) !== '' && $n($a) === $n($b);
    }

    /**
     * Problems and notes of a SAWT's title lines against the active client. Returns array($problems, $notes,
     * $branchId): a TIN of another taxpayer or an unknown branch code stops the import.
     */
    public static function reportCheck($meta, $client)
    {
        $problems = array();
        $notes = array();
        $branchId = null;
        $clientName = Ctx::clientName($client);
        if ($meta['tin'] && $meta['tin'] !== $client['tin']) {
            $problems[] = 'This SAWT is for TIN ' . Tin::display($meta['tin'], $meta['branch']) . ($meta['name'] ? ' (' . $meta['name'] . ')' : '') . ', but the active client is '
                . $clientName . ', TIN ' . Tin::display($client['tin']) . '. Switch to that client, or upload the SAWT of this one.';
        }
        if ($meta['tin'] && $meta['tin'] === $client['tin'] && $meta['branch'] !== null) {
            $branchId = DB::val('SELECT id FROM branches WHERE client_id = ? AND branch_code = ?', array($client['id'], $meta['branch']));
            if (!$branchId) {
                $problems[] = 'The SAWT is for branch ' . $meta['branch'] . ', which ' . $clientName . ' does not have. Add the branch under the client, then map again.';
            }
        }
        if ($meta['name'] && !self::sameName($meta['name'], $clientName) && !self::sameName($meta['name'], $client['trade_name'])) {
            $notes[] = 'The payee\'s name in the file is ' . $meta['name'] . '; the client is registered as ' . $clientName . '. Check that this is the right SAWT.';
        }
        if (!$meta['tin']) {
            $notes[] = 'The file has no "TIN :" line above the header, so it could not be matched to the client. Check that it belongs to ' . $clientName . '.';
        }
        return array($problems, $notes, $branchId ? (int)$branchId : null);
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
            'taxable_amount' => array('TAXABLESALES', 'TAXABLE', 'NETTAXABLE'), 'output_vat' => array('OUTPUTVAT', 'OUTPUTTAX', 'VAT'), 'input_vat' => array('INPUTVAT', 'INPUTTAX'),
            'other_goods_amount' => array('GOODSOTHERTHANCAPITALGOODS', 'OTHERGOODS', 'GOODS'), 'p_branch' => array('SUPPLIERBRANCHCODE', 'VENDORBRANCH', 'BRANCHCODE'),
            'taxable_net' => array('TAXABLENETOFVAT', 'TAXABLEPURCHASES'), 'total_purchases' => array('TOTALPURCHASES', 'TOTAL'), 'vat_rate' => array('VATRATE', 'RATE'));
        $aliases['p_registered_name'][] = 'REGISTEREDNAME';
        $aliases['p_address1'] = array('ADDRESS1', 'ADDRESS', 'SUBSTREETSTREETBARANGAY');
        $aliases['p_address2'] = array('ADDRESS2', 'DISTRICTCITYZIPCODE', 'CITY');
        $aliases['gross_taxable'] = array('GROSSTAXABLE', 'GROSSTAXABLESALES', 'GROSSSALES', 'VATINCLUSIVE');
        if ($type['key'] === 'sales') {
            $aliases['p_tin'][] = 'CUSTOMERSTIN';
            $aliases['taxable_amount'] = array('TAXABLESALES', 'TAXABLE', 'NETTAXABLE', 'TAXABLESALESNETOFVAT');
        }
        $aliases['p_tin'][] = 'VENDORTIN';
        $aliases['p_tin'][] = 'VENDORSTIN';
        foreach ($type['columns'] as $c) {
            $n = self::normHeader($c['title']);
            if (isset($byNorm[$n]) && !in_array($byNorm[$n], $map, true)) {
                $map[$c['key']] = $byNorm[$n];
                continue;
            }
            if (empty($c['extra'])) {
                $exact = false;
            }
            if (isset($aliases[$c['key']])) {
                foreach ($aliases[$c['key']] as $a) {
                    if (isset($byNorm[$a]) && !in_array($byNorm[$a], $map, true)) {
                        $map[$c['key']] = $byNorm[$a];
                        break;
                    }
                }
            }
        }
        return array($map, $exact && count($headers) === count(self::templateColumns($type)));
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
            $hasName = isset($map['p_registered_name']) && $map['p_registered_name'] !== '' || isset($map['p_last_name']) && $map['p_last_name'] !== ''
                || isset($map['p_individual']) && $map['p_individual'] !== '';
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
        $cols = array();
        foreach (self::type($job['import_type'])['columns'] as $c) {
            $cols[$c['key']] = $c;
        }
        DB::q('DELETE FROM import_rows WHERE job_id = ?', array($jobId));
        $batch = array();
        $count = 0;
        $totals = null;
        foreach ($rows as $rowNo => $cells) {
            if ($rowNo <= (int)$job['header_row']) {
                continue;
            }
            // Printed-report lines are not records: "(1)".."(8)", dashes, Grand Total, END OF REPORT.
            $kind = self::rowKind($cells);
            if ($kind === 'end') {
                break;
            }
            if ($kind === 'blank' || $kind === 'marker') {
                continue;
            }
            if ($kind === 'total') {
                $grand = false;
                foreach ($cells as $c) {
                    if (preg_match('/grand/i', (string)$c['v'])) {
                        $grand = true;
                    }
                }
                if ($totals === null || $grand) {
                    $totals = array('row' => $rowNo, 'values' => array());
                    foreach ($map as $target => $src) {
                        if ($src !== '' && $src !== null && isset($cols[$target]) && $cols[$target]['format'] === 'amount' && isset($cells[(int)$src]) && trim((string)$cells[(int)$src]['v']) !== '') {
                            $totals['values'][$target] = self::excelNumber($cells[(int)$src]['v'], $cells[(int)$src]['num']);
                        }
                    }
                }
                continue;
            }
            $raw = array();
            $numeric = array();
            $formula = array();
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
                if (!empty($cell['f'])) {
                    $formula[] = $target;
                }
                // Formula columns, the VAT rate and zero amounts do not make a row: a template row
                // with only formulas, 12 and zeros is still an empty row.
                $c = isset($cols[$target]) ? $cols[$target] : array();
                $blankish = trim((string)$v) === '' || (!empty($c['format']) && $c['format'] === 'amount' && is_numeric(trim((string)$v)) && (float)$v == 0.0);
                if (!$blankish && empty($c['computed']) && empty($c['weak'])) {
                    $empty = false;
                }
            }
            if ($empty) {
                continue;
            }
            $raw['__numeric'] = $numeric;
            $raw['__formula'] = $formula;
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
        $opts = json_decode((string)$job['options_json'], true) ?: array();
        unset($opts['file_totals'], $opts['invoice_rows']);
        // Invoice numbers used on more than one row of the file, for a warning on each of those rows.
        if (isset($map['invoice_no']) && $map['invoice_no'] !== null && $map['invoice_no'] !== '') {
            $seen = array();
            foreach (DB::all('SELECT row_no, raw_json FROM import_rows WHERE job_id = ?', array($jobId)) as $ir) {
                $rj = json_decode($ir['raw_json'], true);
                $no = isset($rj['invoice_no']) ? strtoupper(trim((string)$rj['invoice_no'])) : '';
                if ($no !== '') {
                    $seen[$no][] = (int)$ir['row_no'];
                }
            }
            foreach ($seen as $no => $list) {
                if (count($list) > 1) {
                    $opts['invoice_rows'][$no] = $list;
                }
            }
        }
        if ($totals && $totals['values']) {
            $opts['file_totals'] = $totals;
        }
        DB::update('import_jobs', array('total_rows' => $count, 'processed_rows' => 0, 'valid_rows' => 0, 'error_rows' => 0, 'duplicate_rows' => 0,
            'options_json' => $opts ? json_encode($opts) : null, 'status' => 'mapped', 'updated_at' => now()), 'id = ?', array($jobId));
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
            $raw['__row'] = (int)$r['row_no'];
            list($norm, $errors, $warnings, $hash) = self::normalizeRow($type, $raw, $job, $opts);
            $status = $errors ? 'error' : 'valid';
            $repeatKey = !empty($type['module']) ? Records::module($type['module']) : array();
            $repeatKey = isset($repeatKey['repeat_key']) ? $repeatKey['repeat_key'] : 'invoice_no';
            if (!$errors && $hash && !empty($type['repeatable']) && empty($norm['record'][$repeatKey])) {
                // Several identical lines (same party, month and amounts, no invoice or certificate number) are
                // separate entries. Number them so the first keeps the plain hash and later ones get their own;
                // importing the same file again still finds every one of them as a duplicate.
                $base = $hash;
                $n = 1;
                while (DB::val('SELECT id FROM import_rows WHERE job_id = ? AND row_hash = ? AND row_no < ? LIMIT 1', array($jobId, $hash, $r['row_no']))) {
                    $n++;
                    $hash = sha1($base . '|' . $n);
                }
                if ($n > 1) {
                    $warnings['repeat'] = 'Same ' . ($type['key'] === 'purchases' ? 'vendor' : 'payor') . ' and amounts as an earlier row; kept as entry ' . $n . ' of these.';
                }
            }
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
        if (!$p['tin'] && !isset($errors['p_tin'])) {
            $warnings['p_tin'] = 'No TIN: the counterparty will be matched by name';
        }
        return $p;
    }

    /**
     * Excel's view of a numeric cell: the value rounded to 15 significant digits, as Excel shows and
     * computes it (so 2.6749999999999998 is 2.675). Text cells pass through unchanged.
     */
    public static function excelNumber($v, $isNumeric)
    {
        $s = trim((string)$v);
        if (!$isNumeric || $s === '' || !is_numeric($s)) {
            return $s;
        }
        $g = sprintf('%.15G', (float)$s);
        if (stripos($g, 'E') !== false) {
            $g = rtrim(rtrim(sprintf('%.12F', (float)$g), '0'), '.');
        }
        return $g;
    }

    /** ROUND(x, 2) the way Excel does it, on a 15-digit value, into centavos. */
    private static function excelRound2($s)
    {
        $p = Money::parse($s);
        return $p['ok'] ? $p['cents'] : null;
    }

    /**
     * R_Purchases rules: the month comes from the upload (the sheet has no date), amounts are rounded the
     * way Excel rounds them, and the three formula columns are checked against the inputs, never trusted.
     */
    private static function purchaseRow($raw, &$in, $opts, &$errors, &$warnings)
    {
        $num = isset($raw['__numeric']) ? $raw['__numeric'] : array();
        $fx = isset($raw['__formula']) ? $raw['__formula'] : array();
        $parts = array('exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount');
        $exact = array();
        foreach ($parts as $k) {
            $v = isset($raw[$k]) ? self::excelNumber($raw[$k], in_array($k, $num, true)) : '';
            $in[$k] = $v;
            $exact[$k] = $v === '' ? 0.0 : (is_numeric(str_replace(',', '', $v)) ? (float)str_replace(',', '', $v) : 0.0);
        }
        if (isset($raw['vat_rate'])) {
            $in['vat_rate'] = self::excelNumber($raw['vat_rate'], in_array('vat_rate', $num, true));
        }
        // The month: an invoice date column when the file has one, otherwise the month chosen at upload.
        if (empty($raw['invoice_date']) && !empty($opts['period_month'])) {
            $in['period_month'] = $opts['period_month'];
        }
        // Excel: taxableNetofVat = ROUND(SUM(J:L),2) and the amounts before VAT ROUND(SUM(H:L),2), on unrounded
        // cells; totalPurchases adds the input VAT to them (checked further down, once the VAT is known).
        $taxable = self::excelRound2(self::excelNumber($exact['services_amount'] + $exact['capital_goods_amount'] + $exact['other_goods_amount'], true));
        $total = self::excelRound2(self::excelNumber($exact['exempt_amount'] + $exact['zero_rated_amount'] + $exact['services_amount'] + $exact['capital_goods_amount'] + $exact['other_goods_amount'], true));
        // Store centavos that reproduce the sheet's own rounded totals: when rounding each amount on its
        // own drifts from ROUND(SUM()) by a centavo, move that centavo to the amount nearest the boundary.
        if ($taxable !== null && $total !== null) {
            $moved = self::allocateRounding($in, $exact, array('services_amount', 'capital_goods_amount', 'other_goods_amount'), $taxable);
            $moved += self::allocateRounding($in, $exact, array('exempt_amount', 'zero_rated_amount'), $total - $taxable);
            if ($moved) {
                $warnings['taxable_net'] = 'Amounts with more than two decimals were rounded so they add up to the sheet\'s taxableNetofVat ' . Money::fmt($taxable) . '.';
            }
        }
        foreach (array('taxable_net' => array($taxable, 'taxableNetofVat', 'services + capitalGoods + otherThancapitalGoods')) as $k => $c) {
            if (!isset($raw[$k]) || trim((string)$raw[$k]) === '') {
                continue;
            }
            $given = self::excelRound2(self::excelNumber($raw[$k], in_array($k, $num, true)));
            if ($given === null) {
                $errors[$k] = $c[1] . ' is not a number';
            } elseif ($given !== $c[0]) {
                $errors[$k] = $c[1] . ' is ' . Money::fmt($given) . ' but ' . $c[2] . ' is ' . Money::fmt($c[0]) . '. Fix the row so they agree.';
            }
        }
        // inputVat: a formula is recomputed from the rate; a typed amount is kept and checked against it.
        $rateBlank = !isset($in['vat_rate']) || trim((string)$in['vat_rate']) === '';
        if ($rateBlank && isset($raw['input_vat']) && trim((string)$raw['input_vat']) !== '' && in_array('input_vat', $fx, true) && $taxable) {
            $errors['vat_rate'] = 'vatRate is blank, so the sheet computed inputVat as ' . Money::fmt((int)self::excelRound2(self::excelNumber($raw['input_vat'], true))) . '. Enter 12, or 0 for purchases without VAT.';
            $in['input_vat'] = '';
            return;
        }
        $rateUnits = Money::rateUnits(isset($in['vat_rate']) && trim((string)$in['vat_rate']) !== '' ? $in['vat_rate'] : '12');
        if ($rateUnits !== null && $taxable !== null) {
            $vat = Money::applyRate($taxable, $rateUnits);
            $hasVat = isset($raw['input_vat']) && trim((string)$raw['input_vat']) !== '';
            if (!$hasVat || in_array('input_vat', $fx, true)) {
                if ($hasVat) {
                    $cached = self::excelRound2(self::excelNumber($raw['input_vat'], in_array('input_vat', $num, true)));
                    if ($cached !== null && $cached !== $vat) {
                        $errors['input_vat'] = 'inputVat in the file is ' . Money::fmt($cached) . ' but taxableNetofVat x vatRate / 100 is ' . Money::fmt($vat) . '.';
                    }
                }
                $in['input_vat'] = Money::toPlain($vat);
            } else {
                $in['input_vat'] = self::excelNumber($raw['input_vat'], in_array('input_vat', $num, true));
            }
        }
        // totalPurchases = exempt + zeroRated + (services + capitalGoods + otherThancapitalGoods) + inputVat.
        if ($total !== null && isset($raw['total_purchases']) && trim((string)$raw['total_purchases']) !== '') {
            $vatC = Money::parse(isset($in['input_vat']) ? (string)$in['input_vat'] : '');
            $vatC = $vatC['ok'] && !$vatC['blank'] ? $vatC['cents'] : 0;
            $given = self::excelRound2(self::excelNumber($raw['total_purchases'], in_array('total_purchases', $num, true)));
            if ($given === null) {
                $errors['total_purchases'] = 'totalPurchases is not a number';
            } elseif ($given === $total && $vatC !== 0) {
                // A workbook made with the earlier formula, which left the VAT out of the total.
                $warnings['total_purchases'] = 'totalPurchases ' . Money::fmt($given) . ' leaves out the input VAT (the earlier formula). The total is now ' . Money::fmt($total + $vatC) . ', VAT included.';
            } elseif ($given !== $total + $vatC) {
                $errors['total_purchases'] = 'totalPurchases is ' . Money::fmt($given) . ' but exempt + zeroRated + services + capitalGoods + otherThancapitalGoods + inputVat is '
                    . Money::fmt($total + $vatC) . '. Fix the row so they agree.';
            }
        }
    }

    /**
     * Round $keys to centavos so they add up to $target, moving the leftover centavos to the amounts whose
     * rounding was closest to the half (largest remainder). Returns how many centavos moved.
     */
    private static function allocateRounding(&$in, $exact, $keys, $target)
    {
        $cents = array();
        $sum = 0;
        foreach ($keys as $k) {
            $cents[$k] = (int)self::excelRound2($in[$k] === '' ? '0' : $in[$k]);
            $sum += $cents[$k];
        }
        $diff = $target - $sum;
        if ($diff === 0 || abs($diff) > count($keys)) {
            return 0;
        }
        // Order by how far each rounded amount is from its exact value, in the direction we need to move.
        $order = $keys;
        usort($order, function ($a, $b) use ($exact, $cents, $diff) {
            $da = ($exact[$a] * 100 - $cents[$a]) * ($diff > 0 ? 1 : -1);
            $db = ($exact[$b] * 100 - $cents[$b]) * ($diff > 0 ? 1 : -1);
            return $da < $db ? 1 : ($da > $db ? -1 : 0);
        });
        $step = $diff > 0 ? 1 : -1;
        for ($i = 0; $i < abs($diff); $i++) {
            $k = $order[$i];
            $cents[$k] += $step;
            $in[$k] = Money::toPlain($cents[$k]);
        }
        return abs($diff);
    }

    /**
     * SAWT payor columns: CORPORATION (Registered Name) for a non-individual, or INDIVIDUAL written as
     * "LAST NAME, FIRST NAME, MIDDLE NAME". Report padding (cells of spaces) is blank.
     */
    private static function sawtParty(&$raw, &$errors, &$warnings)
    {
        foreach (array('p_registered_name', 'p_individual', 'p_last_name', 'p_first_name', 'p_middle_name') as $k) {
            if (isset($raw[$k])) {
                $raw[$k] = trim(preg_replace('/\s+/', ' ', (string)$raw[$k]));
            }
        }
        $ind = isset($raw['p_individual']) ? $raw['p_individual'] : '';
        if ($ind === '') {
            return;
        }
        if (!empty($raw['p_registered_name'])) {
            $warnings['p_individual'] = 'Both CORPORATION and INDIVIDUAL are filled; the line is kept under the corporation, ' . $raw['p_registered_name'] . '.';
            return;
        }
        if (!empty($raw['p_last_name'])) {
            return;
        }
        $parts = array_map('trim', explode(',', $ind));
        if (count($parts) < 2 || $parts[0] === '' || $parts[1] === '') {
            $errors['p_individual'] = 'Write the individual payor as LAST NAME, FIRST NAME, MIDDLE NAME (found "' . $ind . '")';
            return;
        }
        $raw['p_last_name'] = $parts[0];
        $raw['p_first_name'] = $parts[1];
        $raw['p_middle_name'] = isset($parts[2]) ? implode(' ', array_slice($parts, 2)) : '';
    }

    /**
     * SAWT amounts: the income payment and tax withheld are taken as the payor certified them (rounded to
     * centavos the way Excel shows them); the rate is in percent. The month comes from the title line
     * "FOR THE MONTH OF ..." or the month chosen at upload, unless a date column is mapped.
     */
    private static function sawtRow($raw, &$in, $opts, &$errors, &$warnings)
    {
        $num = isset($raw['__numeric']) ? $raw['__numeric'] : array();
        foreach (array('income_amount', 'tax_rate', 'tax_withheld') as $k) {
            if (isset($raw[$k])) {
                $in[$k] = self::excelNumber($raw[$k], in_array($k, $num, true));
            }
        }
        if (isset($raw['nature_of_payment'])) {
            $in['nature_of_payment'] = trim(preg_replace('/\s+/', ' ', (string)$raw['nature_of_payment']));
        }
        if (isset($raw['atc_code'])) {
            $in['atc_code'] = strtoupper(preg_replace('/\s+/', '', (string)$raw['atc_code']));
        }
        if (empty($raw['payment_date']) && !empty($opts['period_month'])) {
            $in['period_month'] = $opts['period_month'];
        }
        // The SEQ NO column is the report's line number; it is not stored.
        unset($in['seq_no'], $in['p_individual']);
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
        if ($type['key'] === 'withheld_on_us') {
            self::sawtParty($raw, $errors, $warnings);
        }
        $p = self::partyFrom($raw, $errors, $warnings);
        $in = $raw;
        $in['branch_id'] = $branchId;
        if ($type['key'] === 'purchases') {
            self::purchaseRow($raw, $in, $opts, $errors, $warnings);
        }
        if ($type['key'] === 'withheld_on_us') {
            self::sawtRow($raw, $in, $opts, $errors, $warnings);
        }
        if ($type['key'] === 'sales') {
            // Amounts as Excel shows them (15 significant digits); GROSS TAXABLE is checked and split by the sales rules.
            $num = isset($raw['__numeric']) ? $raw['__numeric'] : array();
            foreach (array('gross_taxable', 'exempt_amount', 'zero_rated_amount', 'taxable_amount', 'vat_rate', 'output_vat') as $k) {
                if (isset($raw[$k])) {
                    $in[$k] = self::excelNumber($raw[$k], in_array($k, $num, true));
                }
            }
        }
        if ($type['key'] === 'withholding') {
            $in['tax_kind'] = isset($raw['tax_kind']) && trim($raw['tax_kind']) !== '' ? strtoupper(trim($raw['tax_kind'])) : 'EWT';
            // SCHEDULE is 1, 2 or 3 in the file (A, B or C in older files); blank is schedule 1.
            $in['schedule_tag'] = isset($raw['schedule_tag']) && trim((string)$raw['schedule_tag']) !== '' ? strtoupper(trim((string)$raw['schedule_tag'])) : '1';
            if (isset($raw['tax_rate'])) {
                $in['tax_rate'] = self::excelNumber($raw['tax_rate'], in_array('tax_rate', isset($raw['__numeric']) ? $raw['__numeric'] : array(), true));
            }
        }
        list($d, $e2, $w2) = Records::validate($mod, $in, $clientId, array('skip_party' => true, 'skip_scope' => true, 'date_format' => $dateFmt, 'party_entity_type' => $p['entity_type']));
        if ($type['key'] === 'withheld_on_us' && isset($e2['period_month']) && empty($in['period_month']) && empty($raw['payment_date'])) {
            $e2['period_month'] = 'Choose the month for this import: the file has no "FOR THE MONTH OF" line and no date column';
        }
        if ($type['key'] === 'purchases') {
            if (isset($e2['period_month']) && empty($in['period_month']) && empty($raw['invoice_date'])) {
                $e2['period_month'] = 'Choose the purchase month for this import: the sheet has no date column';
            }
            // Amounts net of VAT carry long decimals in this sheet; its own formulas round them, and so do we.
            foreach (array('exempt_amount', 'zero_rated_amount', 'services_amount', 'capital_goods_amount', 'other_goods_amount') as $k) {
                if (isset($w2[$k]) && strpos($w2[$k], 'more than 2 decimals') !== false) {
                    unset($w2[$k]);
                }
            }
        }
        $errors = array_merge($errors, $e2);
        $warnings = array_merge($warnings, $w2);
        if ($type['key'] === 'sales' && !empty($d['invoice_no'])) {
            $no = strtoupper(trim($d['invoice_no']));
            if (!empty($opts['invoice_rows'][$no]) && isset($raw['__row'])) {
                $others = array_values(array_diff($opts['invoice_rows'][$no], array((int)$raw['__row'])));
                if ($others) {
                    $warnings['invoice_no'] = 'Invoice no. ' . $d['invoice_no'] . ' is also on row' . (count($others) > 1 ? 's ' : ' ') . implode(', ', array_slice($others, 0, 8)) . ' of this file. Each sales invoice should have its own number.';
                }
            }
            if (!isset($warnings['invoice_no']) && DB::val('SELECT id FROM sales_entries WHERE client_id = ? AND invoice_no = ? AND status = ? LIMIT 1', array($clientId, $d['invoice_no'], 'posted'))) {
                $warnings['invoice_no'] = 'Invoice no. ' . $d['invoice_no'] . ' is already on a saved sale of this client. Check that it is not entered twice.';
            }
        }
        if (!empty($d['period_month']) && Records::lockedPeriod($clientId, $d['period_month'], $mod['lock_scope'])) {
            $errors['period'] = 'Period ' . month_label($d['period_month']) . ' is locked';
        }
        $hashData = $d;
        $hashData['counterparty_id'] = Records::partyKey($p['tin'], $p['branch_code'], $p['registered_name'], $p['last_name'], $p['first_name']);
        $mod['key'] = $type['module'];
        $hash = Records::rowHash($mod, $hashData);
        return array(array('party' => $p, 'record' => $d), $errors, $warnings, $hash);
    }

    // ------------------------------------------------------------------ explaining problems
    /**
     * Where each message points in the file: field key => array(title, column letter or null).
     * Messages about record fields that came from another column point at that column.
     */
    public static function fieldColumns($job)
    {
        $type = self::type($job['import_type']);
        $map = json_decode((string)$job['mapping_json'], true) ?: array();
        $out = array();
        foreach ($type['columns'] as $c) {
            $src = isset($map[$c['key']]) ? $map[$c['key']] : null;
            $out[$c['key']] = array('title' => $c['title'], 'col' => $src === null || $src === '' ? null : XlsxWriter::colLetter((int)$src));
        }
        $same = array('branch_id' => 'client_branch', 'counterparty_id' => 'p_tin', 'p_tin_branch' => 'p_branch');
        foreach ($same as $k => $to) {
            if (!isset($out[$k]) && isset($out[$to])) {
                $out[$k] = $out[$to];
            }
        }
        if (!isset($out['period_month'])) {
            $date = isset($out['invoice_date']) ? 'invoice_date' : (isset($out['payment_date']) ? 'payment_date' : null);
            $out['period_month'] = $date ? $out[$date] : array('title' => 'Month', 'col' => null);
        }
        if (!isset($out['period'])) {
            $out['period'] = $out['period_month'];
        }
        return $out;
    }

    /**
     * One message, located: array('ref' => 'L2' or '', 'title' => 'GROSS TAXABLE' or '', 'value' => what the cell held,
     * 'text' => the message). $raw is the row as read from the file.
     */
    public static function locate($cols, $key, $rowNo, $raw, $text)
    {
        $c = isset($cols[$key]) ? $cols[$key] : null;
        $value = '';
        if ($c && isset($raw[$key]) && !is_array($raw[$key])) {
            $value = trim(preg_replace('/\s+/', ' ', (string)$raw[$key]));
            if (in_array($key, isset($raw['__numeric']) ? $raw['__numeric'] : array(), true) && is_numeric($value)) {
                $value = self::excelNumber($value, true);
            }
        }
        return array('ref' => $c && $c['col'] ? $c['col'] . $rowNo : '', 'title' => $c ? $c['title'] : '', 'value' => $value, 'text' => $text);
    }

    /**
     * Everything to correct in a staged import, grouped by column: for each column and kind ('error' or
     * 'warning'), how many rows, which rows and a sample message. Empty when the file is clean.
     */
    public static function problemSummary($job)
    {
        $cols = self::fieldColumns($job);
        $groups = array();
        foreach (DB::all('SELECT row_no, status, errors_json, warnings_json FROM import_rows WHERE job_id = ? AND (errors_json IS NOT NULL OR warnings_json IS NOT NULL) ORDER BY row_no', array($job['id'])) as $r) {
            foreach (array('error' => $r['errors_json'], 'warning' => $r['warnings_json']) as $kind => $json) {
                foreach (json_decode((string)$json, true) ?: array() as $key => $msg) {
                    if ($kind === 'warning' && in_array($key, array('dup', 'repeat'), true)) {
                        continue;
                    }
                    // Messages that differ only in their amounts belong to the same problem.
                    $shape = preg_replace('/-?[\d,]+(\.\d+)?/', '#', $msg);
                    $g = $kind . '|' . $key . '|' . md5($shape);
                    if (!isset($groups[$g])) {
                        $c = isset($cols[$key]) ? $cols[$key] : array('title' => '', 'col' => null);
                        $groups[$g] = array('kind' => $kind, 'key' => $key, 'title' => $c['title'], 'col' => $c['col'], 'rows' => array(), 'sample' => $msg);
                    }
                    $groups[$g]['rows'][] = (int)$r['row_no'];
                }
            }
        }
        uasort($groups, function ($a, $b) {
            if ($a['kind'] !== $b['kind']) {
                return $a['kind'] === 'error' ? -1 : 1;
            }
            return count($b['rows']) - count($a['rows']);
        });
        return array_values($groups);
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
                    $rec['row_hash'] = $r['row_hash'];
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
        $cellCols = self::fieldColumns($job);
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
            $located = array();
            foreach ($errs as $k => $m) {
                $l = self::locate($cellCols, $k, (int)$r['row_no'], $raw, $m);
                $located[] = ($l['ref'] ? $l['ref'] . ' ' : '') . ($l['title'] ? $l['title'] . ': ' : '') . $m;
            }
            $row[] = implode(' | ', $located);
            $row[] = (string)$r['row_no'];
            $out[] = $row;
        }
        return XlsxWriter::build(array(array('name' => 'Data', 'columns' => $cols, 'rows' => $out, 'freeze' => true)), array('title' => 'Corrections'));
    }
}
