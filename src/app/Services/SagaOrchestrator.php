<?php

namespace App\Services;

use App\Enums\EventTopic;
use App\Enums\SagaStep;
use App\Exceptions\SagaException;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use App\Services\RabbitMQ\Publisher;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saga Orchestrator
 * 
 * Coordinates the execution of sagas using the orchestration pattern.
 * Manages saga lifecycle, step execution, and compensation.
 */
class SagaOrchestrator
{
    private SagaRepository $sagaRepository;
    private Publisher $eventPublisher;
    private int $stepTimeout = 30000; // 30 seconds per step

    public function __construct(
        SagaRepository $sagaRepository,
        Publisher $eventPublisher
    ) {
        $this->sagaRepository = $sagaRepository;
        $this->eventPublisher = $eventPublisher;
    }

    /**
     * Get saga repository (for external access)
     */
    public function getSagaRepository(): SagaRepository
    {
        return $this->sagaRepository;
    }

    /**
     * Start a new saga
     */
    public function startSaga(string $type, array $data): string
    {
        $sagaId = (string) \Illuminate\Support\Str::uuid();
        
        // Detect flash deal items in cart
        $hasFlashDealItems = $this->hasFlashDealItems($data['items'] ?? []);
        $flashDealItemIds = $this->extractFlashDealItemIds($data['items'] ?? []);
        
        $sagaData = array_merge($data, [
            'has_flash_deal_items' => $hasFlashDealItems,
            'flash_deal_item_ids' => $flashDealItemIds,
        ]);
        
        // Calculate total steps based on flash deal items
        $totalSteps = count(SagaStep::ORDER_CREATION_STEPS);
        if (!$hasFlashDealItems) {
            // Skip flash deal step if no flash deal items
            $totalSteps--;
        }

        $saga = $this->sagaRepository->create([
            'saga_id' => $sagaId,
            'type' => $type,
            'order_id' => $data['order_id'] ?? null,
            'correlation_id' => $data['correlation_id'] ?? null,
            'data' => $sagaData,
            'total_steps' => $totalSteps,
        ]);

        $saga->start();

        // Publish saga start event
        $this->eventPublisher->publish(EventTopic::SAGA_ORDER_CREATED, [
            'saga_id' => $sagaId,
            'type' => $type,
            'data' => $sagaData,
            'timestamp' => now()->toISOString(),
        ]);

        Log::info('Saga started', [
            'saga_id' => $sagaId,
            'type' => $type,
            'order_id' => $data['order_id'] ?? null,
            'has_flash_deal_items' => $hasFlashDealItems,
        ]);

        return $sagaId;
    }

    /**
     * Check if cart items contain flash deal items
     */
    private function hasFlashDealItems(array $items): bool
    {
        foreach ($items as $item) {
            if (isset($item['is_flash_deal']) && $item['is_flash_deal'] === true) {
                return true;
            }
            if (isset($item['flash_deal_item_id']) && !empty($item['flash_deal_item_id'])) {
                return true;
            }
        }
        return false;
    }

    /**
     * Extract flash deal item IDs from cart items
     */
    private function extractFlashDealItemIds(array $items): array
    {
        $flashDealItemIds = [];
        foreach ($items as $item) {
            if (isset($item['flash_deal_item_id']) && !empty($item['flash_deal_item_id'])) {
                $flashDealItemIds[] = $item['flash_deal_item_id'];
            }
        }
        return $flashDealItemIds;
    }

    /**
     * Execute the next step in the saga
     */
    public function executeNextStep(string $sagaId): void
    {
        $saga = $this->sagaRepository->findById($sagaId);
        if (!$saga) {
            throw new SagaException("Saga not found: {$sagaId}");
        }

        // Check if saga is completed or failed
        if (in_array($saga->status, [SagaState::STATUS_COMPLETED, SagaState::STATUS_FAILED])) {
            return;
        }

        // Check for timeout
        if ($saga->isTimedOut()) {
            $this->handleTimeout($saga);
            return;
        }

        // Execute compensation if required
        if ($saga->compensation_required) {
            $this->executeCompensation($saga);
            return;
        }

        // Execute next normal step
        $this->executeStep($saga);
    }

