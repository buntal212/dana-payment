<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('dana_sandbox_test_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('operation', 40);
            $table->string('environment', 20)->default('sandbox');
            $table->string('status', 20);
            $table->string('message', 255);
            $table->json('safe_details')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'environment', 'created_at'], 'dana_sandbox_test_run_merchant_created_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('dana_sandbox_test_runs');
    }
};
