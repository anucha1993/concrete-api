<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Stock Deductions (ใบตัดสต๊อก) ──
        Schema::create('stock_deductions', function (Blueprint $table) {
            $table->id();
            $table->string('code', 30)->unique()->comment('SD-YYMM-NNN');
            $table->enum('type', ['SOLD', 'LOST', 'DAMAGED', 'OTHER'])->default('SOLD');
            $table->enum('status', ['DRAFT', 'PENDING', 'APPROVED', 'CANCELLED'])->default('DRAFT');
            $table->string('customer_name')->nullable()->comment('ชื่อลูกค้า (กรณีขาย)');
            $table->string('reference_doc')->nullable()->comment('เลขที่เอกสารอ้างอิง');
            $table->text('reason')->nullable()->comment('เหตุผลการตัดสต๊อก');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
            $table->index('type');
        });

        // ── Stock Deduction Items ──
        Schema::create('stock_deduction_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories');
            $table->string('serial_number', 50);
            $table->foreignId('product_id')->constrained('products');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['stock_deduction_id', 'inventory_id']);
            $table->index('stock_deduction_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_deduction_items');
        Schema::dropIfExists('stock_deductions');
    }
};
