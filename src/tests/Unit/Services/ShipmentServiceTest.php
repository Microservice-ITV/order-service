<?php

namespace Tests\Unit\Services;

use App\Contracts\OrderInterface;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Services\ShipmentService;
use Mockery;
use Tests\TestCase;

class ShipmentServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }

    public function test_it_rejects_shipment_creation_without_a_waybill_number(): void
    {
        $orders = Mockery::mock(OrderInterface::class);
        $orders->shouldReceive('findById')
            ->once()
            ->with('order-1')
            ->andReturn(new Order());

        $service = new ShipmentService($orders);

        try {
            $service->createShipment('order-1', []);
            $this->fail('Expected shipment creation to require a waybill number.');
        } catch (BusinessException $exception) {
            $this->assertSame(422, $exception->getStatus());
            $this->assertSame(
                'Waybill number is required before creating a shipment',
                $exception->getMessage(),
            );
        }
    }
}
