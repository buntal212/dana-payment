<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('merchants', function (Blueprint $table) {
            $table->id();
            $table->string('code', 60)->unique();
            $table->string('name', 180);
            $table->string('contact_email')->nullable();
            $table->string('status', 20)->default('active'); // active, suspended, closed
            $table->timestamps();
            $table->softDeletes();
            $table->index('status');
        });

        Schema::create('merchant_api_keys', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('name', 120);
            $table->string('key_prefix', 24)->index();
            $table->char('key_hash', 64)->unique(); // SHA-256 of high-entropy key
            $table->json('abilities')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->timestamps();
            $table->index(['merchant_id', 'revoked_at']);
        });

        Schema::create('merchant_payment_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('dana');
            $table->string('environment', 20)->default('sandbox');
            $table->string('partner_id', 150)->nullable();
            $table->text('encrypted_credentials')->nullable(); // Encrypt with Laravel Crypt before storage
            $table->string('callback_url', 2048)->nullable();
            $table->boolean('enabled')->default(false);
            $table->timestamps();
            $table->unique(['merchant_id', 'provider', 'environment'], 'merchant_provider_env_unique');
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->string('merchant_order_id', 120);
            $table->string('provider', 30)->default('dana');
            $table->string('environment', 20)->default('sandbox');
            $table->string('provider_order_id', 160)->nullable();
            $table->string('provider_transaction_id', 160)->nullable();
            $table->unsignedBigInteger('amount_minor'); // IDR: whole rupiah, no float
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 24)->default('pending');
            $table->text('checkout_url')->nullable();
            $table->string('idempotency_key', 128)->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamp('failed_at')->nullable();
            $table->json('metadata')->nullable(); // non-secret, minimized
            $table->timestamps();
            $table->unique(['merchant_id', 'merchant_order_id'], 'payment_merchant_order_unique');
            $table->unique(['merchant_id', 'idempotency_key'], 'payment_merchant_idempotency_unique');
            $table->unique(['provider', 'environment', 'provider_order_id'], 'payment_provider_order_unique');
            $table->index(['merchant_id', 'status', 'created_at'], 'payment_merchant_status_created_idx');
            $table->index(['provider', 'environment', 'provider_transaction_id'], 'payment_provider_tx_idx');
        });

        Schema::create('payment_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('event_type', 80);
            $table->string('old_status', 24)->nullable();
            $table->string('new_status', 24)->nullable();
            $table->string('source', 30); // system, merchant, provider
            $table->json('safe_details')->nullable(); // redact secrets / personal data
            $table->timestamps();
            $table->index(['payment_id', 'created_at']);
            $table->index(['merchant_id', 'created_at']);
        });

        Schema::create('payment_webhooks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('merchant_id')->nullable()->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->nullable()->constrained()->restrictOnDelete();
            $table->string('provider', 30)->default('dana');
            $table->string('environment', 20)->default('sandbox');
            $table->char('payload_sha256', 64); // fingerprint, not raw body
            $table->string('provider_event_id', 160)->nullable();
            $table->boolean('signature_valid')->default(false);
            $table->string('processing_status', 24)->default('received');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->timestamp('processed_at')->nullable();
            $table->string('error_code', 80)->nullable();
            $table->timestamps();
            $table->unique(['provider', 'environment', 'provider_event_id'], 'webhook_provider_event_unique');
            $table->index(['provider', 'environment', 'payload_sha256'], 'webhook_payload_fingerprint_idx');
            $table->index(['processing_status', 'created_at']);
        });

        Schema::create('payment_refunds', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('merchant_id')->constrained()->restrictOnDelete();
            $table->foreignId('payment_id')->constrained()->restrictOnDelete();
            $table->string('merchant_refund_id', 120);
            $table->string('provider_refund_id', 160)->nullable();
            $table->unsignedBigInteger('amount_minor');
            $table->char('currency', 3)->default('IDR');
            $table->string('status', 24)->default('pending');
            $table->string('reason', 500)->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
            $table->unique(['merchant_id', 'merchant_refund_id'], 'refund_merchant_reference_unique');
            $table->index(['payment_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payment_refunds');
        Schema::dropIfExists('payment_webhooks');
        Schema::dropIfExists('payment_events');
        Schema::dropIfExists('payments');
        Schema::dropIfExists('merchant_payment_configs');
        Schema::dropIfExists('merchant_api_keys');
        Schema::dropIfExists('merchants');
    }
};