    /**
     * Get the applicable execution steps for a saga
     */
    private function getApplicableSteps(SagaState $saga): array
    {
        $steps = SagaStep::getExecutionOrder();
        
        // If no flash deal items, skip the flash deal inventory reservation step
        if (!($saga->data['has_flash_deal_items'] ?? false)) {
            unset($steps['FLASH_DEAL_INVENTORY_RESERVATION']);
        }
        
        return $steps;
    }

    /**
     * Execute a specific step
     */
    private function executeStep(SagaState $saga): void
    {
        $steps = $this->getApplicableSteps($saga);
        $currentStepName = null;
        
        // Find the next step to execute
        foreach ($steps as $stepName => $step) {
            if (!in_array($stepName, $saga->completed_steps ?? [])) {
                $currentStepName = $stepName;
                break;
            }
        }

        if (!$currentStepName) {
            $this->sagaRepository->complete($saga->saga_id);
            return;
        }

        $step = SagaStep::getStep($currentStepName);
        if (!$step) {
            throw new SagaException("Step not found: {$currentStepName}");
        }

        Log::info('Executing saga step', [
            'saga_id' => $saga->saga_id,
            'step' => $currentStepName,
            'step_order' => $step['order'],
        ]);

        // Prepare step data with flash deal item IDs if applicable
        $stepData = $saga->data;
        if ($currentStepName === 'FLASH_DEAL_INVENTORY_RESERVATION') {
            $stepData['flash_deal_item_ids'] = $saga->data['flash_deal_item_ids'] ?? [];
        }

        try {
            // Publish step event
            $this->eventPublisher->publish($step['event'], [
                'saga_id' => $saga->saga_id,
                'step' => $currentStepName,
                'step_order' => $step['order'],
                'data' => $stepData,
                'correlation_id' => $saga->correlation_id,
                'timeout' => $this->stepTimeout,
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to execute saga step', [
                'saga_id' => $saga->saga_id,
                'step' => $currentStepName,
                'error' => $e->getMessage(),
            ]);

            $this->sagaRepository->failStep($saga->saga_id, $currentStepName, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Handle step completion
     */
    public function completeStep(string $sagaId, string $stepName): void
    {
        $saga = $this->sagaRepository->findById($sagaId);
        if (!$saga) {
            throw new SagaException("Saga not found: {$sagaId}");
        }

        $step = SagaStep::getStep($stepName);
        if (!$step) {
            throw new SagaException("Step not found: {$stepName}");
        }

        Log::info('Saga step completed', [
            'saga_id' => $sagaId,
            'step' => $stepName,
        ]);

        $this->sagaRepository->completeStep($sagaId, $stepName);

        // Publish completion event
        $this->eventPublisher->publish($step['completion_event'], [
            'saga_id' => $sagaId,
            'step' => $stepName,
            'data' => $saga->data,
            'correlation_id' => $saga->correlation_id,
        ]);

        // Execute next step
        $this->executeNextStep($sagaId);
    }

    /**
     * Handle step failure
     */
    public function failStep(string $sagaId, string $stepName, string $errorMessage): void
    {
        $saga = $this->sagaRepository->findById($sagaId);
        if (!$saga) {
            throw new SagaException("Saga not found: {$sagaId}");
        }

        Log::error('Saga step failed', [
            'saga_id' => $sagaId,
            'step' => $stepName,
            'error' => $errorMessage,
        ]);

        $this->sagaRepository->failStep($sagaId, $stepName, $errorMessage);

        // Publish failure event
        $step = SagaStep::getStep($stepName);
        if ($step) {
            $this->eventPublisher->publish($step['failure_event'], [
                'saga_id' => $sagaId,
                'step' => $stepName,
                'error' => $errorMessage,
                'data' => $saga->data,
                'correlation_id' => $saga->correlation_id,
            ]);
        }
    }

    /**
     * Get the applicable compensation steps for a saga
     */
    private function getApplicableCompensationSteps(SagaState $saga): array
    {
        $steps = SagaStep::getCompensationOrder();
        
        // If no flash deal items, skip the flash deal inventory release step
        if (!($saga->data['has_flash_deal_items'] ?? false)) {
            unset($steps['FLASH_DEAL_INVENTORY_RELEASE']);
        }
        
        return $steps;
    }

    /**
     * Execute compensation
     */
    private function executeCompensation(SagaState $saga): void
    {
        $compensationSteps = $this->getApplicableCompensationSteps($saga);
        
        // Find next compensation step to execute
        $nextCompensationStep = null;
        foreach ($compensationSteps as $stepName => $step) {
            // Check if all dependencies are completed
            $dependenciesMet = true;
            foreach ($step['depends_on'] as $dependency) {
                if (!in_array($dependency, $saga->completed_steps ?? [])) {
                    $dependenciesMet = false;
                    break;
                }
            }
            
            if ($dependenciesMet && !in_array($stepName, $saga->completed_steps ?? [])) {
                $nextCompensationStep = $stepName;
                break;
            }
        }

        if (!$nextCompensationStep) {
            // All compensation steps completed
            $this->sagaRepository->completeCompensation($saga->saga_id);
            return;
        }

        $step = SagaStep::getCompensationStep($nextCompensationStep);
        if (!$step) {
            throw new SagaException("Compensation step not found: {$nextCompensationStep}");
        }

        Log::info('Executing compensation step', [
            'saga_id' => $saga->saga_id,
            'step' => $nextCompensationStep,
        ]);

        // Prepare compensation data with flash deal item IDs if applicable
        $compensationData = $saga->data;
        if ($nextCompensationStep === 'FLASH_DEAL_INVENTORY_RELEASE') {
            $compensationData['flash_deal_item_ids'] = $saga->data['flash_deal_item_ids'] ?? [];
        }

        try {
            // Publish compensation event
            $this->eventPublisher->publish($step['event'], [
                'saga_id' => $saga->saga_id,
                'step' => $nextCompensationStep,
                'data' => $compensationData,
                'correlation_id' => $saga->correlation_id,
            ]);

        } catch (Throwable $e) {
            Log::error('Failed to execute compensation step', [
                'saga_id' => $saga->saga_id,
                'step' => $nextCompensationStep,
                'error' => $e->getMessage(),
            ]);

            throw $e;
        }
    }

    /**
     * Handle saga timeout
     */
    private function handleTimeout(SagaState $saga): void
    {
        Log::warning('Saga timeout', [
            'saga_id' => $saga->saga_id,
            'timeout_at' => $saga->timeout_at,
        ]);

        $this->sagaRepository->update($saga->saga_id, [
            'status' => SagaState::STATUS_TIMEOUT,
            'error_message' => 'Saga timeout',
            'completed_at' => now(),
        ]);

        $this->eventPublisher->publish(EventTopic::SAGA_FAILED, [
            'saga_id' => $saga->saga_id,
            'error' => 'Saga timeout',
            'data' => $saga->data,
            'correlation_id' => $saga->correlation_id,
        ]);
    }

    /**
     * Process timed out sagas
     */
    public function processTimeouts(): void
    {
        $timedOutSagas = $this->sagaRepository->getTimedOutSagas();
        
        foreach ($timedOutSagas as $saga) {
            $this->handleTimeout($saga);
        }
    }

    /**
     * Cleanup old sagas
     */
    public function cleanupOldSagas(int $days = 7): int
    {
        return $this->sagaRepository->cleanupOldSagas($days);
    }
}