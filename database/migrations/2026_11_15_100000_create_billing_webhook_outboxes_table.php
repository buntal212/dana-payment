<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('billing_webhook_outboxes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained('payments')->cascadeOnDelete();
            $table->string('environment', 20)->default('sandbox');
            $table->string('event_type', 50);
            $table->string('target_url', 2048);
            $table->string('idempotency_key', 160)->unique();
            $table->json('payload');
            $table->string('status', 24)->default('pending');
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(5);
            $table->timestamp('available_at')->nullable();
            $table->timestamp('locked_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->string('last_error', 500)->nullable();
            $table->unsignedSmallInteger('last_http_status')->nullable();
            $table->timestamps();

            $table->index(['status', 'available_at'], 'billing_outbox_status_available_idx');
            $table->index(['payment_id', 'status'], 'billing_outbox_payment_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('billing_webhook_outboxes');
    }
};
