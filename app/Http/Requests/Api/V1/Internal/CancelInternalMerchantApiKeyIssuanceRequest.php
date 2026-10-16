<?php

namespace App\Http\Requests\Api\V1\Internal;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class CancelInternalMerchantApiKeyIssuanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['confirm_cancel' => ['required', 'accepted'], 'idempotency_key' => ['prohibited']];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            if (! is_string($this->header('Idempotency-Key')) || trim((string) $this->header('Idempotency-Key')) === '' || strlen((string) $this->header('Idempotency-Key')) > 128) {
                $validator->errors()->add('Idempotency-Key', 'Header Idempotency-Key wajib diisi dan maksimal 128 karakter.');
            }
        }];
    }

    public function idempotencyKey(): string
    {
        return (string) $this->header('Idempotency-Key');
    }
}
