<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── รอบตรวจนับสต๊อก ──
        Schema::create('stock_counts', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('SC-YYMM-NNN');
            $table->string('name');
            $table->enum('type', ['FULL', 'CYCLE', 'SPOT'])->default('FULL');
            $table->enum('status', ['DRAFT', 'IN_PROGRESS', 'COMPLETED', 'APPROVED', 'CANCELLED'])->default('DRAFT');
            $table->json('filter_category_ids')->nullable();
            $table->json('filter_location_ids')->nullable();
            $table->json('filter_product_ids')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── รายการสินค้าที่คาดหวัง (snapshot ตอนเริ่มนับ) ──
        Schema::create('stock_count_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained();
            $table->unsignedInteger('expected_qty')->default(0)->comment('จำนวนในระบบ ณ ตอนเปิดรอบ');
            $table->unsignedInteger('scanned_qty')->default(0)->comment('จำนวนที่สแกนได้');
            $table->integer('difference')->default(0)->comment('scanned - expected');
            $table->enum('resolution', ['PENDING', 'MATCHED', 'ADJUSTED', 'IGNORED'])->default('PENDING');
            $table->text('note')->nullable();
            $table->timestamps();

            $table->unique(['stock_count_id', 'product_id']);
        });

        // ── log สแกนจาก PDA ──
        Schema::create('stock_count_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_count_id')->constrained()->cascadeOnDelete();
            $table->string('serial_number', 50);
            $table->foreignId('product_id')->nullable()->constrained();
            $table->foreignId('inventory_id')->nullable()->constrained();
            $table->foreignId('pda_token_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_expected')->default(true)->comment('Serial อยู่ในระบบ/scope หรือไม่');
            $table->boolean('is_duplicate')->default(false);
            $table->timestamp('scanned_at');
            $table->timestamps();

            $table->index(['stock_count_id', 'serial_number']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_count_scans');
        Schema::dropIfExists('stock_count_items');
        Schema::dropIfExists('stock_counts');
    }
};
