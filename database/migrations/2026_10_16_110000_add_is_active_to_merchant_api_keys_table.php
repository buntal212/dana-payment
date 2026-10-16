<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('merchant_api_keys', function (Blueprint $table): void {
            $table->boolean('is_active')->default(true)->index();
        });

        DB::table('internal_merchant_api_key_issuances')
            ->whereNull('merchant_api_key_id')
            ->whereIn('status', ['pending', 'cancelled', 'expired'])
            ->orderBy('id')
            ->each(function (object $issuance): void {
                $reservedApiKeyId = DB::table('merchant_api_keys')->insertGetId([
                    'merchant_id' => $issuance->merchant_id,
                    'name' => $issuance->name,
                    'key_prefix' => $issuance->key_prefix,
                    'key_hash' => $issuance->key_hash,
                    'abilities' => $issuance->abilities,
                    'expires_at' => $issuance->expires_at,
                    'is_active' => false,
                    'created_at' => $issuance->created_at ?? now(),
                    'updated_at' => now(),
                ]);

                DB::table('internal_merchant_api_key_issuances')
                    ->where('id', $issuance->id)
                    ->update(['merchant_api_key_id' => $reservedApiKeyId]);
            });

        Schema::table('internal_merchant_api_key_issuances', function (Blueprint $table): void {
            $table->unique('merchant_api_key_id', 'internal_merchant_key_issue_api_key_uq');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('internal_merchant_api_key_issuances', function (Blueprint $table): void {
            $table->dropUnique('internal_merchant_key_issue_api_key_uq');
        });

        Schema::table('merchant_api_keys', function (Blueprint $table): void {
            $table->dropIndex(['is_active']);
            $table->dropColumn('is_active');
        });
    }
};
