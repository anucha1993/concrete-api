<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Add PENDING to inventories.status enum and convert existing RESERVED → PENDING.
     */
    public function up(): void
    {
        // 1) Alter enum: add PENDING, remove RESERVED
        DB::statement("ALTER TABLE inventories MODIFY COLUMN status ENUM('PENDING','IN_STOCK','RESERVED','SOLD','DAMAGED','SCRAPPED') NOT NULL DEFAULT 'PENDING'");

        // 2) Convert all existing RESERVED → PENDING
        DB::table('inventories')->where('status', 'RESERVED')->update(['status' => 'PENDING']);

        // 3) Now remove RESERVED from the enum
        DB::statement("ALTER TABLE inventories MODIFY COLUMN status ENUM('PENDING','IN_STOCK','SOLD','DAMAGED','SCRAPPED') NOT NULL DEFAULT 'PENDING'");
    }

    /**
     * Reverse: restore RESERVED, convert PENDING → RESERVED, remove PENDING.
     */
    public function down(): void
    {
        DB::statement("ALTER TABLE inventories MODIFY COLUMN status ENUM('PENDING','IN_STOCK','RESERVED','SOLD','DAMAGED','SCRAPPED') NOT NULL DEFAULT 'IN_STOCK'");
        DB::table('inventories')->where('status', 'PENDING')->update(['status' => 'RESERVED']);
        DB::statement("ALTER TABLE inventories MODIFY COLUMN status ENUM('IN_STOCK','RESERVED','SOLD','DAMAGED','SCRAPPED') NOT NULL DEFAULT 'IN_STOCK'");
    }
};
