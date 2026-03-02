<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stock_count_scans', function (Blueprint $table) {
            $table->string('resolution', 20)->nullable()->after('is_duplicate');
            $table->unsignedBigInteger('resolution_product_id')->nullable()->after('resolution');
            $table->unsignedBigInteger('resolution_location_id')->nullable()->after('resolution_product_id');
        });
    }

    public function down(): void
    {
        Schema::table('stock_count_scans', function (Blueprint $table) {
            $table->dropColumn(['resolution', 'resolution_product_id', 'resolution_location_id']);
        });
    }
};
