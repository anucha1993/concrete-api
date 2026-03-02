<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('claim_lines', function (Blueprint $table) {
            $table->enum('resolution', [
                'RETURN_STOCK',       // คืนเข้าสต๊อก (สภาพดี)
                'RETURN_DAMAGED',     // คืนเป็นสินค้าชำรุด
                'REPLACE',            // เปลี่ยนสินค้า
                'REFUND',             // คืนเงิน
                'CREDIT_NOTE',        // ออกใบลดหนี้
            ])->nullable()->after('return_condition');
        });
    }

    public function down(): void
    {
        Schema::table('claim_lines', function (Blueprint $table) {
            $table->dropColumn('resolution');
        });
    }
};
