<?php

namespace App\Services;

use App\Logging\LogMessage;
use App\Models\CheckoutItem;

final class CheckoutPaymentPreparationService
{
    public function __construct(
        private readonly CheckoutService $checkoutService,
        private readonly PaymentService $paymentService
    ) {}

    /**
     * Confirm reserved stock and create a payment intent for checkout.
     *
     * @throws \RuntimeException when required checkout metadata is missing
     */
    public function confirmStockAndPreparePayment(
        string $checkoutId,
        string $reservationId,
        float $total,
        string $correlationId
    ): void {
        $checkout = $this->checkoutService->validateCheckout($checkoutId);
        if (!$checkout) {
            logger()->warning(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'CHECKOUT_NOT_FOUND_OR_EXPIRED',
            ]);

            return;
        }

        if ($correlationId === '') {
            $correlationId = (string) ($checkout->correlation_id ?? '');
        }

        if ($correlationId === '') {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'MISSING_CORRELATION_ID',
            ]);
            throw new \RuntimeException('Missing correlation_id');
        }

        if ($reservationId === '') {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'INVALID_RESERVATION_ID',
            ]);
            throw new \RuntimeException('Invalid reservation_id');
        }

        $this->checkoutService->markStockConfirmed($checkoutId, $total, $reservationId);

        logger()->info(LogMessage::CHECKOUT_STOCK_CONFIRMED, [
            'checkout_id'    => $checkoutId,
            'reservation_id' => $reservationId,
        ]);

        $expireAtIso = $checkout->getExpireAtIso();
        if ($expireAtIso === null) {
            logger()->error(LogMessage::CHECKOUT_REJECTED_STOCK_FAILED, [
                'checkout_id' => $checkoutId,
                'reason'      => 'MISSING_EXPIRE_AT',
            ]);
            throw new \RuntimeException('Missing expire_at');
        }

        try {
            $paymentIntent = $this->paymentService->createPaymentIntent(
                checkoutId: $checkoutId,
                reservationId: $reservationId,
                amount: $total,
                correlationId: $correlationId,
                expireAtIso: $expireAtIso
            );
        } catch (\Throwable $e) {
            logger()->error('PAYMENT_INTENT_CREATION_FAILED_STOCK_STILL_CONFIRMED', [
                'checkout_id' => $checkoutId,
                'error'       => $e->getMessage(),
            ]);

            $this->checkoutService->markPaymentPreparationFailed(
                $checkoutId,
                'PAYMENT_INTENT_CREATION_FAILED'
            );

            return;
        }

        $paymentId    = (string) $paymentIntent['payment_id'];
        $clientSecret = (string) $paymentIntent['client_secret'];

        logger()->info('PAYMENT_INTENT_API_RESPONSE', [
            'payment_id' => $paymentId,
            'client_secret' => $clientSecret,
        ]);

        $this->checkoutService->updatePaymentIntent($checkoutId, $paymentId, $clientSecret);
        $this->checkoutService->markPendingPayment($checkoutId);

        logger()->info(LogMessage::CHECKOUT_PENDING_PAYMENT, [
            'checkout_id' => $checkoutId,
        ]);
    }

    public function calculateCheckoutItemsTotal(string $checkoutId): float
    {
        logger()->info(LogMessage::CHECKOUT_TOTAL_CALCULATION_STARTED, [
            'checkout_id' => $checkoutId,
        ]);

        return (float) CheckoutItem::query()
            ->where('checkout_id', $checkoutId)
            ->get()
            ->sum(function (CheckoutItem $item) {
                if ($item->line_total !== null) {
                    return (float) $item->line_total;
                }

                return $item->qty * (float) ($item->price ?? 0);
            });
    }
}
