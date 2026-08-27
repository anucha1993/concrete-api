<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class ClearStock extends Command
{
    protected $signature = 'stock:clear
        {--dry-run : แสดงแผนการลบเท่านั้น ไม่ลบข้อมูลจริง}
        {--force : ข้ามการยืนยัน (ใช้กับสคริปต์อัตโนมัติ)}';

    protected $description = 'ล้างข้อมูลสต๊อกและใบสั่งผลิตทั้งหมด (reset ระบบใหม่) — ไม่แตะข้อมูลหลัก เช่น สินค้า/หมวดหมู่/คลัง/แพ/ผู้ใช้';

    /**
     * ตารางที่จะถูกล้าง เรียงจากตารางลูก -> ตารางแม่
     * (ตารางข้อมูลหลัก เช่น products, categories, locations, packs, users, label_templates, pda_tokens จะไม่ถูกแตะ)
     *
     * @var array<int, array{0: string, 1: string}>
     */
    private array $tables = [
        ['inventory_movements',            'ประวัติการเคลื่อนไหวสต๊อก'],
        ['production_serials',             'Serial ที่ผูกกับใบสั่งผลิต'],
        ['label_print_logs',               'ประวัติการปริ้น label'],
        ['label_reprint_request_items',    'รายการ serial ในคำขอปริ้นซ้ำ'],
        ['label_reprint_requests',         'คำขอปริ้น label ซ้ำ'],
        ['stock_count_scans',              'log สแกนตรวจนับ (PDA)'],
        ['stock_count_serial_resolutions', 'ผลตัดสินใจ serial ตอนตรวจนับ'],
        ['stock_count_items',              'รายการสินค้าที่คาดหวัง (ตรวจนับ)'],
        ['stock_counts',                   'รอบตรวจนับสต๊อก'],
        ['stock_deduction_scans',          'log สแกนตัดสต๊อก (PDA)'],
        ['stock_deduction_lines',          'รายการวางแผนตัดสต๊อก'],
        ['claim_lines',                    'รายการสินค้าเคลม'],
        ['claims',                         'ใบเคลมสินค้า'],
        ['stock_deductions',               'ใบตัดสต๊อก'],
        ['production_order_items',          'รายการในใบสั่งผลิต'],
        ['production_orders',              'ใบสั่งผลิต'],
        ['inventories',                    'สินค้าคงคลัง (serial)'],
        ['serial_counters',                'ตัวนับ running number ของ serial (numbering จะเริ่มใหม่)'],
    ];

    public function handle(): int
    {
        $this->info('=== แผนการล้างข้อมูลสต๊อก (stock:clear) ===');

        // ── 1) สร้างแผน: นับจำนวนแถวของแต่ละตาราง ──
        $rows = [];
        $total = 0;
        foreach ($this->tables as [$table, $label]) {
            $count = DB::table($table)->count();
            $total += $count;
            $rows[] = [$table, $label, number_format($count)];
        }

        $this->table(['ตาราง', 'รายละเอียด', 'จำนวนแถว'], $rows);
        $this->line('รวมทั้งหมด: <fg=yellow>' . number_format($total) . '</> แถว จาก ' . count($this->tables) . ' ตาราง');
        $this->newLine();
        $this->line('<fg=green>คงไว้ (ไม่ลบ):</> products, categories, locations, packs, pack_items, users, roles, permissions, label_templates, pda_tokens');

        // ── 2) โหมด dry-run: แสดงแผนแล้วจบ ──
        if ($this->option('dry-run')) {
            $this->newLine();
            $this->info('โหมด --dry-run: แสดงแผนเท่านั้น ยังไม่มีการลบข้อมูล');
            return self::SUCCESS;
        }

        if ($total === 0) {
            $this->newLine();
            $this->info('ไม่มีข้อมูลสต๊อกให้ล้าง (ทุกตารางว่างอยู่แล้ว)');
            return self::SUCCESS;
        }

        // ── 3) ยืนยันก่อนลบ (ข้ามได้ด้วย --force) ──
        if (!$this->option('force')) {
            $this->newLine();
            $this->warn('คำเตือน: การลบนี้ไม่สามารถกู้คืนได้ ควรสำรองฐานข้อมูล (backup) ก่อนดำเนินการ');

            if (!$this->confirm('ยืนยันจะล้างข้อมูลสต๊อกและใบสั่งผลิตทั้งหมดใช่ไหม?', false)) {
                $this->info('ยกเลิกแล้ว — ไม่มีการลบข้อมูล');
                return self::SUCCESS;
            }

            if ($this->ask('พิมพ์คำว่า CLEAR เพื่อยืนยันอีกครั้ง') !== 'CLEAR') {
                $this->info('ยกเลิกแล้ว — คำยืนยันไม่ถูกต้อง ไม่มีการลบข้อมูล');
                return self::SUCCESS;
            }
        }

        // ── 4) ลบข้อมูล: ปิด FK checks -> truncate -> เปิด FK checks ──
        $this->newLine();
        $this->info('กำลังล้างข้อมูล...');

        Schema::disableForeignKeyConstraints();
        try {
            foreach ($this->tables as [$table, $label]) {
                DB::table($table)->truncate();
                $this->line("  - ล้าง <fg=cyan>{$table}</> เรียบร้อย");
            }
        } finally {
            Schema::enableForeignKeyConstraints();
        }

        $this->newLine();
        $this->info('เสร็จสิ้น: ล้างข้อมูลสต๊อกและใบสั่งผลิตทั้งหมดแล้ว ระบบพร้อมเริ่มต้นใหม่');

        return self::SUCCESS;
    }
}
