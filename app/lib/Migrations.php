<?php
/**
 * Small, ordered schema updates for installations made from an earlier SQL file.
 * Each step runs once; the reached version is kept in system_config as schema_version.
 * The same statements are in database/migrations/ for anyone who prefers to run them by hand.
 */
class Migrations
{
    const CURRENT = 4;

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
        } catch (Exception $e) {
            // Without ALTER rights the update waits for database/migrations/; check.php reports it.
            error_log('[GSB] Schema update to version ' . self::CURRENT . ' failed: ' . $e->getMessage());
        }
    }
}
