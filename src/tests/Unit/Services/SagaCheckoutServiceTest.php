<?php

namespace Tests\Unit\Services;

use App\Enums\EventTopic;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\Publisher;
use App\Services\SagaCheckoutService;
use App\Services\SagaOrchestrator;
use Mockery;
use Tests\TestCase;

class SagaCheckoutServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_persists_the_saga_before_publishing_the_existing_checkout_event(): void
    {
        $items = [['product_id' => 'product-1', 'qty' => 1]];
        $checkout = new CheckoutSession();
        $checkout->id = 'checkout-1';
        $checkout->idempotency_key = 'correlation-1';
        $checkoutService = Mockery::mock(CheckoutService::class);
        $orchestrator = Mockery::mock(SagaOrchestrator::class);
        $publisher = Mockery::mock(Publisher::class);

        $checkoutService->shouldReceive('startCheckout')
            ->once()
            ->ordered('checkout-flow')
            ->with($items, 'user-1', 'idempotency-1', null, 'cod', null, true)
            ->andReturn(['checkout' => $checkout, 'existing' => false]);
        $orchestrator->shouldReceive('startSaga')
            ->once()
            ->ordered('checkout-flow')
            ->with('ORDER_CREATION', Mockery::on(static fn (array $data): bool => (
                $data['order_id'] === 'checkout-1'
                && $data['checkout_id'] === 'checkout-1'
                && $data['correlation_id'] === 'correlation-1'
            )))
            ->andReturn('saga-1');
        $publisher->shouldReceive('publish')
            ->once()
            ->ordered('checkout-flow')
            ->with(EventTopic::SAGA_ORDER_CREATED, Mockery::on(static fn (array $payload): bool => (
                $payload['event'] === EventTopic::SAGA_ORDER_CREATED->value
                && $payload['data']['saga_id'] === 'saga-1'
                && $payload['data']['order_id'] === 'checkout-1'
            )));
        $checkoutService->shouldReceive('publishOrderCheckoutEvent')
            ->once()
            ->ordered('checkout-flow')
            ->with($checkout, 'correlation-1');

        $service = new SagaCheckoutService($checkoutService, $orchestrator, $publisher);
        $result = $service->startSagaCheckout(
            $items,
            'user-1',
            'idempotency-1',
        );

        $this->assertSame('saga-1', $result['saga_id']);
        $this->assertFalse($result['existing']);
    }
}
