# Order Service — Event & API Payload Reference

> For developers and technical teams. Use with [ORDER_SERVICE_WORKFLOW.md](./ORDER_SERVICE_WORKFLOW.md).

---

## Event envelope (common structure)

All MQ events use this envelope. Order Service uses `event_id`, `event`, `correlation_id`, and `data`.

```json
{
  "event_id": "uuid",
  "event": "event-name",
  "version": "v1",
  "timestamp": "2026-02-22T18:10:05+00:00",
  "producer": "order-service",
  "correlation_id": "string",
  "data": { }
}
```

| Field            | Type   | Description                    |
|------------------|--------|--------------------------------|
| `event_id`       | string | Unique event identifier (UUID) |
| `event`          | string | Event type (routing key)       |
| `version`        | string | Event schema version           |
| `timestamp`      | string | ISO 8601 timestamp             |
| `producer`       | string | Always `order-service`         |
| `correlation_id` | string | Checkout/correlation ID        |
| `data`           | object | Event-specific payload         |

---

## REST API

### ① POST /api/orders/checkout

**Headers:** `Idempotency-Key` (required), `Content-Type: application/json`

**Request body:**
```json
{
  "user_id": "usr_1001",
  "items": [
    { "product_id": "product-uuid-1", "qty": 2 },
    { "product_id": "product-uuid-2", "qty": 1 }
  ],
  "delivery_address": {
    "full_name": "Kyaw Swar",
    "phone_no": "+84901234567",
    "email": "kyaw@example.com",
    "country_code": "VN",
    "country": "Vietnam",
    "state": "Da Nang",
    "city": "Son Tra",
    "district": "An Hai Bac",
    "ward": "Ward 3",
    "address_line_1": "123 Nguyen Van Linh",
    "address_line_2": "Apartment 12A",
    "postal_code": "550000",
    "latitude": 16.0544,
    "longitude": 108.2022,
    "delivery_note": "Call before delivery"
  }
}
```

| Field              | Type   | Required | Description                          |
|--------------------|--------|----------|--------------------------------------|
| `user_id`          | string | ✓        | Max 64 chars                         |
| `items`            | array  | ✓        | Min 1 item                           |
| `items[].product_id`| string | ✓        | UUID                                 |
| `items[].qty`      | int    | ✓        | Min 1                                |
| `delivery_address` | object |          | Optional at checkout; can add later  |

**Success (200) — new checkout:**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "message": "Order received and we are processing your order",
  "expire_at": "2026-02-22T18:25:05+00:00"
}
```

**Success (200) — existing (idempotent):**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "status": 4,
  "message": "Order already paid"
}
```

**Error (400):**
```json
{
  "error": "OUT_OF_STOCK",
  "items": [ { "product_id": "...", "reason": "..." } ]
}
```

---

### ② PUT /api/order/checkout/{checkout_id}/address

**Request body:**
```json
{
  "full_name": "Kyaw Swar",
  "phone_no": "+84901234567",
  "email": "kyaw@example.com",
  "country_code": "VN",
  "country": "Vietnam",
  "state": "Da Nang",
  "city": "Son Tra",
  "district": "An Hai Bac",
  "ward": "Ward 3",
  "address_line_1": "123 Nguyen Van Linh",
  "address_line_2": "Apartment 12A",
  "postal_code": "550000",
  "latitude": 16.0544,
  "longitude": 108.2022,
  "delivery_note": "Call before delivery"
}
```

| Field           | Type   | Required | Description        |
|-----------------|--------|----------|--------------------|
| `full_name`     | string | ✓        | Max 255            |
| `phone_no`      | string | ✓        | Max 50             |
| `country_code`  | string | ✓        | Max 10             |
| `country`       | string | ✓        | Max 120            |
| `city`          | string | ✓        | Max 120            |
| `address_line_1`| string | ✓        | Max 255            |
| `email`         | string |          | Valid email        |
| `state`         | string |          | Max 120            |
| `district`      | string |          | Max 120            |
| `ward`          | string |          | Max 120            |
| `address_line_2`| string |          | Max 255            |
| `postal_code`   | string |          | Max 32             |
| `latitude`      | number |          | -90 to 90          |
| `longitude`     | number |          | -180 to 180        |
| `delivery_note` | string |          | Max 500            |

