<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CollectOrderPaymentController extends Controller
{
    public function __invoke(Request $request, string $orderId): JsonResponse
    {
        $request->merge(['order_ids' => [$orderId]]);
        $batchResponse = app(CollectCodBatchController::class)($request);
        if ($batchResponse->getStatusCode() >= 400) return $batchResponse;

        $batch = $batchResponse->getData(true);
        $payment = $batch['payments'][0] ?? [];
        return response()->json([
            'order_id' => $orderId,
            'payment_id' => $payment['paymentId'] ?? null,
            'status' => $payment['status'] ?? null,
            'batch_id' => $batch['batch_id'] ?? null,
        ]);
    }
}
