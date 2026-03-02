<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('serial_counters', function (Blueprint $table) {
            $table->dropForeign(['category_id']);
            $table->dropUnique(['category_id', 'prefix']);
        });

        Schema::table('serial_counters', function (Blueprint $table) {
            $table->unsignedBigInteger('category_id')->nullable()->change();
            $table->unique(['category_id', 'prefix']);
        });
    }

    public function down(): void
    {
        Schema::table('serial_counters', function (Blueprint $table) {
            $table->dropUnique(['category_id', 'prefix']);
            $table->unsignedBigInteger('category_id')->nullable(false)->change();
            $table->foreignId('category_id')->constrained('categories')->cascadeOnDelete();
            $table->unique(['category_id', 'prefix']);
        });
    }
};
