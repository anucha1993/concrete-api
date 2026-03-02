<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            // Add width dimension
            $table->decimal('width', 10, 2)->nullable()->after('thickness')->comment('Width of the product');

            // Add per-dimension unit columns
            $table->string('length_unit', 20)->default('meter')->after('length')->comment('Unit for length');
            $table->string('thickness_unit', 20)->default('millimeter')->after('thickness')->comment('Unit for thickness');
            $table->string('width_unit', 20)->default('meter')->after('width')->comment('Unit for width');

            // Remove global unit column
            $table->dropColumn('unit');

            // Make barcode nullable
            $table->string('barcode')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['width', 'length_unit', 'thickness_unit', 'width_unit']);
            $table->string('unit')->default('meter')->after('side_steel_type');
            $table->string('barcode')->nullable(false)->change();
        });
    }
};
