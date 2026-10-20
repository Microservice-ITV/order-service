<?php

namespace Tests\Feature;

use App\Services\CheckoutService;
use App\Services\RabbitMQ\DeliveryCompanyConfigurationRpcClient;
use Mockery\MockInterface;
use Tests\TestCase;

final class CheckoutDeliveryCompanyConfigurationTest extends TestCase
{
    private const CHECKOUT_ID = 'checkout-1';
    private MockInterface $checkoutService;
    private MockInterface $deliveryCompanyConfigurationRpcClient;

    protected function setUp(): void
    {
        parent::setUp();

        config(['services.accounting.api_key' => 'order-accounting-test-key']);
        $this->checkoutService = $this->mock(CheckoutService::class);
        $this->deliveryCompanyConfigurationRpcClient = $this->mock(DeliveryCompanyConfigurationRpcClient::class);
    }

    public function test_valid_per_order_configuration_is_accepted_for_checkout(): void
    {
        $this->allowActiveConfigurationLookup();
        $this->expectSuccessfulCheckoutUpdate();

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
            'shop_shippings' => [$this->shopShipping()],
        ]))->assertOk();
    }

    public function test_valid_per_day_configuration_is_accepted_for_checkout(): void
    {
        $this->allowActiveConfigurationLookup();
        $this->expectSuccessfulCheckoutUpdate();

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
            'shop_shippings' => [$this->shopShipping()],
        ]))->assertOk();
    }

    public function test_valid_multi_shop_checkout_verifies_each_shop_configuration(): void
    {
        $this->allowActiveConfigurationLookup([
            $this->shopShipping('shop-1', 'company-1', 200),
            $this->shopShipping('shop-2', 'company-2', 300),
        ]);
        $this->expectSuccessfulCheckoutUpdate();

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 500,
            'shop_shippings' => [
                $this->shopShipping('shop-1', 'company-1', 200),
                $this->shopShipping('shop-2', 'company-2', 300),
            ],
        ]))->assertOk();

    }

    public function test_missing_shop_shippings_is_rejected_even_with_shipping_total(): void
    {
        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_shippings');

        $this->checkoutService->shouldNotHaveReceived('updateDeliveryAddress');
    }

    public function test_empty_shop_shippings_is_rejected(): void
    {
        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
            'shop_shippings' => [],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_shippings');

        $this->checkoutService->shouldNotHaveReceived('updateDeliveryAddress');
    }

    public function test_missing_delivery_company_id_is_accepted(): void
    {
        $row = $this->shopShipping();
        unset($row['delivery_company_id']);
        $this->expectSuccessfulCheckoutUpdate();

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shop_shippings' => [$row],
        ]))->assertOk();
    }

    public function test_canonical_delivery_company_is_accepted(): void
    {
        $row = $this->shopShipping();
        $this->deliveryCompanyConfigurationRpcClient
            ->shouldReceive('isActive')
            ->once()
            ->with('shop-1', 'company-1', 'order-accounting-test-key')
            ->andReturn(true);
        $this->expectSuccessfulCheckoutUpdate();
        $this->putJson($this->addressUrl(), $this->basePayload([
            'shop_shippings' => [$row],
        ]))->assertOk();
    }

    public function test_unknown_company_configuration_is_rejected(): void
    {
        $this->assertConfigurationVerificationRejected($this->shopShipping('shop-1', 'unknown-company'));
    }

    public function test_company_not_configured_for_shop_is_rejected(): void
    {
        $this->assertConfigurationVerificationRejected($this->shopShipping('shop-2'));
    }

    public function test_inactive_shop_configuration_is_rejected(): void
    {
        $this->assertConfigurationVerificationRejected($this->shopShipping());
    }

    public function test_configuration_from_another_checkout_shop_is_rejected(): void
    {
        $this->allowActiveConfigurationLookup([$this->shopShipping('shop-2')]);
        $this->expectInvalidCheckoutUpdate('unknown_shop_in_shipping');

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
            'shop_shippings' => [$this->shopShipping('shop-2')],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'shop_shippings contains a shop_id that is not in this checkout');
    }

    public function test_multi_shop_checkout_rejects_when_one_shop_has_no_configuration_row(): void
    {
        $this->allowActiveConfigurationLookup();
        $this->expectInvalidCheckoutUpdate('shipping_missing_for_shop');

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 300,
            'shop_shippings' => [$this->shopShipping('shop-1')],
        ]))
            ->assertStatus(409)
            ->assertJsonPath('error', 'Provide a shipping fee for each shop that has items in this checkout');
    }

    public function test_standalone_shipping_total_cannot_bypass_company_configuration(): void
    {
        $this->putJson($this->addressUrl(), $this->basePayload([
            'shipping_total' => 900,
            'shop_shippings' => [],
        ]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors('shop_shippings');
    }

    private function assertConfigurationVerificationRejected(array $row): void
    {
        $this->deliveryCompanyConfigurationRpcClient
            ->shouldReceive('isActive')
            ->once()
            ->with($row['shop_id'], $row['delivery_company_id'], 'order-accounting-test-key')
            ->andReturn(false);

        $this->putJson($this->addressUrl(), $this->basePayload([
            'shop_shippings' => [$row],
        ]))
            ->assertUnprocessable()
            ->assertJsonPath('error', 'The selected delivery company is not active for this shop');

        $this->checkoutService->shouldNotHaveReceived('updateDeliveryAddress');
    }

    private function allowActiveConfigurationLookup(array $rows = []): void
    {
        $rows = $rows ?: [$this->shopShipping()];
        foreach ($rows as $row) {
            $this->deliveryCompanyConfigurationRpcClient
                ->shouldReceive('isActive')
                ->once()
                ->with($row['shop_id'], $row['delivery_company_id'], 'order-accounting-test-key')
                ->andReturn(true);
        }
    }

    private function expectSuccessfulCheckoutUpdate(): void
    {
        $this->checkoutService
            ->shouldReceive('updateDeliveryAddress')
            ->once()
            ->andReturn([
                'not_found' => false,
                'invalid_state' => false,
                'status' => 'PENDING_PAYMENT',
                'address_updated_at' => '2026-10-08T00:00:00+00:00',
                'grand_total' => 1300,
                'shipping_total' => 300,
            ]);
    }

    private function expectInvalidCheckoutUpdate(string $reason): void
    {
        $this->checkoutService
            ->shouldReceive('updateDeliveryAddress')
            ->once()
            ->andReturn([
                'not_found' => false,
                'invalid_state' => true,
                'reason' => $reason,
                'status' => 'PENDING_PAYMENT',
                'address_updated_at' => null,
            ]);
    }

    private function shopShipping(
        string $shopId = 'shop-1',
        string $deliveryCompanyId = 'company-1',
        int $shippingFee = 300,
    ): array {
        return [
            'shop_id' => $shopId,
            'shipping_fee' => $shippingFee,
            'delivery_company_id' => $deliveryCompanyId,
        ];
    }

    private function addressUrl(): string
    {
        return '/api/orders/checkout/' . self::CHECKOUT_ID . '/address';
    }

    private function basePayload(array $overrides = []): array
    {
        return [
            'full_name' => 'Checkout Customer',
            'phone_no' => '+959123456789',
            'country_code' => 'MM',
            'country' => 'Myanmar',
            'city' => 'Yangon',
            'address_line_1' => 'Example Street',
            ...$overrides,
        ];
    }
}
