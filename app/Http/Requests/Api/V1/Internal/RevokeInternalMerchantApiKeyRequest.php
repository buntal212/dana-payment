<?php

namespace App\Http\Requests\Api\V1\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RevokeInternalMerchantApiKeyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'max:128'],
            'confirm_revoke' => ['required', 'accepted'],
        ];
    }
}
