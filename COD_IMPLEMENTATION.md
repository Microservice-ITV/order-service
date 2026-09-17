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

OK, I don't want to change status to collected automatically. Here, the flow will be like this. As long as            
  order successfull, payment status will be 'pending', the shop owner will update to 'collected' when the               
  delivery status is 'delivered'. Another one is orderId are empty is the payment table, can you fix that issue.        
  And also, you updated the delivery status 'shiped' to 'delivered'(need to modify order_status table) in the           
  orders table when the user click 'confirm receipt'.                                                                   
                                                    
  I'll map the current COD payment status flow, where orderId should be set on payments, and the confirm-receipt path
  so we can keep payments PENDING, stop auto-collect, and move orders from shipped → delivered.


Great job, Can you check the Kernel.php and ConsumeAll class in order srvice. It takes time in ordering process while checking stock reservation, payment completed, sotck confirmed and shipment delivered. Can you check CheckoutSummary component also in forntent-react service while taking time in ordering process?
[•] Trace ordering-state flow across order-service consumers, publishers, and checkout polling
[ ] Identify and fix consumer-side latency or blocking behavior
[ ] Identify and fix CheckoutSummary/frontend waiting behavior
[ ] Run focused backend and frontend verification

