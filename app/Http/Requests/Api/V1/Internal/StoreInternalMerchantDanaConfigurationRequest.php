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
            'environment' => ['required', 'string', 'in:sandbox'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'partner_id' => ['required', 'string', 'max:150'],
            'merchant_id' => ['required', 'string', 'max:150'],
            'private_key' => ['nullable', 'string', 'max:65535'],
            'dana_webhook_public_key' => ['nullable', 'string', 'max:65535'],
            'origin' => ['required', 'url', 'max:255', 'regex:/\Ahttps:\/\//i'],
            'channel_id' => ['required', 'string', 'max:100'],
            'notification_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'callback_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'mcc' => ['required', 'string', 'regex:/\A\d{4}\z/'],
            'enabled' => ['required', 'boolean'],
        ];
    }
}