**Success (200):**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "message": "Delivery address updated successfully",
  "address_updated_at": "2026-02-22T18:15:00.000Z"
}
```

**Error (404):** `{ "error": "Checkout session not found" }`  
**Error (409):** `{ "checkout_id": "...", "error": "Delivery address cannot be updated in current state", "status": "PAID" }`

---

### ③ GET /api/orders/checkout-sessions/{checkout_id}

**Success (200):**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "status": "PENDING_PAYMENT",
  "payment": {
    "payment_id": "pay-uuid",
    "client_secret": "pi_xxx_secret_yyy"
  }
}
```

**Error (404):** `{ "error": "Checkout session not found" }`

---

### ④ POST /api/orders/{orderId}/create-shipment

**Request body:**
```json
{
  "courier_code": "DHL",
  "courier_name": "DHL Express",
  "courier_phone": "+84902222222",
  "tracking_number": "TRK-ABC123456789",
  "tracking_url": "https://track.dhl.com/TRK-ABC123456789"
}
```

| Field            | Type   | Required | Description                          |
|------------------|--------|----------|--------------------------------------|
| `courier_code`   | string | ✓        | Max 64 chars                         |
| `courier_name`   | string | ✓        | Max 255 chars                        |
| `courier_phone`  | string | ✓        | Max 50 chars                         |
| `tracking_number`| string |          | Max 128 chars; auto-generated if omitted |
| `tracking_url`   | string |          | Valid URL, max 512 chars             |

**Success (201):** Order must be in `READY_FOR_FULFILLMENT` status.
```json
{
  "order_id": "9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
  "status": "SHIPPED",
  "message": "Shipment created successfully",
  "courier": { "code": "DHL", "name": "DHL Express", "phone": "+84902222222" },
  "tracking_number": "TRK-ABC123456789",
  "tracking_url": "https://track.dhl.com/TRK-ABC123456789",
  "shipment_created_at": "2026-02-22T18:30:00.000Z"
}
```

**Error (404):** `{ "error": "Order not found" }`  
**Error (409):** `{ "error": "Order must be in READY_FOR_FULFILLMENT status to create shipment", "current_status_id": 7 }`

---

### ⑤ POST /api/orders/checkout-sessions/{checkout_id}/cancel

**Request body (optional):**
```json
{
  "reason": "changed_mind"
}
```

