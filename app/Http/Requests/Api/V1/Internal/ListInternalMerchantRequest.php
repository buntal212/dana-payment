<?php

namespace App\Http\Requests\Api\V1\Internal;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class ListInternalMerchantRequest extends FormRequest
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
            'search' => ['nullable', 'string', 'max:180'],
            'status' => ['nullable', 'in:active,suspended,closed'],
            'environment' => ['nullable', 'in:sandbox,production'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }
}
