<?php

namespace App\Enums;

use App\Enums\EventTopic;

/**
 * Saga Step Definitions
 * 
 * Defines the steps in the order creation saga and their sequence.
 */
class SagaStep
{
    public const ORDER_CREATION_STEPS = [
        'INVENTORY_RESERVATION' => [
            'order' => 1,
            'name' => 'Inventory Reservation',
            'event' => EventTopic::SAGA_INVENTORY_RESERVATION_REQUESTED,
            'completion_event' => EventTopic::SAGA_INVENTORY_RESERVATION_COMPLETED,
            'failure_event' => EventTopic::SAGA_INVENTORY_RESERVATION_FAILED,
            'compensation_event' => EventTopic::INVENTORY_RELEASE_REQUESTED,
        ],
        'FLASH_DEAL_INVENTORY_RESERVATION' => [
            'order' => 2,
            'name' => 'Flash Deal Inventory Reservation',
            'event' => EventTopic::FLASH_DEAL_STOCK_RESERVE_REQUESTED,
            'completion_event' => EventTopic::FLASH_DEAL_STOCK_RESERVED,
            'failure_event' => EventTopic::FLASH_DEAL_STOCK_REJECTED,
            'compensation_event' => EventTopic::FLASH_DEAL_STOCK_RELEASE_REQUESTED,
        ],
        'PAYMENT_PROCESSING' => [
            'order' => 3,
            'name' => 'Payment Processing',
            'event' => EventTopic::SAGA_PAYMENT_REQUESTED,
            'completion_event' => EventTopic::SAGA_PAYMENT_COMPLETED,
            'failure_event' => EventTopic::SAGA_PAYMENT_FAILED,
            'compensation_event' => EventTopic::PAYMENT_CANCEL_REQUESTED,
        ],
        'SHIPMENT_CREATION' => [
            'order' => 4,
            'name' => 'Shipment Creation',
            'event' => EventTopic::SAGA_SHIPMENT_REQUESTED,
            'completion_event' => EventTopic::SAGA_SHIPMENT_COMPLETED,
            'failure_event' => EventTopic::SAGA_SHIPMENT_FAILED,
            'compensation_event' => EventTopic::SHIPMENT_CANCEL_REQUESTED,
        ],
    ];

    public const COMPENSATION_STEPS = [
        'SHIPMENT_CANCELLATION' => [
            'order' => 1,
            'name' => 'Shipment Cancellation',
            'event' => EventTopic::SHIPMENT_CANCEL_REQUESTED,
            'completion_event' => EventTopic::SHIPMENT_CANCEL_COMPLETED,
            'depends_on' => ['PAYMENT_PROCESSING', 'INVENTORY_RESERVATION', 'FLASH_DEAL_INVENTORY_RESERVATION'],
        ],
        'PAYMENT_CANCELLATION' => [
            'order' => 2,
            'name' => 'Payment Cancellation',
            'event' => EventTopic::PAYMENT_CANCEL_REQUESTED,
            'completion_event' => EventTopic::PAYMENT_CANCEL_COMPLETED,
            'depends_on' => ['INVENTORY_RESERVATION', 'FLASH_DEAL_INVENTORY_RESERVATION'],
        ],
        'FLASH_DEAL_INVENTORY_RELEASE' => [
            'order' => 3,
            'name' => 'Flash Deal Inventory Release',
            'event' => EventTopic::FLASH_DEAL_STOCK_RELEASE_REQUESTED,
            'completion_event' => EventTopic::FLASH_DEAL_STOCK_RELEASE_COMPLETED,
            'depends_on' => ['INVENTORY_RESERVATION'],
        ],
        'INVENTORY_RELEASE' => [
            'order' => 4,
            'name' => 'Inventory Release',
            'event' => EventTopic::INVENTORY_RELEASE_REQUESTED,
            'completion_event' => EventTopic::INVENTORY_RELEASE_COMPLETED,
            'depends_on' => [],
        ],
    ];

    /**
     * Get steps in execution order
     */
    public static function getExecutionOrder(): array
    {
        $steps = self::ORDER_CREATION_STEPS;
        uasort($steps, fn($a, $b) => $a['order'] <=> $b['order']);
        return $steps;
    }

    /**
     * Get compensation steps in reverse order
     */
    public static function getCompensationOrder(): array
    {
        $steps = self::COMPENSATION_STEPS;
        uasort($steps, fn($a, $b) => $a['order'] <=> $b['order']);
        return $steps;
    }

    /**
     * Get step by name
     */
    public static function getStep(string $stepName): ?array
    {
        return self::ORDER_CREATION_STEPS[$stepName] ?? null;
    }

    /**
     * Get compensation step by name
     */
    public static function getCompensationStep(string $stepName): ?array
    {
        return self::COMPENSATION_STEPS[$stepName] ?? null;
    }

    /**
     * Get next step in sequence
     */
    public static function getNextStep(string $currentStep): ?string
    {
        $steps = self::getExecutionOrder();
        $stepNames = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepNames);
        
        if ($currentIndex !== false && $currentIndex < count($stepNames) - 1) {
            return $stepNames[$currentIndex + 1];
        }
        
        return null;
    }

    /**
     * Get previous step for compensation
     */
    public static function getPreviousCompensationStep(string $currentStep): ?string
    {
        $steps = self::getCompensationOrder();
        $stepNames = array_keys($steps);
        $currentIndex = array_search($currentStep, $stepNames);
        
        if ($currentIndex > 0) {
            return $stepNames[$currentIndex - 1];
        }
        
        return null;
    }
}