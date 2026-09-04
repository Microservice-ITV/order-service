<?php

namespace App\Services\RabbitMQ;

use App\Enums\EventTopic;
use App\Exceptions\SagaException;
use App\Models\SagaState;
use App\Repositories\SagaRepository;
use App\Services\SagaOrchestrator;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Saga Consumer
 * 
 * Consumes saga-related events and coordinates saga execution.
 */
class SagaConsumer extends BaseConsumer
{
    protected string $queue = 'saga_events';
    protected array $topics = [
        EventTopic::SAGA_ORDER_CREATED,
        EventTopic::SAGA_INVENTORY_RESERVATION_COMPLETED,
        EventTopic::SAGA_INVENTORY_RESERVATION_FAILED,
        EventTopic::SAGA_PAYMENT_COMPLETED,
        EventTopic::SAGA_PAYMENT_FAILED,
        EventTopic::SAGA_SHIPMENT_COMPLETED,
        EventTopic::SAGA_SHIPMENT_FAILED,
        EventTopic::INVENTORY_RELEASE_COMPLETED,
        EventTopic::PAYMENT_CANCEL_COMPLETED,
        EventTopic::SHIPMENT_CANCEL_COMPLETED,
    ];

    private SagaOrchestrator $sagaOrchestrator;
    private SagaRepository $sagaRepository;

    public function __construct(
        SagaOrchestrator $sagaOrchestrator,
        SagaRepository $sagaRepository
    ) {
        $this->sagaOrchestrator = $sagaOrchestrator;
        $this->sagaRepository = $sagaRepository;
    }

    protected function queue(): string
    {
        return $this->queue;
    }

    protected function routingKeys(): array
    {
        return $this->topics;
    }

    public function process(array $payload): void
    {
        $event = $payload['event'] ?? '';
        $sagaId = isset($payload['saga_id']) ? (string) $payload['saga_id'] : '';
        $step = isset($payload['step']) ? (string) $payload['step'] : '';

        if ($event === '') {
            throw new SagaException('Missing event key in payload');
        }

        Log::info('Saga event received', [
            'event' => $event,
            'saga_id' => $sagaId,
            'step' => $step,
        ]);

        switch ($event) {
            case EventTopic::SAGA_ORDER_CREATED:
                $this->handleSagaCreated($payload);
                break;

            case EventTopic::SAGA_INVENTORY_RESERVATION_COMPLETED:
                $this->handleStepCompleted($sagaId, 'INVENTORY_RESERVATION');
                break;

            case EventTopic::SAGA_INVENTORY_RESERVATION_FAILED:
                $this->handleStepFailed($sagaId, 'INVENTORY_RESERVATION', $payload['error'] ?? 'Unknown error');
                break;

            case EventTopic::SAGA_PAYMENT_COMPLETED:
                $this->handleStepCompleted($sagaId, 'PAYMENT_PROCESSING');
                break;

            case EventTopic::SAGA_PAYMENT_FAILED:
                $this->handleStepFailed($sagaId, 'PAYMENT_PROCESSING', $payload['error'] ?? 'Unknown error');
                break;

            case EventTopic::SAGA_SHIPMENT_COMPLETED:
                $this->handleStepCompleted($sagaId, 'SHIPMENT_CREATION');
                break;

            case EventTopic::SAGA_SHIPMENT_FAILED:
                $this->handleStepFailed($sagaId, 'SHIPMENT_CREATION', $payload['error'] ?? 'Unknown error');
                break;

            case EventTopic::INVENTORY_RELEASE_COMPLETED:
                $this->handleCompensationCompleted($sagaId, 'INVENTORY_RELEASE');
                break;

            case EventTopic::PAYMENT_CANCEL_COMPLETED:
                $this->handleCompensationCompleted($sagaId, 'PAYMENT_CANCELLATION');
                break;

            case EventTopic::SHIPMENT_CANCEL_COMPLETED:
                $this->handleCompensationCompleted($sagaId, 'SHIPMENT_CANCELLATION');
                break;

            default:
                Log::warning('Unknown saga event', ['event' => $event]);
                break;
        }
    }

    /**
     * Handle saga creation event
     */
    private function handleSagaCreated(array $payload): void
    {
        $sagaId = $payload['saga_id'];
        $type = $payload['type'];
        $data = $payload['data'];

        Log::info('Creating new saga', [
            'saga_id' => $sagaId,
            'type' => $type,
        ]);

        $this->sagaOrchestrator->startSaga($type, $data);
    }

    /**
     * Handle step completion
     */
    private function handleStepCompleted(string $sagaId, string $stepName): void
    {
        Log::info('Step completed', [
            'saga_id' => $sagaId,
            'step' => $stepName,
        ]);

        $this->sagaOrchestrator->completeStep($sagaId, $stepName);
    }

    /**
     * Handle step failure
     */
    private function handleStepFailed(string $sagaId, string $stepName, string $errorMessage): void
    {
        Log::error('Step failed', [
            'saga_id' => $sagaId,
            'step' => $stepName,
            'error' => $errorMessage,
        ]);

        $this->sagaOrchestrator->failStep($sagaId, $stepName, $errorMessage);
    }

    /**
     * Handle compensation completion
     */
    private function handleCompensationCompleted(string $sagaId, string $stepName): void
    {
        Log::info('Compensation completed', [
            'saga_id' => $sagaId,
            'step' => $stepName,
        ]);

        $saga = $this->sagaRepository->findById($sagaId);
        if ($saga) {
            $this->sagaOrchestrator->executeNextStep($sagaId);
        }
    }

    /**
     * Process timeouts periodically
     */
    public function processTimeouts(): void
    {
        $this->sagaOrchestrator->processTimeouts();
    }

    /**
     * Cleanup old sagas
     */
    public function cleanupOldSagas(int $days = 7): int
    {
        return $this->sagaOrchestrator->cleanupOldSagas($days);
    }
}