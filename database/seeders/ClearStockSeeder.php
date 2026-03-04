<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ลบข้อมูลสต๊อกสินค้าทั้งหมด (Inventory, Production Orders, Stock Deductions, Claims, Stock Counts, Labels)
 * โดยคงข้อมูล Master Data ไว้ (Products, Packs, Categories, Locations, Users, Roles, Permissions, Label Templates)
 *
 * ใช้คำสั่ง: php artisan db:seed --class=ClearStockSeeder
 */
class ClearStockSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->warn('⚠️  กำลังลบข้อมูลสต๊อกสินค้าทั้งหมด...');

        Schema::disableForeignKeyConstraints();

        // ── 1. Label & Reprint ──
        DB::table('label_print_logs')->truncate();
        $this->command->info('  ✓ label_print_logs');

        DB::table('label_reprint_request_items')->truncate();
        $this->command->info('  ✓ label_reprint_request_items');

        DB::table('label_reprint_requests')->truncate();
        $this->command->info('  ✓ label_reprint_requests');

        // ── 2. PDA Tokens ──
        DB::table('pda_tokens')->truncate();
        $this->command->info('  ✓ pda_tokens');

        // ── 3. Stock Counts ──
        DB::table('stock_count_serial_resolutions')->truncate();
        $this->command->info('  ✓ stock_count_serial_resolutions');

        DB::table('stock_count_scans')->truncate();
        $this->command->info('  ✓ stock_count_scans');

        DB::table('stock_count_items')->truncate();
        $this->command->info('  ✓ stock_count_items');

        DB::table('stock_counts')->truncate();
        $this->command->info('  ✓ stock_counts');

        // ── 4. Claims ──
        DB::table('claim_lines')->truncate();
        $this->command->info('  ✓ claim_lines');

        DB::table('claims')->truncate();
        $this->command->info('  ✓ claims');

        // ── 5. Stock Deductions ──
        DB::table('stock_deduction_scans')->truncate();
        $this->command->info('  ✓ stock_deduction_scans');

        DB::table('stock_deduction_lines')->truncate();
        $this->command->info('  ✓ stock_deduction_lines');

        DB::table('stock_deductions')->truncate();
        $this->command->info('  ✓ stock_deductions');

        // ── 6. Inventory & Movements ──
        DB::table('inventory_movements')->truncate();
        $this->command->info('  ✓ inventory_movements');

        DB::table('inventories')->truncate();
        $this->command->info('  ✓ inventories');

        // ── 7. Production ──
        DB::table('production_serials')->truncate();
        $this->command->info('  ✓ production_serials');

        DB::table('production_order_items')->truncate();
        $this->command->info('  ✓ production_order_items');

        DB::table('production_orders')->truncate();
        $this->command->info('  ✓ production_orders');

        // ── 8. Serial Counters (รีเซ็ตตัวนับ) ──
        DB::table('serial_counters')->truncate();
        $this->command->info('  ✓ serial_counters');

        Schema::enableForeignKeyConstraints();

        $this->command->newLine();
        $this->command->info('✅ ลบข้อมูลสต๊อกสินค้าทั้งหมดเรียบร้อย!');
        $this->command->info('   (คงเหลือ: Products, Packs, Categories, Locations, Users, Roles, Permissions, Label Templates)');
    }
}
