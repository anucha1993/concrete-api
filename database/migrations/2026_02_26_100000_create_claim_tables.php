<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Claims (ใบเคลมสินค้า) ──
        Schema::create('claims', function (Blueprint $table) {
            $table->id();
            $table->string('code', 20)->unique();                   // CLM-YYMM-XXX
            $table->enum('type', [
                'RETURN',             // คืนสินค้า (สั่งผิด/เปลี่ยนตัว)
                'TRANSPORT_DAMAGE',   // เสียหายจากขนส่ง
                'DEFECT',             // ชำรุด/ตำหนิจากผลิต
                'WRONG_SPEC',         // สินค้าไม่ตรงสเปค
                'OTHER',              // อื่นๆ
            ]);
            $table->enum('status', [
                'DRAFT',              // แบบร่าง
                'PENDING',            // รอตรวจสอบ
                'APPROVED',           // อนุมัติ
                'REJECTED',           // ปฏิเสธ
                'CANCELLED',          // ยกเลิก
            ])->default('DRAFT');
            $table->enum('resolution', [
                'RETURN_STOCK',       // รับคืนเข้าสต๊อก (สภาพดี)
                'RETURN_DAMAGED',     // รับคืนเป็นสินค้าชำรุด
                'REPLACE',            // เปลี่ยนสินค้า
                'REFUND',             // คืนเงิน
                'CREDIT_NOTE',        // ออกใบลดหนี้
            ])->nullable();
            $table->string('customer_name', 255)->nullable();
            $table->string('reference_doc', 255)->nullable();       // เลขใบส่งของ/ใบขาย
            $table->foreignId('stock_deduction_id')->nullable()     // อ้างอิงใบตัดสต๊อก (ถ้ามี)
                ->constrained('stock_deductions')->nullOnDelete();
            $table->text('reason')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();
            $table->text('reject_reason')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // ── Claim Lines (รายการสินค้าเคลม) ──
        Schema::create('claim_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('claim_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('inventory_id')->nullable()           // serial เฉพาะตัว (nullable — อาจยังไม่ระบุ serial)
                ->constrained('inventories')->nullOnDelete();
            $table->string('serial_number', 50)->nullable();
            $table->unsignedInteger('quantity')->default(1);
            $table->enum('return_condition', ['GOOD', 'DAMAGED'])->default('GOOD');
            $table->text('note')->nullable();
            $table->timestamps();
            $table->index('claim_id');
            $table->index('inventory_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('claim_lines');
        Schema::dropIfExists('claims');
    }
};
