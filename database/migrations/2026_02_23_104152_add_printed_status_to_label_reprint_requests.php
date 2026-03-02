<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE label_reprint_requests MODIFY COLUMN status ENUM('PENDING','APPROVED','REJECTED','PRINTED') NOT NULL DEFAULT 'PENDING'");
    }

    public function down(): void
    {
        DB::statement("UPDATE label_reprint_requests SET status = 'APPROVED' WHERE status = 'PRINTED'");
        DB::statement("ALTER TABLE label_reprint_requests MODIFY COLUMN status ENUM('PENDING','APPROVED','REJECTED') NOT NULL DEFAULT 'PENDING'");
    }
};
