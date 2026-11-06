<?php
/**
 * Small, ordered schema updates for installations made from an earlier SQL file.
 * Each step runs once; the reached version is kept in system_config as schema_version.
 * The same statements are in database/migrations/ for anyone who prefers to run them by hand.
 */
class Migrations
{
    const CURRENT = 8;

    public static function run()
    {
        $v = (int)sys_config('schema_version', '1');
        if ($v >= self::CURRENT) {
            return;
        }
        try {
            if ($v < 2) {
                // 2026-10-25: purchases in the R_Purchases format (per-row VAT rate, optional invoice date).
                $has = (int)DB::val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'purchase_entries' AND column_name = 'vat_rate'");
                if (!$has) {
                    DB::q('ALTER TABLE purchase_entries MODIFY invoice_date DATE NULL, ADD COLUMN vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000 AFTER other_goods_amount');
                    DB::q('UPDATE purchase_entries SET vat_rate = 0 WHERE input_vat = 0 AND (services_amount + capital_goods_amount + other_goods_amount) <> 0');
                }
                set_sys_config('schema_version', '2');
            }
            if ($v < 3) {
                // 2026-10-25: taxes withheld on us in the SAWT layout. A SAWT line belongs to a month and
                // usually has no payment date; the BIR's nature-of-payment texts run past 150 characters.
                $nullable = DB::val("SELECT is_nullable FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'withholding_entries' AND column_name = 'payment_date'");
                if ($nullable === 'NO') {
                    DB::q('ALTER TABLE withholding_entries MODIFY payment_date DATE NULL, MODIFY nature_of_payment VARCHAR(255) NULL');
                }
                set_sys_config('schema_version', '3');
            }
            if ($v < 4) {
                // 2026-10-25: sales in the VAT sales workbook format (per-row VAT rate; gross taxable is worked out).
                $has = (int)DB::val("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'sales_entries' AND column_name = 'vat_rate'");
                if (!$has) {
                    DB::q('ALTER TABLE sales_entries ADD COLUMN vat_rate DECIMAL(9,4) NOT NULL DEFAULT 12.0000 AFTER taxable_amount');
                    DB::q('UPDATE sales_entries SET vat_rate = 0 WHERE output_vat = 0 AND taxable_amount <> 0');
                }
                set_sys_config('schema_version', '4');
            }
            if ($v < 5) {
                // 2026-11-03: 1604-C in the format of the BIR Alphalist Data Entry and Validation module v7.4
                // (actual amount withheld in Schedule 1; the module's 59-field Schedule 2 line).
                Specs::update1604cLayouts();
                set_sys_config('schema_version', '5');
            }
            if ($v < 6) {
                // 2026-11-05: SAWT as the BIR Alphalist module v7.4 writes it, for 1702Q, 2550Q and every other
                // attached return; the VAT and percentage tax withholding codes the 2550Q and 2551Q SAWT need.
                Specs::updateSawtLayouts();
                self::addTaxCodes(array('GVAT', 'GPT'));
                set_sys_config('schema_version', '6');
            }
            if ($v < 7) {
                // 2026-11-05: QAP (1601-EQ, 1601-FQ), MAP (1600-VT, 1600-PT), 1604-E and 1604-F as the BIR Alphalist
                // module v7.4 writes them, the schedules of a return in one file. The 1604-E Schedule 3 is the
                // expanded withholding of the year's QAP Schedule 1, and Schedule 4 the payees exempt from it.
                Specs::updateV74WithholdingLayouts();
                self::updateSchedules();
                self::addTaxCodes(array('FWT'));
                set_sys_config('schema_version', '7');
            }
            if ($v < 8) {
                // 2026-11-06: new clients uploaded from a CSV file, held for review until they are saved.
                foreach (self::sqlStatements(dirname(APP_PATH) . '/database/migrations/2026-11-06_client_upload.sql') as $sql) {
                    if (stripos($sql, 'CREATE TABLE') === 0) {
                        DB::q($sql);
                    }
                }
                set_sys_config('schema_version', '8');
            }
        } catch (Exception $e) {
            // Without ALTER rights the update waits for database/migrations/; check.php reports it.
            error_log('[GSB] Schema update to version ' . self::CURRENT . ' failed: ' . $e->getMessage());
        }
    }

    /** The statements of a migration file, without its comment lines. */
    public static function sqlStatements($path)
    {
        $sql = preg_replace('/^\s*--.*$/m', '', (string)@file_get_contents($path));
        $out = array();
        foreach (explode(';', $sql) as $st) {
            $st = trim($st);
            if ($st !== '') {
                $out[] = $st;
            }
        }
        return $out;
    }

    /** Bring the names and data sources of the report schedules in line with the reference data. */
    public static function updateSchedules()
    {
        require_once APP_PATH . '/specs/reference.php';
        $R = gsb_reference();
        foreach ($R['schedules'] as $s) {
            DB::q('UPDATE report_schedules SET name = ?, dataset_key = ? WHERE form_code = ? AND schedule_code = ?', array($s[2], $s[3], $s[0], $s[1]));
        }
    }

    /** Seed tax codes of the given kinds that an installation does not have yet (pending review, like at install). */
    public static function addTaxCodes($kinds)
    {
        require_once APP_PATH . '/specs/reference.php';
        $R = gsb_reference();
        $n = 0;
        foreach ($R['tax_codes'] as $t) {
            if (!in_array($t[1], $kinds, true) || DB::val('SELECT id FROM tax_codes WHERE atc_code = ? AND tax_kind = ?', array($t[0], $t[1]))) {
                continue;
            }
            DB::insert('tax_codes', array('atc_code' => $t[0], 'tax_kind' => $t[1], 'payee_type' => $t[2], 'description' => $t[3], 'rate' => $t[4],
                'effective_from' => '2018-01-01', 'effective_to' => null, 'version_tag' => 'BIR-v7.4-ATC', 'source_ref' => $t[5], 'status' => 'pending_review',
                'notes' => 'Added from the ATC table of the BIR Alphalist module v7.4. Approve after checking the ATC table of the installed validator.', 'created_at' => now()));
            $n++;
        }
        return $n;
    }
}
