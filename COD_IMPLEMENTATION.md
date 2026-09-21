Recommended COD design
Treat COD as an "authorized on delivery" payment. Intent creation only reserves a PENDING COD payment; `payment.completed` is published **after** address/shipping are finalised on Place Order (via authorize). Cash collection happens later at delivery.

Frontend (COD selected) ──▶ /saga/orders/checkout  +  payment_method: "cod"
▼ ConsumeProductValidated  creates COD intent (PENDING, no payment.completed yet)
▼ Place Order ──▶ updateDeliveryAddress (+ shipping) ──▶ POST /payments/{id}/authorize
▼ payment-service authorizeCodPayment
    ├─ Payment status → SUCCESS
    └─ publishes payment.completed  (payment_method: "cod" in data)
▼ ConsumePaymentSucceeded  →  UNCHANGED  → confirm → create order → markPaid → completeStep(PAYMENT)
▼ product-service commits stock ──▶ shipment flow  (UNCHANGED)
▼ Delivery agent collects cash ──▶ POST /payments/{id}/collect (from shipment.delivered)
                                 └─ marks Payment COLLECTED / publishes payment.collected

Concrete changes per service
frontend-react (CheckoutPayment.tsx, CheckoutSummary.tsx, CheckoutPage.tsx, checkoutThunks.ts)
- Enable the "Cash on Delivery" tab and carry payment_method through the checkout payload.
- Restart checkout session when Card ↔ COD changes after a session exists.
- In handlePlaceOrder: if COD, skip confirmCardPayment/Elements checks; update address (triggers authorize) and poll for PAID.

order-service
- SagaCheckoutController::startCheckout + SagaCheckoutService::startSagaCheckout: accept/persist payment_method.
- PaymentService::createPaymentIntent(): forward payment_method; authorizePayment + collectPayment helpers.
- ConsumeProductValidated: pass checkout payment_method; mark PENDING_PAYMENT after intent create.
- updateDeliveryAddress: after shipping/amount update, authorize COD payments.
- ConsumeShipmentDelivered: collect COD on shipment.delivered.

payment-service
- COD branch in createPaymentIntent: PENDING row, no gateway, no immediate completed event.
- POST /payments/{id}/authorize → SUCCESS + payment.completed.
- POST /payments/{id}/collect → COLLECTED + payment.collected.
- PaymentEventEnvelope includes completed/failed/collected; payment_method on completed + collected.

No changes to: ConsumePaymentSucceeded, stock commit, shipment, delivery-service, or the saga step definitions — the saga stays intact.
payment_method propagation comes from the frontend request; delivery/courier confirms cash collection (collect + payment.collected).

[✓] payment-service: PaymentStatus.COLLECTED + PaymentEventType COLLECTED (schema + migration)
[✓] payment-service: payment.collected routing key + publisher + env
[✓] payment-service: COD branch in createPaymentIntent + amount update skip + authorize/collect endpoints
[✓] payment-service: extend PaymentEventEnvelope with payment_method; collected included in union
[✓] order-service: payment_method column migration + model fillable + validations + pass-through to PaymentService payload
[✓] order-service: PaymentService::authorizePayment + collectPayment + ConsumeShipmentDelivered + rabbitmq config + ConsumeAll
[✓] frontend: paymentMethod in checkoutSlice/checkoutThunks/checkoutTypes
[✓] frontend: CheckoutPayment COD tab + CheckoutSummary COD branch + CheckoutPage restart effect
[✓] frontend: process COD sessionPaymentMethod from status response
[✓] docker-compose: RABBITMQ_PAYMENT_COLLECTED_KEY env

Hey, I want you to modify the payment processing flow. I already modified the payments methods to "prepaid and cod". For COD part, all fine. I want to store "Prepaid" as payment method what ever you choose (card, kpay, wavepay, etc..).Now we are storing only "card". In that case, provider will be stripe or kpay what ever you choose. The final goal to store the acutal payment method(cod, prepaid) name in the database. All operation are working well and make sure payment process working gracefully after you modified. You need to tocuh CheckoutPage and CheckoutPayment in frontend-react.

