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
        Schema::table('merchant_api_keys', function (Blueprint $table): void {
            if (! Schema::hasColumn('merchant_api_keys', 'environment')) {
                $table->string('environment', 20)->default('sandbox')->after('merchant_id')->index();
            }
        });

        Schema::table('internal_merchant_api_key_issuances', function (Blueprint $table): void {
            if (! Schema::hasColumn('internal_merchant_api_key_issuances', 'environment')) {
                $table->string('environment', 20)->default('sandbox')->after('merchant_id')->index();
            }
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internal_merchant_api_key_issuances', function (Blueprint $table): void {
            if (Schema::hasColumn('internal_merchant_api_key_issuances', 'environment')) {
                $table->dropIndex(['environment']);
                $table->dropColumn('environment');
            }
        });

        Schema::table('merchant_api_keys', function (Blueprint $table): void {
            if (Schema::hasColumn('merchant_api_keys', 'environment')) {
                $table->dropIndex(['environment']);
                $table->dropColumn('environment');
            }
        });
    }
};
