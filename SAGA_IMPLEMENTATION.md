# Saga Pattern Implementation

This document describes the Saga pattern implementation with orchestration approach in the Order Service for achieving eventual consistency across microservices.

## Overview

The Saga pattern implementation ensures eventual consistency across distributed services by using an orchestration approach. The Order Service acts as the saga orchestrator, coordinating operations across Product Service, Payment Service, and Shipping Service.

## Architecture

### Components

1. **SagaOrchestrator** - Central coordinator that manages saga lifecycle
2. **SagaState** - Model that tracks saga progress and state
3. **SagaRepository** - Data access layer for saga state management
4. **SagaCheckoutService** - Service that integrates sagas with checkout flow
5. **SagaConsumer** - RabbitMQ consumer that processes saga events
6. **SagaCheckoutController** - API endpoints for saga-based operations

### Event Flow

```
Order Created → Inventory Reservation → Payment Processing → Shipment Creation → Saga Complete
       ↓                ↓                  ↓                  ↓
    Inventory      Payment             Shipment          Saga
    Rejected      Failed             Failed           Failed
       ↓                ↓                  ↓                  ↓
    Inventory      Payment             Shipment          Compensation
    Released      Cancelled           Cancelled         Triggered
```

## Usage

### Starting a Saga

```php
// Use SagaCheckoutService to start a saga-based checkout
$result = $sagaCheckoutService->startSagaCheckout(
    $items,
    $userId,
    $idempotencyKey,
    $deliveryAddress
);

// Returns:
// [
//     'checkout' => CheckoutSession,
//     'existing' => false,
//     'saga_id' => 'uuid',
//     'message' => 'Checkout started with saga orchestration'
// ]
```

### Checking Saga Status

```php
// Get saga status
$status = $sagaCheckoutService->getSagaStatus($sagaId);

// Returns:
// [
//     'saga_id' => 'uuid',
//     'status' => 'IN_PROGRESS',
//     'current_step' => 2,
//     'total_steps' => 3,
//     'completed_steps' => ['INVENTORY_RESERVATION'],
//     'failed_steps' => [],
//     'compensation_required' => false,
//     'error_message' => null,
//     'started_at' => '2024-01-01T10:00:00Z',
//     'completed_at' => null
// ]
```

### API Endpoints

#### Saga Checkout
```http
POST /api/saga/orders/checkout
Content-Type: application/json

{
    "items": [
        {
            "product_id": "prod-123",
            "qty": 2
        }
    ],
    "user_id": "user-456",
    "idempotency_key": "unique-key-123",
    "delivery_address": {
        "street": "123 Main St",
        "city": "New York",
        "zip": "10001"
    }
}
```

#### Complete Checkout
```http
POST /api/saga/orders/{checkoutId}/complete
Content-Type: application/json

{
    "payment_data": {
        "payment_method": "credit_card",
        "token": "pay_token_123"
    }
}
```

#### Get Saga Status
```http
GET /api/saga/sagas/{sagaId}
```

#### Get Saga Statistics
```http
GET /api/saga/sagas/statistics
```

### Console Commands

#### Process Timeouts
```bash
php artisan saga:manage process-timeouts --limit=100
```

#### Cleanup Old Sagas
```bash
php artisan saga:manage cleanup-old-sagas --days=7
```

#### Show Statistics
```bash
php artisan saga:manage statistics
```

## Event Types

### Saga Events
- `saga.order.created` - Saga started
- `saga.inventory.reservation.requested` - Inventory reservation initiated
- `saga.inventory.reservation.completed` - Inventory reservation successful
- `saga.inventory.reservation.failed` - Inventory reservation failed
- `saga.payment.requested` - Payment processing initiated
- `saga.payment.completed` - Payment successful
- `saga.payment.failed` - Payment failed
- `saga.shipment.requested` - Shipment creation initiated
- `saga.shipment.completed` - Shipment created successfully
- `saga.shipment.failed` - Shipment creation failed
- `saga.completed` - Saga completed successfully
- `saga.failed` - Saga failed

