<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Add label tracking columns to inventories ────
        Schema::table('inventories', function (Blueprint $table) {
            $table->timestamp('label_printed_at')->nullable()->after('last_movement_at')->comment('วันที่ปริ้น label');
            $table->foreignId('label_printed_by')->nullable()->after('label_printed_at')->constrained('users')->nullOnDelete();
            $table->timestamp('label_verified_at')->nullable()->after('label_printed_by')->comment('วันที่ยืนยันติด label (PDA)');
            $table->foreignId('label_verified_by')->nullable()->after('label_verified_at')->constrained('users')->nullOnDelete();
            $table->unsignedSmallInteger('label_print_count')->default(0)->after('label_verified_by')->comment('จำนวนครั้งที่ปริ้น');
        });

        // ── Label Print Logs (ประวัติการปริ้น) ───────────
        Schema::create('label_print_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->string('serial_number', 50)->index();
            $table->enum('print_type', ['FIRST', 'REPRINT'])->default('FIRST');
            $table->string('paper_size', 30)->default('50x30')->comment('ขนาดกระดาษ เช่น 50x30, 70x40');
            $table->text('reprint_reason')->nullable();
            $table->foreignId('reprint_request_id')->nullable();
            $table->foreignId('printed_by')->constrained('users');
            $table->timestamp('printed_at');
            $table->timestamps();

            $table->index(['inventory_id', 'printed_at']);
        });

        // ── Reprint Requests (คำขอปริ้นซ้ำ) ─────────────
        Schema::create('label_reprint_requests', function (Blueprint $table) {
            $table->id();
            $table->enum('status', ['PENDING', 'APPROVED', 'REJECTED'])->default('PENDING');
            $table->text('reason')->comment('เหตุผลขอปริ้นซ้ำ');
            $table->text('reject_reason')->nullable()->comment('เหตุผลที่ปฏิเสธ');

            // ── Who ──
            $table->foreignId('requested_by')->constrained('users');
            $table->foreignId('approved_by')->nullable()->constrained('users');
            $table->timestamp('approved_at')->nullable();

            // ── Optional link to production order ──
            $table->foreignId('production_order_id')->nullable()->constrained('production_orders')->nullOnDelete();

            $table->timestamps();

            $table->index('status');
        });

        // ── Pivot: which serials in a reprint request ────
        Schema::create('label_reprint_request_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reprint_request_id')->constrained('label_reprint_requests')->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['reprint_request_id', 'inventory_id'], 'reprint_req_inv_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('label_reprint_request_items');
        Schema::dropIfExists('label_reprint_requests');
        Schema::dropIfExists('label_print_logs');

        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['label_printed_by']);
            $table->dropForeign(['label_verified_by']);
            $table->dropColumn([
                'label_printed_at',
                'label_printed_by',
                'label_verified_at',
                'label_verified_by',
                'label_print_count',
            ]);
        });
    }
};
