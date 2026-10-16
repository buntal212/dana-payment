<?php

namespace App\Http\Requests\Api\V1\Internal;

use Closure;
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
            'private_key' => ['required', 'string', 'max:65535', $this->pemRule()],
            'dana_webhook_public_key' => ['required', 'string', 'max:65535', $this->pemRule()],
            'origin' => ['required', 'url', 'max:255', 'regex:/\Ahttps:\/\//i'],
            'channel_id' => ['required', 'string', 'max:100'],
            'notification_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'callback_url' => ['required', 'url', 'max:2048', 'regex:/\Ahttps:\/\//i'],
            'mcc' => ['required', 'string', 'regex:/\A\d{4}\z/'],
            'enabled' => ['required', 'boolean'],
        ];
    }

    private function pemRule(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_string($value) || preg_match('/\A-----BEGIN [A-Z0-9 ]+-----\R.+\R-----END [A-Z0-9 ]+-----\z/s', trim($value)) !== 1) {
                $fail('Format PEM tidak valid.');
            }
        };
    }
}