### Compensation Events
- `inventory.release.requested` - Inventory release initiated
- `inventory.release.completed` - Inventory release completed
- `payment.cancel.requested` - Payment cancellation initiated
- `payment.cancel.completed` - Payment cancellation completed
- `shipment.cancel.requested` - Shipment cancellation initiated
- `shipment.cancel.completed` - Shipment cancellation completed

## Saga States

### Status Values
- `PENDING` - Saga created but not started
- `IN_PROGRESS` - Saga is executing steps
- `COMPLETED` - Saga completed successfully
- `COMPENSATING` - Saga is executing compensation
- `FAILED` - Saga failed (compensation may or may not have completed)
- `TIMEOUT` - Saga timed out

### Step States
- `completed_steps` - Array of completed step names
- `failed_steps` - Array of failed step names
- `current_step` - Current step being executed
- `total_steps` - Total steps in the saga

## Error Handling

### SagaException
Custom exception for saga-related errors with context information:

```php
throw new SagaException(
    'Step failed',
    $sagaId,
    $stepName,
    ['context' => 'additional data']
);
```

### Compensation Trigger
When any step fails:
1. Saga state is updated to `COMPENSATING`
2. Compensation events are published in reverse order
3. Failed steps are tracked for monitoring

## Monitoring

### Saga Statistics
- Total sagas created
- Active sagas
- Completed sagas
- Failed sagas
- Compensating sagas
- Success rate

### Logging
Comprehensive logging for:
- Saga lifecycle events
- Step execution
- Compensation events
- Error conditions
- Timeout handling

## Configuration

### Environment Variables
```env
# Saga Configuration
SAGA_STEP_TIMEOUT=30000
SAGA_TIMEOUT_MINUTES=30
SAGA_CLEANUP_DAYS=7

# RabbitMQ Configuration
RABBITMQ_URL=amqp://localhost:5672
RABBITMQ_EXCHANGE=catalog.events
RABBITMQ_PREFETCH=10
```

### Database Schema
The `saga_states` table tracks saga progress:
- `saga_id` - Unique identifier
- `type` - Saga type (e.g., ORDER_CREATION)
- `status` - Current status
- `order_id` - Associated order ID
- `correlation_id` - Correlation ID for tracking
- `data` - Saga data (JSON)
- `completed_steps` - Array of completed steps
- `failed_steps` - Array of failed steps
- `compensation_required` - Whether compensation is needed
- `timeout_at` - Saga timeout timestamp

## Testing

### Unit Tests
Run saga-specific tests:
```bash
php artisan test --tests-unit=Services/SagaOrchestratorTest.php
```

### Integration Tests
Test saga flow with external services:
```bash
php artisan test --tests-feature=SagaCheckoutControllerTest.php
```

### Console Command Tests
```bash
php artisan test tests/Unit/Console/Commands/SagaManageCommandTest.php
```

## Best Practices

1. **Idempotency** - All event handlers should be idempotent
2. **Timeout Handling** - Set appropriate timeouts for each step
3. **Monitoring** - Monitor saga success rates and failure patterns
4. **Compensation** - Ensure compensation actions are reliable
5. **Error Handling** - Implement proper error handling and logging
6. **Testing** - Test both happy path and failure scenarios

## Troubleshooting

### Common Issues

1. **Saga Timeout**
   - Check if dependent services are responding
   - Increase timeout if needed
   - Monitor service health

2. **Compensation Failures**
   - Check compensation service logs
   - Ensure compensation actions are idempotent
   - Manual intervention may be required

3. **Event Delivery Failures**
   - Check RabbitMQ connectivity
   - Verify event routing configuration
   - Monitor dead-letter queues

### Debug Commands
```bash
# Check active sagas
php artisan tinker --execute="App\Models\SagaState::active()->count()"

# Check failed sagas
php artisan tinker --execute="App\Models\SagaState::where('status', 'FAILED')->count()"

# Check compensating sagas
php artisan tinker --execute="App\Models\SagaState::where('status', 'COMPENSATING')->count()"
```

## Migration

To migrate existing checkout to saga-based checkout:

1. Run the migration:
```bash
php artisan migrate
```

2. Update checkout flow to use `SagaCheckoutService` instead of `CheckoutService`

3. Add saga event handlers to external services

4. Monitor the transition and adjust as needed