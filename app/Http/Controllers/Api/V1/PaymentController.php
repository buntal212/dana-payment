<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\StorePaymentRequest;
use App\Http\Resources\PaymentResource;
use App\Models\Merchant;
use App\Models\Payment;
use App\Services\PaymentGatewayInterface;
use App\Services\PaymentService;
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
