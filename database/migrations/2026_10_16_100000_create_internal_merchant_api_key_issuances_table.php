<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('internal_merchant_api_key_issuances', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('service_id', 100);
            $table->string('idempotency_key', 128);
            $table->char('request_fingerprint', 64);
            $table->string('name', 120);
            $table->string('key_prefix', 24);
            $table->char('key_hash', 64)->unique('internal_merchant_api_key_issuances_hash_unique');
            $table->json('abilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->string('status', 30);
            $table->timestamp('pending_expires_at');
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->foreignId('merchant_api_key_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();

            $table->unique(['service_id', 'idempotency_key'], 'internal_merchant_key_issue_idempotency_uq');
            $table->index(['merchant_id', 'status', 'pending_expires_at'], 'internal_merchant_key_issue_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('internal_merchant_api_key_issuances');
    }
};