**Success (200):**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "status": "CANCELLED",
  "message": "Order cancelled successfully"
}
```

---

## External API Calls (Order Service → Other Services)

### POST Product Service — /api/v1/products/validate-stock

**Auth:** `X-SERVICE-KEY` header

**Request:**
```json
{
  "items": [
    { "product_id": "product-uuid-1", "qty": 2 },
    { "product_id": "product-uuid-2", "qty": 1 }
  ]
}
```

**Response (success):**
```json
{
  "success": true,
  "data": {
    "stock_validation": true,
    "items": [
      {
        "product_id": "product-uuid-1",
        "qty": 2,
        "stock_validation": true,
        "unit_price": 23.0,
        "line_total": 46.0,
        "product": {
          "product_id": "product-uuid-1",
          "slug": "shop-2-8",
          "title": "Shop 2 Product 8",
          "sku": "SHOP2-P8",
          "price": 23.0,
          "stock": 50,
          "available_stock": 48,
          "currency": "USD",
          "category_id": "cat-uuid",
          "shop_id": "shop-uuid",
          "image_url": "/uploads/products/laptop2.jpeg"
        }
      }
    ]
  }
}
```

---

### POST Payment Service — /api/v1/payments/intents

**Auth:** `X-SERVICE-KEY` header

**Request:**
```json
{
  "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "reservation_id": "reservation-uuid",
  "amount": 129.99,
  "correlation_id": "44806ca5f1557c656b8cfb72922805f3130772009b7212b78d08693a579fffc9",
  "expire_at": "2026-02-22T18:25:05+00:00"
}
```

**Response (201):**
```json
{
  "success": true,
  "data": {
    "paymentId": "pay-uuid",
    "clientSecret": "pi_xxx_secret_yyy"
  }
}
```

---

## MQ Events — Outgoing (Order Service → MQ)

### order.created

**Routing key:** `order.created`  
**When:** After checkout session created and items validated.

```json
{
  "event_id": "7f0e7ec9-6be0-4ac6-90ec-9627f4bbec2c",
  "event": "order.created",
  "version": "v1",
  "timestamp": "2026-02-22T18:10:05+00:00",
  "producer": "order-service",
  "correlation_id": "44806ca5f1557c656b8cfb72922805f3130772009b7212b78d08693a579fffc9",
  "data": {
    "order_id": null,
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "user_id": "usr_1001",
    "status": "CREATED",
    "items": [
      {
        "product_id": "166c9ce4-2b84-4603-92cc-85e4aae96af7",
        "sku": "SHOP2-P8",
        "slug": "shop-2-8",
        "name": "Shop 2 Product 8",
        "description": "Sample product 8 in Shop 2",
        "image_url": "/uploads/products/laptop2.jpeg",
        "qty": 2,
        "unit_price": 23.0,
        "line_total": 46.0,
        "currency": "USD",
        "shop_id": "2f8f3a7d-f5f2-4df9-a995-1f11dc580101",
        "category_id": "7a8d5f8f-6a5c-4b88-aac8-20f74b9f2c10",
        "available_stock": 18
      }
    ],
    "subtotal": 46.0,
    "total": 46.0,
    "currency": "USD",
    "delivery_address": {
      "full_name": "Kyaw Swar",
      "phone_no": "+84901234567",
      "email": "kyaw@example.com",
      "country_code": "VN",
      "country": "Vietnam",
      "state": "Da Nang",
      "city": "Son Tra",
      "district": "An Hai Bac",
      "ward": "Ward 3",
      "address_line_1": "123 Nguyen Van Linh",
      "address_line_2": "Apartment 12A",
      "postal_code": "550000",
      "latitude": 16.0544,
      "longitude": 108.2022,
      "delivery_note": "Call before delivery"
    },
    "placedAt": "2026-02-22T18:10:05+00:00",
    "updatedAt": "2026-02-22T18:10:05+00:00",
    "expires_at": "2026-02-22T18:25:05+00:00"
  }
}
```

**Consumed by:** Product Service (reserve stock)

---

### order.paid

**Routing key:** `order.paid`  
**When:** After payment.completed consumed, Order record created, checkout marked PAID.

```json
{
  "event_id": "7f0e7ec9-6be0-4ac6-90ec-9627f4bbec2d",
  "event": "order.paid",
  "version": "v1",
  "timestamp": "2026-02-22T18:15:00+00:00",
  "producer": "order-service",
  "correlation_id": "44806ca5f1557c656b8cfb72922805f3130772009b7212b78d08693a579fffc9",
  "data": {
    "order_id": "9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "user_id": "usr_1001",
    "status": "ORDER_PAID",
    "payment_id": "pay_7e4f0c70-77d3-4c6a-9f68-5b53f4f9de8a",
    "transaction_id": "txn_abc123",
    "amount": 241.0,
    "currency": "USD",
    "reservation_id": "res_2de7f6d2-b511-4fd7-934b-f35196f2a8ee",
    "deliveryAddress": {
      "full_name": "Kyaw Swar",
      "phone_no": "+84901234567",
      "email": "kyaw@example.com",
      "country_code": "VN",
      "country": "Vietnam",
      "state": "Da Nang",
      "city": "Son Tra",
      "district": "An Hai Bac",
      "ward": "Ward 3",
      "address_line_1": "123 Nguyen Van Linh",
      "address_line_2": "Apartment 12A",
      "postal_code": "550000",
      "latitude": 16.0544,
      "longitude": 108.2022,
      "delivery_note": "Call before delivery"
    },
    "expire_at": "2026-02-22T18:25:05+00:00",
    "updatedAt": "2026-02-22T18:15:00.000Z"
  }
}
```

**Consumed by:** Product Service (reduce stock, publish product.stock.confirmed), Ecommerce Read Service

---

### order.ready_for_fulfillment

**Routing key:** `order.ready_for_fulfillment`  
**When:** After product.stock.confirmed consumed (stock reduced). Indicates order is ready for packing only — shipment creation happens via admin API.

```json
{
  "event_id": "7f0e7ec9-6be0-4ac6-90ec-9627f4bbec2e",
  "event": "order.ready_for_fulfillment",
  "version": "v1",
  "timestamp": "2026-02-22T18:16:00+00:00",
  "producer": "order-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "order_id": "9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "reservation_id": "reservation-uuid",
    "user_id": "usr_1001",
    "status": "READY_FOR_FULFILLMENT",
    "deliveryAddress": {
      "full_name": "Kyaw Swar",
      "phone_no": "+84901234567",
      "email": "kyaw@example.com",
      "country_code": "VN",
      "country": "Vietnam",
      "state": "Da Nang",
      "city": "Son Tra",
      "district": "An Hai Bac",
      "ward": "Ward 3",
      "address_line_1": "123 Nguyen Van Linh",
      "address_line_2": "Apartment 12A",
      "postal_code": "550000",
      "latitude": 16.0544,
      "longitude": 108.2022,
      "delivery_note": "Call before delivery"
    },
    "items": [
      {
        "product_id": "166c9ce4-2b84-4603-92cc-85e4aae96af7",
        "sku": "SHOP2-P8",
        "slug": "shop-2-8",
        "name": "Shop 2 Product 8",
        "qty": 2,
        "unit_price": 23.0,
        "line_total": 46.0,
        "currency": "USD"
      }
    ],
    "updatedAt": "2026-02-22T18:16:00.000Z"
  }
}
```

**Consumed by:** Admin/fulfillment systems (ready for packing). Delivery Service consumes `shipment.created` instead.

---

### shipment.created

**Routing key:** `shipment.created`  
**When:** Admin creates shipment via POST /orders/{orderId}/create-shipment.

```json
{
  "event_id": "7f0e7ec9-6be0-4ac6-90ec-9627f4bbec30",
  "event": "shipment.created",
  "version": "v1",
  "timestamp": "2026-02-22T18:30:00+00:00",
  "producer": "order-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "order_id": "9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
    "order_reference": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "customer_id": "usr_1001",
    "recipient_name": "Kyaw Swar",
    "recipient_phone": "+84901234567",
    "pickup_address": {
      "full_name": "Warehouse",
      "phone_no": "+84901111111",
      "address_line_1": "123 Warehouse St",
      "city": "Ho Chi Minh",
      "country": "Vietnam",
      "country_code": "VN"
    },
    "delivery_address": {
      "full_name": "Kyaw Swar",
      "phone_no": "+84901234567",
      "email": "kyaw@example.com",
      "country_code": "VN",
      "country": "Vietnam",
      "state": "Da Nang",
      "city": "Son Tra",
      "address_line_1": "123 Nguyen Van Linh",
      "address_line_2": "Apartment 12A",
      "postal_code": "550000"
    },
    "shipping_address": { "full_name": "Kyaw Swar", "phone_no": "+84901234567", "address_line_1": "123 Nguyen Van Linh", "city": "Son Tra", "country": "Vietnam" },
    "items": [
      {
        "product_id": "166c9ce4-2b84-4603-92cc-85e4aae96af7",
        "sku": "SHOP2-P8",
        "name": "Shop 2 Product 8",
        "qty": 2,
        "unit_price": 23.0,
        "line_total": 46.0,
        "currency": "USD"
      }
    ],
    "courier": {
      "code": "DHL",
      "name": "DHL Express",
      "phone": "+84902222222"
    },
    "tracking_number": "TRK-ABC123456789-1739123456",
    "tracking_url": "https://track.dhl.com/TRK-ABC123456789"
  }
}
```

**Consumed by:** Delivery Service (creates delivery record, manages lifecycle)

---

### order.cancelled

**Routing key:** `order.cancelled`  
**When:** Payment failed, product rejected, or user cancel.

> **Note:** `order_id` may be `null` when payment fails before Order record is created.

```json
{
  "event_id": "7f0e7ec9-6be0-4ac6-90ec-9627f4bbec2f",
  "event": "order.cancelled",
  "version": "v1",
  "timestamp": "2026-02-22T18:20:00+00:00",
  "producer": "order-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "order_id": "9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "user_id": "usr_1001",
    "status": "CANCELLED",
    "updatedAt": "2026-02-22T18:20:00.000Z",
    "reason": "gateway_declined"
  }
}
```

**Consumed by:** Product Service (release reservation), Ecommerce Read Service

---

## MQ Events — Incoming (Order Service consumes)

### product.stock.reserved

**Routing key:** `product.stock.reserved`  
**Queue:** `RABBITMQ_QUEUE_PRODUCT_RESERVED`

```json
{
  "event_id": "uuid",
  "event": "product.stock.reserved",
  "version": "v1",
  "timestamp": "2026-02-22T18:11:00+00:00",
  "producer": "product-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "reservation_id": "reservation-uuid",
    "total": 75.0,
    "correlation_id": "44806ca5f1557c656b8cfb72922805f3130772009b7212b78d08693a579fffc9",
    "expires_at": "2026-02-22T18:25:05+00:00"
  }
}
```

**Order Service action:** `markStockConfirmed` → create payment intent via Payment Service → `markPendingPayment`

---

### product.stock.rejected

**Routing key:** `product.stock.rejected`  
**Queue:** `RABBITMQ_QUEUE_PRODUCT_REJECTED`

```json
{
  "event_id": "uuid",
  "event": "product.stock.rejected",
  "version": "v1",
  "timestamp": "2026-02-22T18:11:00+00:00",
  "producer": "product-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "reason": "OUT_OF_STOCK"
  }
}
```

**Order Service action:** `reject` checkout → publish `order.cancelled`

---

### payment.completed

**Routing key:** `payment.completed`  
**Queue:** `RABBITMQ_QUEUE_PAYMENT_COMPLETED`

```json
{
  "event_id": "uuid",
  "event": "payment.completed",
  "version": "v1",
  "timestamp": "2026-02-22T18:15:00+00:00",
  "producer": "payment-service",
  "correlation_id": "checkout_74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "payment_id": "pay_7e4f0c70-77d3-4c6a-9f68-5b53f4f9de8a",
    "order_id": "ord_9dd5ba1f-c7c2-4f5d-9af7-0dc914dcbaf0",
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "reservation_id": "reservation-uuid",
    "transaction_id": "txn_abc123",
    "amount": 241.0,
    "currency": "USD",
    "updatedAt": "2026-02-22T18:15:00.000Z"
  }
}
```

**Order Service action:** `confirm` checkout → create Order → `markPaid` → publish `order.paid`

---

### payment.failed

**Routing key:** `payment.failed`  
**Queue:** `RABBITMQ_QUEUE_PAYMENT_FAILED`

```json
{
  "event_id": "uuid",
  "event": "payment.failed",
  "version": "v1",
  "timestamp": "2026-02-22T18:15:00+00:00",
  "producer": "payment-service",
  "correlation_id": "checkout_74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "payment_id": "pay-uuid",
    "order_id": "order-uuid",
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "transaction_id": "txn_abc123",
    "reason": "gateway_declined",
    "updatedAt": "2026-02-22T18:15:00.000Z"
  }
}
```

**Order Service action:** `fail` checkout → publish `order.cancelled`

---

### product.stock.confirmed

**Routing key:** `product.stock.confirmed`  
**Queue:** `RABBITMQ_QUEUE_PRODUCT_STOCK_CONFIRMED`

```json
{
  "event_id": "uuid",
  "event": "product.stock.confirmed",
  "version": "v1",
  "timestamp": "2026-03-04T12:00:00.000Z",
  "producer": "product-service",
  "correlation_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
  "data": {
    "checkout_id": "74e888a5-f33e-4dc2-9bb3-54512d67806d",
    "reservation_id": "reservation-uuid",
    "order_id": "order-uuid"
  }
}
```

**Order Service action:** Publish `order.ready_for_fulfillment` (Delivery Service consumes)

---

## Webhook Routes (for testing / simulation)

| Method | Path | Required body fields |
|--------|------|----------------------|
| POST | /api/webhooks/product/validated | `checkout_id`, `reservation_id`, `correlation_id` |
| POST | /api/webhooks/product/rejected | `checkout_id`, `correlation_id` |
| POST | /api/webhooks/payment/succeeded | `checkout_id`, `payment_id`, `correlation_id` |
| POST | /api/webhooks/payment/failed | `checkout_id`, `payment_id`, `correlation_id`, `reason` |

**Optional fields:** `total`, `expire_at`, `order_id`, `transaction_id`, `amount`, `currency`, `updatedAt`, `reservation_id` (per endpoint)

**Auth:** `webhook.signature` middleware (HMAC when `WEBHOOK_SECRET` set)

---

## Quick Reference

| Flow | Event / API | Key Fields (Order Service uses) |
|------|-------------|--------------------------------|
| Checkout | POST /orders/checkout | `items`, `user_id`, `Idempotency-Key`, `delivery_address` |
| Address | PUT /order/checkout/{id}/address | `full_name`, `phone_no`, `country_code`, `country`, `city`, `address_line_1` |
| Status | GET /checkout-sessions/{id} | `checkout_id`, `status`, `payment` |
| Create shipment | POST /orders/{orderId}/create-shipment | `courier_code`, `courier_name`, `courier_phone`, `tracking_number`, `tracking_url` |
| Cancel | POST /checkout-sessions/{id}/cancel | `reason` |

| Flow | Event (in) | Action | Event (out) |
|------|------------|--------|-------------|
| Reserve | product.stock.reserved | Create payment intent | — |
| Reject | product.stock.rejected | Reject checkout | order.cancelled |
| Paid | payment.completed | Create Order, mark paid | order.paid |
| Failed | payment.failed | Fail checkout | order.cancelled |
| Stock confirmed | product.stock.confirmed | Set READY_FOR_FULFILLMENT | order.ready_for_fulfillment |
| Create shipment | — | Admin API | shipment.created |
| User cancel | — | Cancel checkout | order.cancelled |
