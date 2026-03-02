<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE inventory_movements MODIFY COLUMN `type` ENUM('PRODUCTION_IN','TRANSFER','SOLD','DAMAGED','ADJUSTMENT','CLAIM_RETURN') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE inventory_movements MODIFY COLUMN `type` ENUM('PRODUCTION_IN','TRANSFER','SOLD','DAMAGED','ADJUSTMENT') NOT NULL");
    }
};
