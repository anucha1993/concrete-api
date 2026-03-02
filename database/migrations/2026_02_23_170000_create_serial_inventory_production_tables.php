<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ── Serial Counters ──────────────────────────────────
        // Tracks running number per category per month
        Schema::create('serial_counters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->string('prefix', 30)->comment('e.g. E-2602');
            $table->unsignedBigInteger('last_number')->default(0);
            $table->timestamps();

            $table->unique(['category_id', 'prefix']);
        });

        // ── Inventories ──────────────────────────────────────
        // Each row = 1 physical item with unique serial
        Schema::create('inventories', function (Blueprint $table) {
            $table->id();
            $table->string('serial_number', 50)->unique()->comment('CAT-YYMM-XXXXXXXX');
            $table->foreignId('product_id')->constrained('products');
            $table->foreignId('location_id')->nullable()->constrained('locations')->nullOnDelete();
            $table->foreignId('production_order_id')->nullable(); // set after FK table exists
            $table->enum('status', ['IN_STOCK', 'RESERVED', 'SOLD', 'DAMAGED', 'SCRAPPED'])->default('IN_STOCK');
            $table->enum('condition', ['GOOD', 'DAMAGED'])->default('GOOD');
            $table->text('note')->nullable();
            $table->timestamp('received_at')->nullable()->comment('วันที่รับเข้าคลัง');
            $table->timestamp('last_movement_at')->nullable()->comment('วันที่เคลื่อนไหวล่าสุด');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['product_id', 'status']);
            $table->index('location_id');
            $table->index('last_movement_at');
        });

        // ── Production Orders (ใบสั่งผลิต) ───────────────────
        Schema::create('production_orders', function (Blueprint $table) {
            $table->id();
            $table->string('order_number', 50)->unique()->comment('เลขที่ใบสั่งผลิต PO-YYMM-XXXX');
            $table->foreignId('pack_id')->constrained('packs');
            $table->unsignedInteger('quantity')->default(1)->comment('จำนวนชุด (แพ) ที่สั่งผลิต');
            $table->enum('status', ['DRAFT', 'CONFIRMED', 'IN_PROGRESS', 'COMPLETED', 'CANCELLED'])->default('DRAFT');
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->foreignId('confirmed_by')->nullable()->constrained('users');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->index('status');
        });

        // ── Production Order Items ───────────────────────────
        // Expanded from pack: each product × quantity
        Schema::create('production_order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('product_id')->constrained('products');
            $table->unsignedInteger('planned_qty')->comment('จำนวนที่ต้องผลิต');
            $table->unsignedInteger('good_qty')->default(0)->comment('ของดี');
            $table->unsignedInteger('damaged_qty')->default(0)->comment('ชำรุด');
            $table->timestamps();

            $table->index('production_order_id');
        });

        // ── Production Serials ───────────────────────────────
        // Links serial to production order item
        Schema::create('production_serials', function (Blueprint $table) {
            $table->id();
            $table->foreignId('production_order_id')->constrained('production_orders')->cascadeOnDelete();
            $table->foreignId('production_order_item_id')->constrained('production_order_items')->cascadeOnDelete();
            $table->foreignId('inventory_id')->constrained('inventories')->cascadeOnDelete();
            $table->enum('condition', ['GOOD', 'DAMAGED'])->default('GOOD');
            $table->timestamps();
        });

        // ── Inventory Movements (log) ────────────────────────
        Schema::create('inventory_movements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('inventory_id')->constrained('inventories');
            $table->enum('type', ['PRODUCTION_IN', 'TRANSFER', 'SOLD', 'DAMAGED', 'ADJUSTMENT']);
            $table->foreignId('from_location_id')->nullable()->constrained('locations');
            $table->foreignId('to_location_id')->nullable()->constrained('locations');
            $table->string('reference_type', 50)->nullable()->comment('production_orders etc.');
            $table->unsignedBigInteger('reference_id')->nullable();
            $table->text('note')->nullable();
            $table->foreignId('created_by')->constrained('users');
            $table->timestamps();

            $table->index(['inventory_id', 'created_at']);
        });

        // Add FK for production_order_id in inventories
        Schema::table('inventories', function (Blueprint $table) {
            $table->foreign('production_order_id')->references('id')->on('production_orders')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventories', function (Blueprint $table) {
            $table->dropForeign(['production_order_id']);
        });
        Schema::dropIfExists('inventory_movements');
        Schema::dropIfExists('production_serials');
        Schema::dropIfExists('production_order_items');
        Schema::dropIfExists('production_orders');
        Schema::dropIfExists('inventories');
        Schema::dropIfExists('serial_counters');
    }
};
