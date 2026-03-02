<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('counting_unit', 50)->default('ชิ้น')->after('category_id')->comment('หน่วยนับ เช่น ชิ้น, แผ่น, ต้น');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn('counting_unit');
        });
    }
};
