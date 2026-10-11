<?php

namespace App\Http\Controllers\Api\V1;

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
            ]);
        } catch (RuntimeException) {
            return response()->json(['message' => 'Webhook tidak valid.'], 400);
        }

        return response()->json([
            'responseCode' => '2005600',
            'responseMessage' => 'Successful',
        ])->header('X-TIMESTAMP', now('Asia/Jakarta')->format('Y-m-d\\TH:i:sP'));
    }
}
