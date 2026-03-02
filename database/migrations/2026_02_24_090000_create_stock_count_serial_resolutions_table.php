<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('stock_count_serial_resolutions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained();
            $table->string('serial_number');
            $table->foreignId('product_id')->constrained();
            $table->string('resolution', 20); // WRITE_OFF, KEEP
            $table->timestamps();

            $table->unique(['stock_count_id', 'inventory_id'], 'sc_serial_res_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_serial_resolutions');
    }
};
