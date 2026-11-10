<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Merchant;
use App\Models\MerchantPaymentConfig;
use App\Models\Payment;
use App\Models\PaymentEvent;
use App\Services\DanaSandboxCancelPaymentService;
use App\Services\DanaSandboxQueryPaymentService;
use App\Services\PaymentGatewayInterface;
use App\Services\PaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;

class PaymentController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $validated = $request->validate([
            'status' => ['nullable', 'string', 'in:pending,paid,failed,expired,cancelled'],
            'merchant_order_id' => ['nullable', 'string', 'max:120'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $payments = Payment::query()
            ->forMerchant($this->merchant($request))
            ->when($validated['status'] ?? null, fn ($query, string $status) => $query->where('status', $status))
            ->when($validated['merchant_order_id'] ?? null, fn ($query, string $orderId) => $query->where('merchant_order_id', $orderId))
            ->latest('id')
            ->paginate($validated['per_page'] ?? 20)
            ->withQueryString();

        return PaymentResource::collection($payments);
    }

    public function store(StorePaymentRequest $request, PaymentService $paymentService, PaymentGatewayInterface $gateway): PaymentResource
    {
        try {
            $payment = $paymentService->createHostedCheckout($this->merchant($request), $request->validated(), $gateway);
        } catch (HttpExceptionInterface $exception) {
            throw $exception;
        } catch (\LogicException $exception) {
            abort(422, 'Konfigurasi checkout merchant belum lengkap.');
        } catch (\RuntimeException $exception) {
            abort(503, 'Checkout sementara tidak tersedia.');
        }

        return new PaymentResource($payment);
    }

    public function show(Request $request, string $id): PaymentResource
    {
        return new PaymentResource($this->paymentForMerchant($request, $id));
    }

    public function status(Request $request, string $id): PaymentResource
    {
        return new PaymentResource($this->paymentForMerchant($request, $id));
    }

    public function inquiry(Request $request, string $id, DanaSandboxQueryPaymentService $queryService): JsonResponse
    {
        $merchant = $this->merchant($request);
        $payment = $this->paymentForMerchant($request, $id);

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('provider', 'dana')
            ->where('environment', $payment->environment)
            ->where('enabled', true)
            ->first();

        if ($config === null) {
            abort(422, 'Konfigurasi DANA belum tersedia untuk merchant ini.');
        }

        try {
            $inquiry = $queryService->query($payment, $config);
        } catch (\LogicException $e) {
            abort(422, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        if (($inquiry['latest_transaction_status'] ?? null) === '02' && $payment->status === 'pending') {
            $payment->update(['status' => 'cancelled']);
            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $payment->getKey(),
                'event_type' => 'payment.cancelled_sync',
                'old_status' => 'pending',
                'new_status' => 'cancelled',
                'source' => 'provider',
                'safe_details' => ['inquiry' => $inquiry],
            ]);
        } elseif (($inquiry['latest_transaction_status'] ?? null) === '05' && $payment->status === 'pending') {
            $payment->update(['status' => 'expired']);
            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $payment->getKey(),
                'event_type' => 'payment.expired_sync',
                'old_status' => 'pending',
                'new_status' => 'expired',
                'source' => 'provider',
                'safe_details' => ['inquiry' => $inquiry],
            ]);
        }

        return response()->json([
            'data' => (new PaymentResource($payment->fresh()))->toArray($request),
            'dana_status' => $inquiry,
        ]);
    }

    public function cancel(Request $request, string $id, DanaSandboxCancelPaymentService $cancelService): JsonResponse
    {
        $merchant = $this->merchant($request);
        $payment = $this->paymentForMerchant($request, $id);

        if ($payment->status !== 'pending') {
            abort(422, "Pembayaran tidak dapat dibatalkan karena status saat ini: {$payment->status}.");
        }

        $config = MerchantPaymentConfig::query()
            ->where('merchant_id', $merchant->getKey())
            ->where('provider', 'dana')
            ->where('environment', $payment->environment)
            ->where('enabled', true)
            ->first();

        if ($config === null) {
            abort(422, 'Konfigurasi DANA belum tersedia untuk merchant ini.');
        }

        $reason = $request->input('reason');
        $partnerReferenceNo = $request->input('partner_reference_no');
        $mockCode = $request->input('mock_code');

        try {
            $effectiveMockCode = null;
            if ($mockCode !== null && in_array($mockCode, DanaSandboxCancelPaymentService::SANDBOX_MOCK_CODES, true)) {
                $effectiveMockCode = $mockCode;
            } elseif ($partnerReferenceNo !== null && in_array($partnerReferenceNo, DanaSandboxCancelPaymentService::SANDBOX_MOCK_CODES, true)) {
                $effectiveMockCode = $partnerReferenceNo;
            }

            if ($effectiveMockCode !== null) {
                $cancelResult = $cancelService->simulateMockCancel($payment, $config, $effectiveMockCode, $reason);
            } else {
                $cancelResult = $cancelService->cancel($payment, $config, $reason);
            }
        } catch (\LogicException $e) {
            abort(422, $e->getMessage());
        } catch (\RuntimeException $e) {
            abort(503, $e->getMessage());
        }

        $succeeded = ($cancelResult['http_status'] ?? 0) === 200
            && in_array($cancelResult['response_code'] ?? null, ['2005700', '2025700'], true);

        if ($succeeded && ($cancelResult['response_code'] ?? null) === '2005700') {
            $payment->update([
                'status' => 'cancelled',
            ]);

            PaymentEvent::query()->create([
                'merchant_id' => $merchant->getKey(),
                'payment_id' => $payment->getKey(),
                'event_type' => 'payment.cancelled',
                'old_status' => 'pending',
                'new_status' => 'cancelled',
                'source' => 'merchant',
                'safe_details' => [
                    'reason' => $reason ?? 'Cancelled via Merchant API',
                    'cancel_time' => $cancelResult['cancel_time'] ?? null,
                    'response_code' => $cancelResult['response_code'],
                ],
            ]);
        }

        return response()->json([
            'data' => (new PaymentResource($payment->fresh()))->toArray($request),
            'cancel_result' => $cancelResult,
        ], $succeeded ? 200 : 422);
    }

    private function merchant(Request $request): Merchant
    {
        /** @var Merchant $merchant */
        $merchant = $request->attributes->get('merchant');

        return $merchant;
    }

    private function paymentForMerchant(Request $request, string $publicId): Payment
    {
        return Payment::query()->forMerchant($this->merchant($request))->where('public_id', $publicId)->firstOrFail();
    }
}
