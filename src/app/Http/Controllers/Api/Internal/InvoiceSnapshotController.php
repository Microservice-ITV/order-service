<?php

namespace App\Http\Controllers\Api\Internal;

use App\Http\Controllers\Controller;
use App\Services\InvoiceSnapshotService;
use Illuminate\Http\JsonResponse;

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
}