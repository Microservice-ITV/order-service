<?php

namespace App\Http\Controllers\Api;

use App\Enums\OrderStatus;
use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Http\Requests\CheckoutRequest;
use App\Models\CheckoutSession;
use App\Services\CheckoutService;
use App\Services\RabbitMQ\DeliveryCompanyConfigurationRpcClient;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Throwable;

class CheckoutController extends Controller
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly DeliveryCompanyConfigurationRpcClient $deliveryCompanyConfigurationRpcClient,
    ) {}

    public function checkout(CheckoutRequest $request): JsonResponse
    {
        try {
            $result = $this->checkoutService->startCheckout(
                $request->validated('items'),
                $request->validated('user_id'),
                $request->validated('idempotency_key'),
                $request->validated('delivery_address')
            );
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatus(), $e->getData());
        }

        $checkout = $result['checkout'];
        if ($result['existing']) {
            return $this->existingOrderResponse($checkout);
        }

        return $this->successResponse($checkout->id, $checkout->getExpireAtIso());
    }

    public function updateAddress(Request $request, string $checkoutId): JsonResponse
    {
        try {
            $validated = $request->validate(array_merge($this->deliveryAddressRules(), [
                'shipping_total' => ['nullable', 'numeric', 'min:0'],
                'shop_shippings' => ['required', 'array', 'min:1'],
                'shop_shippings.*.shop_id' => ['required', 'string', 'max:64'],
                'shop_shippings.*.shipping_fee' => ['required', 'numeric', 'min:0'],
                'shop_shippings.*.delivery_company_id' => ['nullable', 'string', 'max:64'],
                'promo_code' => ['nullable', 'string', 'max:64'],
                'payment_method' => ['nullable', 'string', 'in:prepaid,cod'],
                'cash_paid_amount' => ['nullable', 'numeric', 'min:0'],
                'cash_refund_amount' => ['nullable', 'numeric', 'min:0'],
            ]));

            if (!array_key_exists('shipping_total', $validated)) {
                $shippingTotal = null;
            } else {
                $shippingTotal = $validated['shipping_total'] === null ? null : (float) $validated['shipping_total'];
            }
            unset($validated['shipping_total']);

            $promoCode = null;
            if (array_key_exists('promo_code', $validated) && is_string($validated['promo_code'])) {
                $trimmed = trim($validated['promo_code']);
                $promoCode = $trimmed !== '' ? $trimmed : null;
            }
            unset($validated['promo_code']);

            $paymentMethod = $validated['payment_method'] ?? null;
            unset($validated['payment_method']);

            $cashPaidAmount = array_key_exists('cash_paid_amount', $validated) && $validated['cash_paid_amount'] !== null
                ? round((float) $validated['cash_paid_amount'], 2)
                : null;
            unset($validated['cash_paid_amount']);

            $cashRefundAmount = array_key_exists('cash_refund_amount', $validated) && $validated['cash_refund_amount'] !== null
                ? round((float) $validated['cash_refund_amount'], 2)
                : null;
            unset($validated['cash_refund_amount']);

            $shopShippings = array_map(
                function (array $row): array {
                    $shopId = trim((string) $row['shop_id']);
                    $deliveryCompanyId = trim((string) ($row['delivery_company_id'] ?? ''));
                    if ($shopId === '') {
                        throw new BusinessException(
                            'Each shop must select an active Delivery Company configured for that shop',
                            422,
                        );
                    }
                    // $this->assertActiveShopDeliveryCompany($shopId, $deliveryCompanyId);

                    return [
                        'shop_id' => $shopId,
                        'shipping_fee' => (float) $row['shipping_fee'],
                        'delivery_company_id' => $deliveryCompanyId !== '' ? $deliveryCompanyId : null,
                    ];
                },
                $validated['shop_shippings']
            );
            unset($validated['shop_shippings']);

            $result = $this->checkoutService->updateDeliveryAddress(
                checkoutId: $checkoutId,
                deliveryAddress: $validated,
                shippingTotal: $shippingTotal,
                shopShippings: $shopShippings,
                promoCode: $promoCode,
                paymentMethod: $paymentMethod,
                cashPaidAmount: $cashPaidAmount,
                cashRefundAmount: $cashRefundAmount,
            );

            if ($result['not_found'] === true) {
                return response()->json([
                    'error' => 'Checkout session not found',
                ], 404);
            }

            if (!empty($result['payment_update_failed'])) {
                return response()->json([
                    'checkout_id' => $checkoutId,
                    'error' => 'Failed to update payment amount for checkout',
                    'detail' => $result['payment_error'] ?? null,
                    'status' => $result['status'],
                ], 502);
            }

            if ($result['invalid_state'] === true) {
                $message = match ($result['reason'] ?? null) {
                    'shipping_requires_pending_payment' => 'Shipping can only be finalized while checkout is awaiting payment',
                    'payment_not_ready' => 'Payment is not ready yet; wait for stock confirmation to complete',
                    'shipping_total_mismatch' => 'shipping_total must match the sum of shop_shippings',
                    'shipping_missing_for_shop' => 'Provide a shipping fee for each shop that has items in this checkout',
                    'shop_delivery_company_missing' => 'Select an active Delivery Company configured for every shop in this checkout',
                    'unknown_shop_in_shipping' => 'shop_shippings contains a shop_id that is not in this checkout',
                    'checkout_items_missing_shop_id' => 'Cart line items must include shop_id to use per-shop shipping',
                    'shop_shippings_invalid' => 'shop_shippings could not be applied (empty or invalid shop ids)',
                    'promotion_quote_failed' => 'Promotion pricing is temporarily unavailable; please try again',
                    'tax_quote_failed' => 'Tax pricing is temporarily unavailable; please try again',
                    'promo_code_invalid' => match ($result['promo_code_status'] ?? null) {
                        'LIMIT_REACHED' => $result['promo_code_message'] ?? 'Promo code has reached its usage limit',
                        'NOT_APPLICABLE' => $result['promo_code_message'] ?? 'Promo code does not apply to the current cart',
                        default => $result['promo_code_message'] ?? 'Promo code is not valid',
                    },
                    default => 'Delivery address cannot be updated in current state',
                };

                $payload = [
                    'checkout_id' => $checkoutId,
                    'error' => $message,
                    'status' => $result['status'],
                ];
                if (!empty($result['shop_validation']) && is_array($result['shop_validation'])) {
                    $payload['shop_validation'] = $result['shop_validation'];
                }
                if (!empty($result['promo_code_status'])) {
                    $payload['promo_code'] = $result['promo_code'] ?? null;
                    $payload['promo_code_status'] = $result['promo_code_status'];
                    $payload['promo_code_message'] = $result['promo_code_message'] ?? null;
                }

                return response()->json($payload, 409);
            }

            $payload = [
                'checkout_id' => $checkoutId,
                'message' => 'Delivery address updated successfully',
                'address_updated_at' => $result['address_updated_at'],
            ];

            if (!empty($result['already_succeeded'])) {
                $payload['already_succeeded'] = true;
            }

            if (isset($result['grand_total'], $result['shipping_total'])) {
                $payload['grand_total'] = $result['grand_total'];
                $payload['shipping_total'] = $result['shipping_total'];
                $payload['promotion_discount_total'] = $result['promotion_discount_total'] ?? 0;
                $payload['tax_amount'] = $result['tax_amount'] ?? 0;
                $payload['taxable_amount'] = $result['taxable_amount'] ?? 0;
                $payload['tax_enabled'] = $result['tax_enabled'] ?? true;
                if (!empty($result['promo_code'])) {
                    $payload['promo_code'] = $result['promo_code'];
                }
                if (!empty($result['promo_code_status'])) {
                    $payload['promo_code_status'] = $result['promo_code_status'];
                    $payload['promo_code_message'] = $result['promo_code_message'] ?? null;
                }
                if (!empty($result['client_secret'])) {
                    $payload['payment'] = [
                        'client_secret' => $result['client_secret'],
                    ];
                }
                if (!empty($result['shop_shippings'])) {
                    $payload['shop_shippings'] = $result['shop_shippings'];
                }
            }

            return response()->json($payload);
        } catch (BusinessException $e) {
            return $this->errorResponse($e->getMessage(), $e->getStatus(), $e->getData());
        } catch (\Throwable $e) {
            logger()->error('CHECKOUT_UPDATE_ADDRESS_FAILED', [
                'checkout_id' => $checkoutId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'checkout_id' => $checkoutId,
                'error' => 'Failed to update delivery address',
                'detail' => $e->getMessage(),
            ], 500);
        }
    }

    private function assertActiveShopDeliveryCompany(string $shopId, string $deliveryCompanyId): void
    {
        $accountingServiceApiKey = trim((string) config('services.accounting.api_key'));
        if ($accountingServiceApiKey === '') {
            throw new BusinessException('Accounting Service authentication is not configured', 503);
        }

        try {
            $isActive = $this->deliveryCompanyConfigurationRpcClient->isActive(
                $shopId,
                $deliveryCompanyId,
                $accountingServiceApiKey,
            );
        } catch (Throwable $error) {
            logger()->error('CHECKOUT_DELIVERY_COMPANY_VERIFICATION_FAILED', [
                'shop_id' => $shopId,
                'delivery_company_id' => $deliveryCompanyId,
                'error' => $error->getMessage(),
            ]);
            throw new BusinessException('Shop delivery configuration could not be verified', 503);
        }

        if (!$isActive) {
            throw new BusinessException('The selected Delivery Company is not active for this shop', 422);
        }
    }

    private function existingOrderResponse(CheckoutSession $checkout): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkout->id,
            'status'      => $checkout->status_id->value,
            'message'     => match ($checkout->status_id) {
                OrderStatus::PAID => 'Order already paid',
                OrderStatus::CANCELLED => 'Order was cancelled',
                default => 'Order already received and is processing',
            },
        ]);
    }


    private function successResponse(string $checkoutId, ?string $expireAt): JsonResponse
    {
        return response()->json([
            'checkout_id' => $checkoutId,
            'message' => 'Order received and we are processing your order',
            'expire_at' => $expireAt,
        ]);
    }

    private function errorResponse(string $message, int $status = 400, array $data = []): JsonResponse
    {
        return response()->json([
            'error' => $message,
            ...$data,
        ], $status);
    }

    private function deliveryAddressRules(): array
    {
        return [
            'full_name' => ['required', 'string', 'max:255'],
            'phone_no' => ['required', 'string', 'max:50'],
            'email' => ['nullable', 'email', 'max:255'],
            'country_code' => ['required', 'string', 'max:10'],
            'country' => ['required', 'string', 'max:120'],
            'state' => ['nullable', 'string', 'max:120'],
            'city' => ['required', 'string', 'max:120'],
            'district' => ['nullable', 'string', 'max:120'],
            'ward' => ['nullable', 'string', 'max:120'],
            'address_line_1' => ['required', 'string', 'max:255'],
            'address_line_2' => ['nullable', 'string', 'max:255'],
            'postal_code' => ['nullable', 'string', 'max:32'],
            'latitude' => ['nullable', 'numeric', 'between:-90,90'],
            'longitude' => ['nullable', 'numeric', 'between:-180,180'],
            'delivery_note' => ['nullable', 'string', 'max:500'],
        ];
    }
}
