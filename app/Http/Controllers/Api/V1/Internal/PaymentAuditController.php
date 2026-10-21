<?php

namespace App\Http\Controllers\Api\V1\Internal;

use App\Http\Controllers\Controller;
use App\Models\InternalMerchantProvisioningRequest;
use App\Models\Merchant;
use App\Models\Payment;
use App\Models\PaymentWebhook;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Read-only, Sandbox-only audit feed for the UDEV internal service.
 *
 * This controller deliberately does not expose checkout URLs, request payloads,
 * signatures, fingerprints, metadata, or credentials.
 */
class PaymentAuditController extends Controller
{
    public function show(Merchant $merchant, string $paymentId): JsonResponse
    {
        $this->ensureSandboxMerchant($merchant);

        $payment = $this->paymentQuery($merchant)
            ->with(['events' => fn ($query) => $query->orderBy('id')])
            ->where('public_id', $paymentId)
            ->firstOrFail();

        return response()->json(['data' => $this->payment($payment, true)])
            ->header('Cache-Control', 'no-store');
    }

    public function webhooks(Merchant $merchant, string $paymentId, Request $request): JsonResponse
    {
        $this->ensureSandboxMerchant($merchant);
        $validated = $request->validate(['per_page' => ['nullable', 'integer', 'min:1', 'max:100']]);
        $payment = $this->paymentQuery($merchant)->where('public_id', $paymentId)->firstOrFail();
        $webhooks = PaymentWebhook::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('payment_id', $payment->getKey())
            ->where('provider', 'dana')
            ->where('environment', 'sandbox')
            ->orderByDesc('id')
            ->paginate($validated['per_page'] ?? 15);

        return response()->json([
            'data' => $webhooks->getCollection()->map(fn (PaymentWebhook $webhook): array => [
                'received_at' => $webhook->created_at?->toIso8601String(),
                'processed_at' => $webhook->processed_at?->toIso8601String(),
                'signature_valid' => $webhook->signature_valid,
                'processing_status' => $webhook->processing_status,
                'error_code' => $webhook->error_code,
                'provider_event_id' => $webhook->provider_event_id,
            ])->values(),
            'meta' => $this->pagination($webhooks),
        ])->header('Cache-Control', 'no-store');
    }

    public function index(Merchant $merchant, Request $request): JsonResponse
    {
        $this->ensureSandboxMerchant($merchant);
        $filters = $this->filters($request);
        $payments = $this->applyFilters($this->paymentQuery($merchant), $filters)
            ->orderByDesc('id')
            ->paginate($filters['per_page'] ?? 15);

        return response()->json([
            'data' => $payments->getCollection()->map(fn (Payment $payment): array => $this->payment($payment))->values(),
            'meta' => $this->pagination($payments),
        ])->header('Cache-Control', 'no-store');
    }

    public function summary(Merchant $merchant, Request $request): JsonResponse
    {
        $this->ensureSandboxMerchant($merchant);
        $filters = $this->filters($request);
        $query = $this->applyFilters($this->paymentQuery($merchant), $filters);
        $totals = (clone $query)->selectRaw("COUNT(*) as total, SUM(CASE WHEN status = 'paid' THEN 1 ELSE 0 END) as paid, SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending, SUM(CASE WHEN status IN ('failed', 'expired', 'cancelled') THEN 1 ELSE 0 END) as failed_or_expired")->first();
        $currencies = (clone $query)->selectRaw("currency, SUM(CASE WHEN status = 'paid' THEN amount_minor ELSE 0 END) as paid_amount_minor")
            ->groupBy('currency')
            ->orderBy('currency')
            ->get()
            ->map(fn (object $row): array => ['currency' => $row->currency, 'paid_amount_minor' => (int) $row->paid_amount_minor])
            ->values();

        return response()->json(['data' => [
            'total' => (int) ($totals->total ?? 0),
            'paid' => (int) ($totals->paid ?? 0),
            'pending' => (int) ($totals->pending ?? 0),
            'failed_or_expired' => (int) ($totals->failed_or_expired ?? 0),
            'paid_amounts' => $currencies,
        ]])->header('Cache-Control', 'no-store');
    }

    /** @return array<string, mixed> */
    private function filters(Request $request): array
    {
        return $request->validate([
            'status' => ['nullable', 'in:pending,paid,failed,expired,cancelled'],
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'merchant_order_id' => ['nullable', 'string', 'max:255'],
            // Billing supplies this server-side allow-list to preserve Sandbox
            // Tester ownership without granting an admin visibility of another
            // admin's transactions for the same merchant.
            'payment_ids' => ['nullable', 'array', 'max:500'],
            'payment_ids.*' => ['ulid'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }

    /** @param array<string, mixed> $filters */
    private function applyFilters(Builder $query, array $filters): Builder
    {
        return $query
            ->when($filters['status'] ?? null, fn (Builder $query, string $status): Builder => $query->where('status', $status))
            ->when($filters['from'] ?? null, fn (Builder $query, string $from): Builder => $query->whereDate('created_at', '>=', $from))
            ->when($filters['to'] ?? null, fn (Builder $query, string $to): Builder => $query->whereDate('created_at', '<=', $to))
            ->when($filters['merchant_order_id'] ?? null, fn (Builder $query, string $reference): Builder => $query->where('merchant_order_id', $reference))
            ->when($filters['payment_ids'] ?? null, fn (Builder $query, array $paymentIds): Builder => $query->whereIn('public_id', $paymentIds));
    }

    private function paymentQuery(Merchant $merchant): Builder
    {
        return Payment::query()->forMerchant($merchant)->where('environment', 'sandbox')->where('provider', 'dana');
    }

    private function ensureSandboxMerchant(Merchant $merchant): void
    {
        $managed = InternalMerchantProvisioningRequest::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('service_id', (string) request()->attributes->get('internal_service_id'))
            ->where('environment', 'sandbox')
            ->where('status', InternalMerchantProvisioningRequest::StatusProvisioned)
            ->exists();

        abort_unless($managed, Response::HTTP_NOT_FOUND);
    }

    /** @return array<string, mixed> */
    private function payment(Payment $payment, bool $withTimeline = false): array
    {
        $data = [
            'id' => $payment->public_id,
            'merchant_reference' => $payment->merchant_order_id,
            'provider_order_reference' => $payment->provider_order_id,
            'amount_minor' => $payment->amount_minor,
            'currency' => $payment->currency,
            'status' => $payment->status,
            'created_at' => $payment->created_at?->toIso8601String(),
            'updated_at' => $payment->updated_at?->toIso8601String(),
            'gateway_verified_at' => $payment->paid_at?->toIso8601String(),
        ];

        if ($withTimeline) {
            $data['timeline'] = $payment->events->map(fn ($event): array => [
                'event_type' => $event->event_type,
                'old_status' => $event->old_status,
                'new_status' => $event->new_status,
                'source' => $event->source,
                'created_at' => $event->created_at?->toIso8601String(),
            ])->values();
        }

        return $data;
    }

    /** @return array<string, int> */
    private function pagination($paginator): array
    {
        return ['current_page' => $paginator->currentPage(), 'per_page' => $paginator->perPage(), 'total' => $paginator->total(), 'last_page' => $paginator->lastPage()];
    }
}
