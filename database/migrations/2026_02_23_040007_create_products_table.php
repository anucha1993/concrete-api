<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->string('product_code')->unique();
            $table->string('name');
            $table->foreignId('category_id')->constrained('categories')->restrictOnDelete();
            $table->decimal('length', 10, 2)->nullable()->comment('Length of the product');
            $table->decimal('thickness', 10, 2)->nullable()->comment('Thickness of the product');
            $table->string('steel_type')->nullable()->comment('Type of steel used');
            $table->enum('side_steel_type', ['HIDE', 'SHOW'])->default('HIDE')->comment('HIDE=ไม่แสดงเหล็กข้าง, SHOW=แสดงเหล็กข้าง');
            $table->string('unit')->default('meter')->comment('Unit of measure: meter, centimeter');
            $table->enum('size_type', ['STANDARD', 'CUSTOM'])->default('STANDARD');
            $table->text('custom_note')->nullable()->comment('Required when size_type = CUSTOM');
            $table->integer('stock_min')->default(0);
            $table->integer('stock_max')->default(0);
            $table->foreignId('default_location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->string('barcode')->unique();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->softDeletes();

            // Indexes for common queries
            $table->index('category_id');
            $table->index('size_type');
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('products');
    }
};
