# Database Schema Refactoring Summary

## Overview

The database schema has been refactored to better separate checkout sessions from confirmed orders, following ecommerce best practices.

## Changes Made

### 1. Table Renames

#### `orders` → `checkout_sessions`
- **Purpose**: Represents a checkout session (cart checkout process)
- **Contains**: Checkout state, items, idempotency key, status tracking
- **When Created**: When user initiates checkout

#### `order_items` → `checkout_items`
- **Purpose**: Items in a checkout session
- **Foreign Key**: References `checkout_sessions.id` (via `order_id` column)

### 2. New `orders` Table

Created a new `orders` table for confirmed orders:

```sql
orders
-------------------------
order_id           UUID  PK
checkout_id        UUID  UNIQUE (FK to checkout_sessions.id)
reservation_id     UUID  -- from Product Service
payment_id         UUID  -- from Payment Service
status             ENUM('CONFIRMED','CANCELLED')
created_at
updated_at
```

**Purpose**: Represents a confirmed order after payment and reservation are complete.

## Migration Files

1. `2026_01_23_000002_rename_orders_to_checkout_sessions.php`
   - Renames `orders` table to `checkout_sessions`

2. `2026_01_23_000003_rename_order_items_to_checkout_items.php`
   - Renames `order_items` table to `checkout_items`
   - Updates foreign key constraint to reference `checkout_sessions`

3. `2026_01_23_000004_create_orders_table.php`
   - Creates new `orders` table with specified structure

## Model Changes

### New Models

1. **CheckoutSession** (`app/Models/CheckoutSession.php`)
   - Replaces old `Order` model
   - Table: `checkout_sessions`
   - Relationships:
     - `items()` → hasMany CheckoutItem
     - `order()` → hasOne Order (new orders table)

2. **CheckoutItem** (`app/Models/CheckoutItem.php`)
   - Replaces old `OrderItem` model
   - Table: `checkout_items`
   - Relationship:
     - `checkoutSession()` → belongsTo CheckoutSession

3. **Order** (`app/Models/Order.php`) - NEW
   - New model for confirmed orders
   - Table: `orders`
   - Primary Key: `order_id`
   - Relationship:
     - `checkoutSession()` → belongsTo CheckoutSession

## Code Updates

### Updated Files

1. **CheckoutController** (`app/Http/Controllers/Api/CheckoutController.php`)
   - Uses `CheckoutSession` instead of `Order`
   - Uses `CheckoutItem` instead of `OrderItem`
   - Updated method signatures and variable names

2. **OrderService** (`app/Services/OrderService.php`)
   - Works with `CheckoutSession` instead of `Order`
   - Method parameters renamed from `$orderId` to `$checkoutId` for clarity

3. **ConsumeProductValidated** (`app/Console/Commands/ConsumeProductValidated.php`)
   - Uses `CheckoutItem` instead of `OrderItem`
   - Updated to work with checkout sessions

## Data Flow

### Old Flow
```
Checkout → Create Order → Process Payment → Confirm
```

### New Flow
```
Checkout → Create CheckoutSession → Process Payment → Create Order (confirmed)
```

## Key Benefits

1. **Clear Separation**: Checkout sessions are separate from confirmed orders
2. **Better Tracking**: Can track checkout sessions that never become orders
3. **Reservation Tracking**: Orders table links to reservation and payment IDs
4. **Status Clarity**: Orders table has simple CONFIRMED/CANCELLED status
5. **Audit Trail**: Can see which checkout sessions became orders

## Migration Order

When running migrations, they will execute in this order:

1. Existing migrations (create orders, order_items, etc.)
2. `2026_01_23_000001_add_idempotency_key_to_orders_table.php` (adds idempotency_key)
3. `2026_01_23_000002_rename_orders_to_checkout_sessions.php` (renames table)
4. `2026_01_23_000003_rename_order_items_to_checkout_items.php` (renames table + FK)
5. `2026_01_23_000004_create_orders_table.php` (creates new orders table)

## Notes

- The `idempotency_key` column remains on `checkout_sessions` (not on new `orders` table)
- The new `orders` table uses `order_id` as primary key (not `id`)
- Foreign key `checkout_id` in `orders` references `checkout_sessions.id`
- All existing data in `orders` and `order_items` will be preserved during rename
