# Professional Ecommerce Checkout Flow

## Overview

This document explains the professional ecommerce checkout flow implemented in this order service, following industry best practices used by platforms like Shopee, Lazada, and Amazon.

## Key Principles

1. **Validate BEFORE Create**: Never create orders for invalid products
2. **Idempotency at Service Level**: Reusable, professional idempotency handling
3. **Separation of Concerns**: Each service has a single responsibility
4. **Fail Fast**: Return errors immediately if validation fails

## Flow Diagram

```
┌─────────────────┐
│  Client Request │
│  (Checkout API) │
└────────┬────────┘
         │
         ▼
┌─────────────────────────┐
│ 1. Validate Cart Items  │
│    - Format check       │
│    - Non-empty          │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│ 2. Extract Idempotency  │
│    Key                  │
│    - From header        │
│    - Or generate        │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│ 3. Validate Products   │ ◄─── CRITICAL: Validate FIRST
│    - Exist?             │
│    - In stock?          │
│    - Prices valid?      │
└────────┬────────────────┘
         │
    ┌────┴────┐
    │ Valid?  │
    └────┬────┘
         │
    ┌────┴────┐
    │   NO    │ ────► Return Error (400)
    └─────────┘
         │
    ┌────┴────┐
    │  YES   │
    └────┬────┘
         │
         ▼
┌─────────────────────────┐
│ 4. Check Idempotency     │
│    - Cache lookup        │
│    - DB lookup           │
│    - Lock if new         │
└────────┬────────────────┘
         │
    ┌────┴────┐
    │ Exists? │
    └────┬────┘
         │
    ┌────┴────┐
    │  YES   │ ────► Return Existing Order
    └─────────┘
         │
    ┌────┴────┐
    │   NO   │
    └────┬────┘
         │
         ▼
┌─────────────────────────┐
│ 5. Create Order         │ ◄─── Only if validation passed
│    - Order record       │
│    - Order items        │
│    - Set idempotency    │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│ 6. Publish Event        │
│    - RabbitMQ           │
│    - For payment        │
└────────┬────────────────┘
         │
         ▼
┌─────────────────────────┐
│ 7. Return Response      │
│    - Order ID           │
│    - Status             │
└────────────────────────┘
```

## Why This Flow is Better

### ❌ Old Approach (Wrong)
```
1. Create Order (with invalid products possible)
2. Validate Products
3. If invalid, reject order
```
**Problems:**
- Creates orders for non-existent products
- Wastes database resources
- Poor user experience
- Data inconsistency

### ✅ New Approach (Correct)
```
1. Validate Products FIRST
2. Only create order if validation passes
3. Idempotency prevents duplicates
```
**Benefits:**
- No invalid orders in database
- Better performance (fail fast)
- Cleaner data
- Professional implementation

## Services Architecture

### 1. IdempotencyService
**Purpose**: Handle duplicate request prevention professionally

**Features:**
- DB-backed with unique constraint
- Cache layer for performance
- Distributed locking for concurrency
- Configurable TTL
- Reusable across endpoints

**Usage:**
```php
$result = $idempotencyService->getOrCreateOrder(
    $idempotencyKey,
    fn($hash) => $this->createOrder($items, $validation, $hash)
);
```

### 2. ProductValidationService
**Purpose**: Validate products before order creation

**Validates:**
- Product existence
- Stock availability
- Price validity
- Returns structured validation result

**Usage:**
```php
$validation = $productValidationService->validateAndGetPricing($items);
if (!$validation['valid']) {
    return error_response(...);
}
```

### 3. CheckoutController
**Purpose**: Orchestrate the checkout flow

**Responsibilities:**
- Extract and validate input
- Coordinate services
- Handle errors
- Return responses

## Real-World Ecommerce Flow

### Complete Order Lifecycle

```
1. CHECKOUT REQUEST
   ├─ Validate cart items
   ├─ Extract idempotency key
   └─ Validate products (exist, stock, prices)

2. ORDER CREATION (only if validation passes)
   ├─ Create order record
   ├─ Create order items
   └─ Set idempotency key

3. EVENT PUBLISHING
   └─ Publish to RabbitMQ for payment processing

4. PAYMENT PROCESSING (separate service)
   ├─ Process payment
   ├─ On success: Confirm stock reservation
   └─ On failure: Release stock reservation

5. FULFILLMENT
   ├─ Stock confirmed
   ├─ Payment confirmed
   └─ Ship order
```

## API Endpoint

### POST `/api/checkout`

**Request Headers:**
```
Idempotency-Key: <uuid> (optional, recommended)
Content-Type: application/json
```

**Request Body:**
```json
{
  "items": [
    {
      "product_id": "uuid",
      "qty": 2
    }
  ]
}
```

**Success Response (201):**
```json
{
  "order_id": "uuid",
  "message": "Order created successfully. Processing payment...",
  "status": "pending_payment"
}
```

**Error Response (400):**
```json
{
  "error": "Some products are unavailable or out of stock",
  "missing_products": [...],
  "out_of_stock": [...]
}
```

**Idempotent Response (200):**
```json
{
  "order_id": "uuid",
  "message": "Order already exists. Processing payment...",
  "status": "pending_payment"
}
```

## Key Differences from Previous Implementation

| Aspect | Old | New |
|--------|-----|-----|
| **Validation Order** | Create order → Validate | Validate → Create order |
| **Idempotency** | Controller-level logic | Service-level, reusable |
| **Product Check** | After order creation | Before order creation |
| **Error Handling** | Scattered | Centralized in services |
| **Code Reusability** | Low | High (services) |
| **Testing** | Hard | Easy (testable services) |

## Best Practices Implemented

1. ✅ **Fail Fast**: Validate early, return errors immediately
2. ✅ **Single Responsibility**: Each service has one job
3. ✅ **Idempotency**: Professional, reusable implementation
4. ✅ **Transaction Safety**: DB transactions for consistency
5. ✅ **Distributed Locking**: Handle concurrent requests
6. ✅ **Caching**: Performance optimization
7. ✅ **Logging**: Comprehensive logging for debugging
8. ✅ **Type Safety**: PHP 8+ type hints and docblocks

## Next Steps (Future Enhancements)

1. **Stock Reservation**: Reserve stock temporarily during checkout
2. **Price Locking**: Lock prices for a time window
3. **Cart Expiration**: Expire carts after inactivity
4. **Payment Integration**: Direct payment processing
5. **Order Status Webhooks**: Notify clients of status changes
6. **Retry Logic**: Automatic retry for transient failures
