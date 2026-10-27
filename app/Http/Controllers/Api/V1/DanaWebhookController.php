<?php

namespace App\Http\Controllers\Api\V1;

use App\Exceptions\DanaWebhookSimulatedServerException;
use App\Http\Controllers\Controller;
use App\Services\DanaWebhookService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class DanaWebhookController extends Controller
{
    public function __invoke(Request $request, DanaWebhookService $webhookService): JsonResponse
    {
        try {
            $webhookService->process($request->getContent(), [
                'x-timestamp' => $request->header('X-TIMESTAMP'),
                'x-signature' => $request->header('X-SIGNATURE'),
                'x-partner-id' => $request->header('X-PARTNER-ID'),
                // getPathInfo() berasal dari request URI yang diterima aplikasi,
                // tidak menyertakan query string, dan tidak mempercayai header
                // forwarding yang dapat dikirim oleh pemanggil webhook.
                'request-path' => $request->getPathInfo(),
            ]);
        } catch (DanaWebhookSimulatedServerException) {
            return response()->json([
                'responseCode' => '5005601',
                'responseMessage' => 'Internal Server Error',
            ], 500)->header('X-TIMESTAMP', now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP'));
        } catch (RuntimeException) {
            return response()->json(['message' => 'Webhook tidak valid.'], 400);
        }

        return response()->json([
            'responseCode' => '2005600',
            'responseMessage' => 'Successful',
        ])->header('X-TIMESTAMP', now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP'));
    }
}
