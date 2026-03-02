<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Drop old items table (empty, just created)
        Schema::dropIfExists('stock_deduction_items');

        // Add pda_token + status changes to stock_deductions
        Schema::table('stock_deductions', function (Blueprint $table) {
            $table->string('pda_token', 64)->nullable()->unique()->after('note');
            // Change status enum — drop and recreate column
        });

        // Workaround: alter enum by raw SQL (MySQL)
        \Illuminate\Support\Facades\DB::statement("ALTER TABLE `stock_deductions` MODIFY COLUMN `status` ENUM('DRAFT','PENDING','IN_PROGRESS','COMPLETED','APPROVED','CANCELLED') NOT NULL DEFAULT 'DRAFT'");

        // ── Stock Deduction Lines (planned: product + quantity) ──
        Schema::create('stock_deduction_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('quantity')->default(1);
            $table->unsignedInteger('scanned_qty')->default(0);
            $table->text('note')->nullable();
            $table->timestamps();
            $table->unique(['stock_deduction_id', 'product_id']);
            $table->index('stock_deduction_id');
        });

        // ── Stock Deduction Scans (actual scanned serials) ──
        Schema::create('stock_deduction_scans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('stock_deduction_id')->constrained()->cascadeOnDelete();
            $table->foreignId('stock_deduction_line_id')->constrained('stock_deduction_lines')->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories');
            $table->string('serial_number', 50);
            $table->foreignId('pda_token_id')->nullable()->constrained('pda_tokens')->nullOnDelete();
            $table->timestamp('scanned_at');
            $table->timestamps();
            $table->unique(['stock_deduction_id', 'inventory_id'], 'sd_scans_deduction_inventory_unique');
            $table->index(['stock_deduction_id', 'stock_deduction_line_id'], 'sd_scans_deduction_line_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stock_deduction_scans');
        Schema::dropIfExists('stock_deduction_lines');

        Schema::table('stock_deductions', function (Blueprint $table) {
            $table->dropColumn('pda_token');
        });

        \Illuminate\Support\Facades\DB::statement("ALTER TABLE `stock_deductions` MODIFY COLUMN `status` ENUM('DRAFT','PENDING','APPROVED','CANCELLED') NOT NULL DEFAULT 'DRAFT'");

        // Re-create original items table
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
};
