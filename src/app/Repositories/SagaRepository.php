<?php

namespace App\Repositories;

use App\Models\SagaState;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Carbon\Carbon;

/**
 * Saga Repository
 * 
 * Handles database operations for saga state management.
 */
class SagaRepository
{
    /**
     * Create a new saga
     */
    public function create(array $data): SagaState
    {
        return SagaState::create([
            'saga_id' => $data['saga_id'] ?? (string) Str::uuid(),
            'type' => $data['type'] ?? SagaState::TYPE_ORDER_CREATION,
            'status' => SagaState::STATUS_PENDING,
            'order_id' => $data['order_id'] ?? null,
            'correlation_id' => $data['correlation_id'] ?? null,
            'data' => $data['data'] ?? [],
            'step_order' => $data['step_order'] ?? 1,
            'total_steps' => $data['total_steps'] ?? 3,
            'started_at' => null,
            'completed_at' => null,
            'timeout_at' => $data['timeout_at'] ?? now()->addMinutes(30),
        ]);
    }

    /**
     * Find saga by ID
     */
    public function findById(string $sagaId): ?SagaState
    {
        return SagaState::where('saga_id', $sagaId)->first();
    }

    /**
     * Find saga by order ID
     */
    public function findByOrderId(string $orderId): ?SagaState
    {
        return SagaState::where('order_id', $orderId)->active()->first();
    }

    /**
     * Find saga by correlation ID
     */
    public function findByCorrelationId(string $correlationId): ?SagaState
    {
        return SagaState::where('correlation_id', $correlationId)->active()->first();
    }

    /**
     * Update saga state
     */
    public function update(string $sagaId, array $data): bool
    {
        return SagaState::where('saga_id', $sagaId)->update($data);
    }

    /**
     * Mark saga as started
     */
    public function start(string $sagaId): bool
    {
        return SagaState::where('saga_id', $sagaId)->update([
            'status' => SagaState::STATUS_IN_PROGRESS,
            'started_at' => now(),
            'current_step' => 1,
        ]);
    }

    /**
     * Complete a step
     */
    public function completeStep(string $sagaId, string $stepName): bool
    {
        $saga = $this->findById($sagaId);
        if (!$saga) {
            return false;
        }

        $completedSteps = $saga->completed_steps ?? [];
        $completedSteps[] = $stepName;
        
        $updateData = [
            'completed_steps' => $completedSteps,
            'current_step' => count($completedSteps) + 1,
        ];

        // Check if saga is completed
        if (count($completedSteps) >= $saga->total_steps) {
            $updateData['status'] = SagaState::STATUS_COMPLETED;
            $updateData['completed_at'] = now();
        }

        return SagaState::where('saga_id', $sagaId)->update($updateData);
    }

    /**
     * Fail a step
     */
    public function failStep(string $sagaId, string $stepName, string $errorMessage): bool
    {
        $saga = $this->findById($sagaId);
        if (!$saga) {
            return false;
        }

        $failedSteps = $saga->failed_steps ?? [];
        $failedSteps[] = $stepName;
        
        $updateData = [
            'failed_steps' => $failedSteps,
            'error_message' => $errorMessage,
        ];

        // Trigger compensation if not already triggered
        if (!$saga->compensation_required) {
            $updateData['compensation_required'] = true;
            $updateData['status'] = SagaState::STATUS_COMPENSATING;
        }

        return SagaState::where('saga_id', $sagaId)->update($updateData);
    }

    /**
     * Trigger compensation
     */
    public function triggerCompensation(string $sagaId): bool
    {
        return SagaState::where('saga_id', $sagaId)->update([
            'compensation_required' => true,
            'status' => SagaState::STATUS_COMPENSATING,
        ]);
    }

    /**
     * Complete compensation
     */
    public function completeCompensation(string $sagaId): bool
    {
        return SagaState::where('saga_id', $sagaId)->update([
            'status' => SagaState::STATUS_FAILED,
            'compensation_status' => 'COMPLETED',
            'completed_at' => now(),
        ]);
    }

    /**
     * Complete saga
     */
    public function complete(string $sagaId): bool
    {
        return SagaState::where('saga_id', $sagaId)->update([
            'status' => SagaState::STATUS_COMPLETED,
            'completed_at' => now(),
        ]);
    }

    /**
     * Get timed out sagas
     */
    public function getTimedOutSagas(): \Illuminate\Database\Eloquent\Collection
    {
        return SagaState::where('timeout_at', '<=', now())
            ->whereIn('status', [SagaState::STATUS_PENDING, SagaState::STATUS_IN_PROGRESS])
            ->get();
    }

    /**
     * Clean up old completed sagas
     */
    public function cleanupOldSagas(int $days = 7): int
    {
        return SagaState::where('status', '!=', SagaState::STATUS_IN_PROGRESS)
            ->where('created_at', '<=', now()->subDays($days))
            ->delete();
    }
}