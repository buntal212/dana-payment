<?php

namespace App\Http\Requests\Api\V1\Internal;

use App\Enums\DanaSandboxPaymentMethod;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

class StoreDanaSandboxTestTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'amount_minor' => ['required', 'integer', 'min:1', 'max:999999999999999'],
            'payment_method' => ['required', 'string', 'in:'.implode(',', array_column(DanaSandboxPaymentMethod::cases(), 'value'))],
            'currency' => ['prohibited'],
            'environment' => ['prohibited'],
            'idempotency_key' => ['prohibited'],
            'metadata' => ['prohibited'],
            'status' => ['prohibited'],
            // Opsional hanya untuk kompatibilitas rollout dengan Billing versi sebelumnya.
            // Billing yang mendukung halaman hasil selalu mengirimkan nilai ini.
            'return_context' => ['nullable', 'string', 'regex:/\A[A-Za-z0-9_-]{43}\z/'],
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator): void {
            $idempotencyKey = $this->header('Idempotency-Key');
            if (! is_string($idempotencyKey) || preg_match('/\A[a-zA-Z0-9][a-zA-Z0-9._-]{15,127}\z/', $idempotencyKey) !== 1) {
                $validator->errors()->add('Idempotency-Key', 'Header Idempotency-Key wajib berupa identifier tindakan yang valid.');
            }
        }];
    }

    /** @return array{amount_minor: int, payment_method: string, idempotency_key: string, return_context: ?string} */
    public function transactionAttributes(): array
    {
        return [
            'amount_minor' => $this->integer('amount_minor'),
            'payment_method' => (string) $this->input('payment_method'),
            'idempotency_key' => (string) $this->header('Idempotency-Key'),
            'return_context' => $this->filled('return_context') ? (string) $this->input('return_context') : null,
        ];
    }
}
