<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pda_tokens', function (Blueprint $table) {
            $table->id();
            $table->string('token', 64)->unique();
            $table->string('name')->nullable()->comment('ชื่อ/หมายเหตุ เช่น "พนักงานคลัง A"');
            $table->foreignId('created_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->unsignedInteger('scan_count')->default(0);
            $table->boolean('is_revoked')->default(false);
            $table->timestamps();

            $table->index('token');
            $table->index('expires_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pda_tokens');
    }
};
