<?php

namespace App\Http\Requests\Api\V1\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class StoreInternalMerchantRequest extends FormRequest
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
            'code' => ['required', 'string', 'max:60'],
            'name' => ['required', 'string', 'max:180'],
            'email' => ['nullable', 'email', 'max:255'],
            'environment' => ['required', 'in:sandbox,production'],
            'idempotency_key' => ['required', 'string', 'max:128'],
        ];
    }
}
