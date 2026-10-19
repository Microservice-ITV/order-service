<?php

namespace Tests\Unit\Services;

use App\Contracts\EventPublisherInterface;
use App\Contracts\OrderInterface;
use App\Enums\EventTopic;
use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Models\Order;
use App\Models\ShopOrder;
use App\Repositories\OrderRepository;
use App\Services\OrderLifecycleService;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Mockery;
use Tests\TestCase;

class OrderLifecycleServiceTest extends TestCase
{
    private OrderRepository $orders;

    protected function setUp(): void
    {
        parent::setUp();
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkout_sessions');

        Schema::create('orders', function (Blueprint $table): void {
            $table->string('order_id')->primary();
            $table->string('checkout_id');
            $table->string('user_id')->nullable();
            $table->string('reservation_id')->nullable();
            $table->unsignedInteger('order_status');
            $table->text('cancellation_reason')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamp('return_deadline_at')->nullable();
            $table->text('return_reason')->nullable();
            $table->timestamp('returned_at')->nullable();
            $table->timestamps();
        });
        Schema::create('checkout_sessions', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('correlation_id')->nullable();
            $table->string('user_id')->nullable();
        });
        Schema::create('shop_orders', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('order_id');
            $table->string('shop_id');
            $table->decimal('subtotal', 10, 2)->default(0);
            $table->decimal('discount_total', 10, 2)->default(0);
            $table->decimal('bundle_discount', 10, 2)->default(0);
            $table->decimal('shipping_total', 10, 2)->default(0);
            $table->decimal('grand_total', 10, 2)->default(0);
            $table->unsignedInteger('order_status');
            $table->string('tracking_number')->nullable();
            $table->string('courier_name')->nullable();
            $table->timestamp('shipment_created_at')->nullable();
            $table->timestamps();
        });

        $this->orders = new OrderRepository(new Order());
        $this->app->instance(OrderInterface::class, $this->orders);
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('shop_orders');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('checkout_sessions');
        Mockery::close();
        parent::tearDown();
    }

    public function test_shipped_order_can_be_cancelled_then_returned_with_reasons_and_statuses(): void
    {
        $this->seedShippedOrder();
        $publishedTopics = [];
        $publisher = Mockery::mock(EventPublisherInterface::class);
        $publisher->shouldReceive('publish')
            ->twice()
            ->andReturnUsing(static function (EventTopic $topic) use (&$publishedTopics): void {
                $publishedTopics[] = $topic;
            });
        $this->app->instance(EventPublisherInterface::class, $publisher);
        $service = new OrderLifecycleService($this->orders);

        $cancelled = $service->cancelShippedOrder('order-1', 'Customer refused delivery');

        $this->assertSame(OrderStatus::CANCELLED->value, (int) $cancelled->order_status);
        $this->assertSame('Customer refused delivery', $cancelled->cancellation_reason);
        $this->assertNotNull($cancelled->cancelled_at);
        $this->assertSame(
            [OrderStatus::CANCELLED->value, OrderStatus::CANCELLED->value],
            ShopOrder::orderBy('id')->pluck('order_status')->map(static fn ($status): int => (int) $status)->all(),
        );

        $returned = $service->markOrderReturned('order-1', 'Package received unopened');

        $this->assertSame(OrderStatus::RETURNED->value, (int) $returned->order_status);
        $this->assertSame('Package received unopened', $returned->return_reason);
        $this->assertNotNull($returned->returned_at);
        $this->assertSame(
            [OrderStatus::RETURNED->value, OrderStatus::RETURNED->value],
            ShopOrder::orderBy('id')->pluck('order_status')->map(static fn ($status): int => (int) $status)->all(),
        );
        $this->assertSame([EventTopic::ORDER_CANCELLED, EventTopic::ORDER_RETURNED], $publishedTopics);
    }

    public function test_return_is_rejected_after_the_seven_day_window(): void
    {
        $this->seedShippedOrder();
        Order::where('order_id', 'order-1')->update([
            'order_status' => OrderStatus::CANCELLED->value,
            'cancellation_reason' => 'Customer refused delivery',
            'cancelled_at' => now()->subDays(8),
        ]);
        ShopOrder::where('order_id', 'order-1')->update([
            'order_status' => OrderStatus::CANCELLED->value,
        ]);

        try {
            (new OrderLifecycleService($this->orders))->markOrderReturned('order-1', 'Received back');
            $this->fail('Expected the expired return window to reject the operation.');
        } catch (BusinessException $exception) {
            $this->assertSame(409, $exception->getStatus());
            $this->assertSame('CANCELLED', OrderStatus::tryFrom((int) Order::find('order-1')->order_status)?->name);
        }
    }

    private function seedShippedOrder(): void
    {
        DB::table('checkout_sessions')->insert([
            'id' => 'checkout-1',
            'correlation_id' => 'correlation-1',
            'user_id' => 'seller-customer-1',
        ]);
        Order::create([
            'order_id' => 'order-1',
            'checkout_id' => 'checkout-1',
            'user_id' => 'seller-customer-1',
            'reservation_id' => 'reservation-1',
            // Multi-shop orders use FULFILLED at order level once all shops ship.
            'order_status' => OrderStatus::FULFILLED->value,
        ]);
        foreach (['shop-1', 'shop-2'] as $index => $shopId) {
            ShopOrder::create([
                'id' => 'shop-order-' . $index,
                'order_id' => 'order-1',
                'shop_id' => $shopId,
                'order_status' => OrderStatus::SHIPPED->value,
            ]);
        }
    }
}
