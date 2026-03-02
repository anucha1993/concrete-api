<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE products MODIFY COLUMN side_steel_type ENUM('HIDE','SHOW','NONE') NOT NULL DEFAULT 'NONE' COMMENT 'HIDE=ไม่แสดงเหล็กข้าง, SHOW=แสดงเหล็กข้าง, NONE=ไม่ระบุ'");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE products MODIFY COLUMN side_steel_type ENUM('HIDE','SHOW') NOT NULL DEFAULT 'HIDE' COMMENT 'HIDE=ไม่แสดงเหล็กข้าง, SHOW=แสดงเหล็กข้าง'");
    }
};
