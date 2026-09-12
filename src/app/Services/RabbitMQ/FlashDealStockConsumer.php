<?php

namespace App\Services\RabbitMQ;

use App\Enums\EventTopic;
use App\Services\SagaOrchestrator;
use Illuminate\Support\Facades\Log;
use Throwable;

class FlashDealStockConsumer extends BaseConsumer
{
    protected array $routingKeys = [
        EventTopic::FLASH_DEAL_STOCK_RESERVED,
        EventTopic::FLASH_DEAL_STOCK_REJECTED,
        EventTopic::FLASH_DEAL_STOCK_RELEASE_COMPLETED,
    ];

    private SagaOrchestrator $sagaOrchestrator;

    public function __construct(SagaOrchestrator $sagaOrchestrator)
    {
        $this->sagaOrchestrator = $sagaOrchestrator;
    }

    protected function queue(): string
    {
        return config('rabbitmq.queues.flash_deal_stock', 'order_service.flash_deal_stock');
    }

    protected function routingKeys(): array
    {
        return $this->routingKeys;
    }

    public function process(array $payload): void
    {
        $event = $payload['event'] ?? '';
        $sagaId = $payload['saga_id'] ?? '';
        $step = $payload['step'] ?? '';

        if ($event === '' || $sagaId === '') {
            throw new \RuntimeException('Missing event or saga_id in flash deal stock payload');
        }

        Log::info('Flash deal stock event received', [
            'event' => $event,
            'saga_id' => $sagaId,
            'step' => $step,
        ]);

        try {
            switch ($event) {
                case EventTopic::FLASH_DEAL_STOCK_RESERVED:
                    $this->handleStockReserved($sagaId, $payload);
                    break;

                case EventTopic::FLASH_DEAL_STOCK_REJECTED:
                    $this->handleStockRejected($sagaId, $payload);
                    break;

                case EventTopic::FLASH_DEAL_STOCK_RELEASE_COMPLETED:
                    $this->handleReleaseCompleted($sagaId, $payload);
                    break;

                default:
                    Log::warning('Unknown flash deal stock event', ['event' => $event]);
                    break;
            }
        } catch (Throwable $e) {
            Log::error('Failed to process flash deal stock event', [
                'event' => $event,
                'saga_id' => $sagaId,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Handle flash deal stock reserved - proceed to payment step
     */
    private function handleStockReserved(string $sagaId, array $payload): void
    {
        Log::info('Flash deal stock reserved', [
            'saga_id' => $sagaId,
            'flash_deal_item_ids' => $payload['data']['flash_deal_item_ids'] ?? [],
        ]);

        $this->sagaOrchestrator->completeStep($sagaId, 'FLASH_DEAL_INVENTORY_RESERVATION');
    }

    /**
     * Handle flash deal stock rejected - trigger compensation
     */
    private function handleStockRejected(string $sagaId, array $payload): void
    {
        $reason = $payload['data']['reason'] ?? 'UNKNOWN';

        Log::warning('Flash deal stock rejected', [
            'saga_id' => $sagaId,
            'reason' => $reason,
        ]);

        $this->sagaOrchestrator->failStep($sagaId, 'FLASH_DEAL_INVENTORY_RESERVATION', "Flash deal stock rejected: {$reason}");
    }

    /**
     * Handle flash deal stock release completed (compensation)
     */
    private function handleReleaseCompleted(string $sagaId, array $payload): void
    {
        Log::info('Flash deal stock release completed', [
            'saga_id' => $sagaId,
        ]);

        $saga = $this->sagaOrchestrator->getSagaRepository()->findById($sagaId);
        if ($saga) {
            $this->sagaOrchestrator->executeNextStep($sagaId);
        }
    }
}