<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->string('width', 100)->nullable()->comment('ขนาดหน้าตัด เช่น 3*3')->change();
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->decimal('width', 10, 2)->nullable()->comment('Width of the product')->change();
        });
    }
};
