<?php

namespace App\Repositories;

use App\Models\SagaState;
use App\Enums\SagaStep;
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
            'total_steps' => $data['total_steps'] ?? count(SagaStep::getExecutionOrder()),
            'current_step' => 1,
            'completed_steps' => [],
            'failed_steps' => [],
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
        return SagaState::where('order_id', $orderId)->first();
    }

    /**
     * Whether an event is allowed to execute its saga step now. A step that
     * was already completed is allowed through for idempotent redelivery.
     */
    public function isStepExpected(string $sagaId, string $stepName): bool
    {
        $orderedSteps = array_keys(SagaStep::getExecutionOrder());
        if (!in_array($stepName, $orderedSteps, true)) {
            return false;
        }

        $saga = $this->findById($sagaId);
        if (!$saga) {
            return false;
        }

        $completedSteps = array_values(array_unique(array_filter(
            $saga->completed_steps ?? [],
            static fn ($step): bool => is_string($step) && in_array($step, $orderedSteps, true),
        )));
        if (in_array($stepName, $completedSteps, true)) {
            return true;
        }

        if (in_array($saga->status, [
            SagaState::STATUS_COMPLETED,
            SagaState::STATUS_COMPENSATING,
            SagaState::STATUS_FAILED,
            SagaState::STATUS_TIMEOUT,
        ], true)) {
            return false;
        }

        foreach ($orderedSteps as $expectedStep) {
            if (!in_array($expectedStep, $completedSteps, true)) {
                return $expectedStep === $stepName;
            }
        }

        return false;
    }

    /**
     * Find saga by correlation ID
     */
    public function findByCorrelationId(string $correlationId): ?SagaState
    {
        return SagaState::where('correlation_id', $correlationId)->first();
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
        $orderedSteps = array_keys(SagaStep::getExecutionOrder());
        if (!in_array($stepName, $orderedSteps, true)) {
            return false;
        }

        return DB::transaction(function () use ($sagaId, $stepName, $orderedSteps): bool {
            $saga = SagaState::query()
                ->where('saga_id', $sagaId)
                ->lockForUpdate()
                ->first();

            if (!$saga) {
                return false;
            }

            // Normalize legacy duplicate / shipment entries while applying the
            // new order-placement sequence (inventory reservation, payment).
            $completedSteps = array_values(array_unique(array_filter(
                $saga->completed_steps ?? [],
                static fn ($step): bool => is_string($step) && in_array($step, $orderedSteps, true),
            )));
            $totalSteps = count($orderedSteps);

            if (in_array($stepName, $completedSteps, true)) {
                if (!in_array($saga->status, [
                    SagaState::STATUS_FAILED,
                    SagaState::STATUS_TIMEOUT,
                    SagaState::STATUS_COMPENSATING,
                ], true)) {
                    $this->persistCompletedSteps($saga, $completedSteps, $totalSteps);
                }
                return true;
            }

            if (in_array($saga->status, [
                SagaState::STATUS_FAILED,
                SagaState::STATUS_TIMEOUT,
                SagaState::STATUS_COMPENSATING,
                SagaState::STATUS_COMPLETED,
            ], true)) {
                return false;
            }

            $expectedStep = null;
            foreach ($orderedSteps as $candidate) {
                if (!in_array($candidate, $completedSteps, true)) {
                    $expectedStep = $candidate;
                    break;
                }
            }

            if ($expectedStep !== $stepName) {
                return false;
            }

            $completedSteps[] = $stepName;
            $this->persistCompletedSteps($saga, $completedSteps, $totalSteps);

            return true;
        });
    }

    /** @param list<string> $completedSteps */
    private function persistCompletedSteps(SagaState $saga, array $completedSteps, int $totalSteps): void
    {
        $isComplete = count($completedSteps) >= $totalSteps;
        $saga->forceFill([
            'completed_steps' => $completedSteps,
            'total_steps' => $totalSteps,
            'current_step' => $isComplete ? $totalSteps : count($completedSteps) + 1,
            ...($isComplete
                ? ['status' => SagaState::STATUS_COMPLETED, 'completed_at' => $saga->completed_at ?? now()]
                : []),
        ])->save();
    }

    /**
     * Fail a step
     */
    public function failStep(string $sagaId, string $stepName, string $errorMessage): bool
    {
        $orderedSteps = array_keys(SagaStep::getExecutionOrder());
        if (!in_array($stepName, $orderedSteps, true)) {
            return false;
        }

        return DB::transaction(function () use ($sagaId, $stepName, $errorMessage): bool {
            $saga = SagaState::query()
                ->where('saga_id', $sagaId)
                ->lockForUpdate()
                ->first();

            if (!$saga) {
                return false;
            }

            $failedSteps = array_values(array_unique($saga->failed_steps ?? []));
            if (in_array($stepName, $failedSteps, true)) {
                return true;
            }

            if (in_array($saga->status, [
                SagaState::STATUS_COMPLETED,
                SagaState::STATUS_FAILED,
                SagaState::STATUS_TIMEOUT,
            ], true)) {
                return false;
            }

            if (in_array($stepName, $saga->completed_steps ?? [], true)) {
                return false;
            }

            $completedSteps = $saga->completed_steps ?? [];
            $expectedStep = null;
            foreach (array_keys(SagaStep::getExecutionOrder()) as $candidate) {
                if (!in_array($candidate, $completedSteps, true)) {
                    $expectedStep = $candidate;
                    break;
                }
            }
            if ($expectedStep !== $stepName) {
                return false;
            }

            $failedSteps[] = $stepName;
            $saga->forceFill([
                'failed_steps' => $failedSteps,
                'error_message' => $errorMessage,
                'compensation_required' => true,
                'status' => SagaState::STATUS_COMPENSATING,
            ])->save();

            return true;
        });
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
