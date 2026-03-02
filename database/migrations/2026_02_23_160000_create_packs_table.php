<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('packs', function (Blueprint $table) {
            $table->id();
            $table->string('code', 50)->unique()->comment('รหัสแพ');
            $table->string('name', 200)->comment('ชื่อแพ');
            $table->text('description')->nullable()->comment('คำอธิบาย');
            $table->boolean('is_active')->default(true)->comment('สถานะ');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('pack_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('pack_id')->constrained('packs')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products')->cascadeOnDelete();
            $table->integer('quantity')->default(1)->comment('จำนวนสินค้าในแพ');
            $table->timestamps();

            $table->unique(['pack_id', 'product_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pack_items');
        Schema::dropIfExists('packs');
    }
};
