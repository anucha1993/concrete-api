<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // เพิ่ม pda_token ให้ claims (CRL — claim reference link)
        Schema::table('claims', function (Blueprint $table) {
            $table->string('pda_token', 64)->nullable()->unique()->after('note');
        });

        // ลบ return_condition ออกจาก claim_lines (ใช้ resolution แทน)
        Schema::table('claim_lines', function (Blueprint $table) {
            $table->dropColumn('return_condition');
        });
    }

    public function down(): void
    {
        Schema::table('claims', function (Blueprint $table) {
            $table->dropColumn('pda_token');
        });

        Schema::table('claim_lines', function (Blueprint $table) {
            $table->enum('return_condition', ['GOOD', 'DAMAGED'])->default('GOOD')->after('quantity');
        });
    }
};
