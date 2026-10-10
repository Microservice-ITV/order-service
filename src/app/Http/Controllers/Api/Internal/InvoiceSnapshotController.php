<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\InvoiceSnapshotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

final class InvoiceSnapshotController extends Controller
{
    public function __construct(
        private readonly InvoiceSnapshotService $invoiceSnapshotService
    ) {}

    public function show(string $orderId): JsonResponse
    {
        return response()->json(
            $this->invoiceSnapshotService->buildForOrder($orderId)
        );
    }

    public function showByCheckout(string $checkoutId): JsonResponse
    {
        return response()->json(
            $this->invoiceSnapshotService->buildForCheckout($checkoutId)
        );
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'limit' => ['sometimes', 'integer', 'min:1', 'max:100'],
            'cursor' => ['sometimes', 'nullable', 'string', 'max:512'],
        ]);

        return response()->json(
            $this->invoiceSnapshotService->listInvoiceCandidates(
                (int) ($validated['limit'] ?? 50),
                $validated['cursor'] ?? null,
            )
        );
    }
}