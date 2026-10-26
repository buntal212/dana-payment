<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->string('partner_reference_no', 64)->nullable()->after('public_id');
            $table->unique(['provider', 'environment', 'partner_reference_no'], 'payment_provider_env_partner_ref_unique');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table): void {
            $table->dropUnique('payment_provider_env_partner_ref_unique');
            $table->dropColumn('partner_reference_no');
        });
    }
};
