<?php

namespace App\Http\Requests\Api\V1;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Log;

class StorePaymentRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'merchant_order_id' => ['required', 'string', 'max:120', 'regex:/^[A-Za-z0-9][A-Za-z0-9._-]*$/'],
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'currency' => ['required', 'string', 'size:3', 'in:IDR'],
            'metadata' => ['nullable', 'array'],
            'idempotency_key' => ['required', 'string', 'max:128'],
            'status' => ['prohibited'],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    protected function failedValidation(Validator $validator): void
    {
        Log::warning('Payment request rejected.', [
            'merchant_id' => $this->attributes->get('merchant')?->getKey(),
            'fields' => array_keys($validator->failed()),
        ]);

        parent::failedValidation($validator);
    }
}
