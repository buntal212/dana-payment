<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('internal_merchant_management_requests', function (Blueprint $table) {
            $table->id();
            $table->string('service_id', 100);
            $table->string('operation', 60);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('status', 30);
            $table->json('safe_result')->nullable();
            $table->timestamps();

            $table->unique(['service_id', 'operation', 'idempotency_key'], 'internal_merchant_management_idempotency_unique');
            $table->index(['merchant_id', 'created_at'], 'internal_merchant_management_merchant_idx');
        });

        Schema::create('merchant_management_audits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('service_id', 100);
            $table->string('operation', 60);
            $table->char('request_fingerprint', 64);
            $table->json('safe_details')->nullable();
            $table->timestamps();

            $table->index(['merchant_id', 'created_at'], 'merchant_management_audit_merchant_idx');
            $table->index(['service_id', 'created_at'], 'merchant_management_audit_service_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('merchant_management_audits');
        Schema::dropIfExists('internal_merchant_management_requests');
    }
};
