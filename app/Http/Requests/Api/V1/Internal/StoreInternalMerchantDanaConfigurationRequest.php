<?php

namespace App\Http\Requests\Api\V1\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInternalMerchantDanaConfigurationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|Closure|string>
     */
    public function rules(): array
    {
        return [
            'environment' => ['required', 'string', 'in:sandbox,production'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'partner_id' => ['required', 'string', 'max:150'],
            'client_id' => ['nullable', 'string', 'max:150'],
            'client_secret' => ['nullable', 'string', 'max:255'],
            'clear_client_secret' => ['nullable', 'boolean'],
            'merchant_id' => ['required', 'string', 'max:150'],
            'private_key' => ['nullable', 'string', 'max:65535'],
            'clear_private_key' => ['nullable', 'boolean'],
            'dana_webhook_public_key' => ['nullable', 'string', 'max:65535'],
            'clear_dana_webhook_public_key' => ['nullable', 'boolean'],
            'origin' => ['required', 'url', 'max:255', 'regex:/\Ahttps:\/\//i'],
            'channel_id' => ['required', 'string', 'max:100'],
            'external_store_id' => ['nullable', 'string', 'max:150'],
            'clear_external_store_id' => ['nullable', 'boolean'],
            'sub_merchant_id' => ['nullable', 'string', 'max:150'],
            'clear_sub_merchant_id' => ['nullable', 'boolean'],
            'notification_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'callback_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'mcc' => ['required', 'string', 'regex:/\A\d{4}\z/'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
