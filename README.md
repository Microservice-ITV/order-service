# Order Service

Event-driven microservice for order management using Laravel 12 and RabbitMQ (CloudAMQP).

## Table of Contents

- [Overview](#overview)
- [Architecture](#architecture)
- [Setup & Installation](#setup--installation)
- [Configuration](#configuration)
- [Testing](#testing)
- [API Endpoints](#api-endpoints)
- [RabbitMQ Event Flow](#rabbitmq-event-flow)
- [Database Schema](#database-schema)
- [Project Structure](#project-structure)
- [Troubleshooting](#troubleshooting)

---

## Overview

The Order Service is a microservice responsible for:
- Creating orders from customer checkouts
- Managing order lifecycle (created → pending_payment → confirmed)
- Publishing and consuming events via RabbitMQ
- Integrating with Product Service and Payment Service through event-driven architecture

### Tech Stack

- **Framework**: Laravel 12
- **Database**: MySQL
- **Message Queue**: RabbitMQ (CloudAMQP)
- **Authentication**: JWT (tymon/jwt-auth)
- **Build Tool**: Vite + Tailwind CSS
- **Testing**: PHPUnit

---

## Architecture

### Event-Driven Microservices

```
┌─────────────────┐
│  Order Service  │
│ (This Service)  │
└────────┬────────┘
         │
    RabbitMQ Exchange: order_events
         │
    ┌────┴────────────────┬──────────────────┐
    │                     │                  │
    ▼                     ▼                  ▼
PRODUCT_VALIDATED  PAYMENT_REQUESTED  PAYMENT_SUCCEEDED
(from Product)     (to Payment)       (from Payment)
```

### Layer Architecture

```
┌──────────────────────────┐
│    API Controllers       │
│  (CheckoutController)    │
└────────────┬─────────────┘
             │
┌────────────▼─────────────┐
│   Business Logic         │
│   (OrderService)         │
└────────────┬─────────────┘
             │
┌────────────▼─────────────┐
│   Data Access Layer      │
│  (OrderRepository)       │
└────────────┬─────────────┘
             │
┌────────────▼─────────────┐
│   Database (Eloquent)    │
│  (Orders, OrderItems)    │
└──────────────────────────┘
```

---

## Setup & Installation

### Prerequisites

- PHP 8.2+
- Composer
- MySQL 8.0+
- Node.js 18+
- npm
- RabbitMQ (CloudAMQP account)

### Step 1: Clone & Install Dependencies

```bash
cd c:\xampp\htdocs\order-service
composer install
npm install
```

### Step 2: Environment Configuration

Copy `.env.example` to `.env`:

```bash
cp .env.example .env
```

Update `.env` with your credentials:

```env
APP_NAME=Order Service
APP_ENV=local
APP_DEBUG=true

# Database
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=order_service
DB_USERNAME=root
DB_PASSWORD=root

# RabbitMQ (CloudAMQP)
RABBITMQ_HOST=armadillo-01.rmq.cloudamqp.com
RABBITMQ_PORT=5671
RABBITMQ_USER=your_cloudamqp_user
RABBITMQ_PASSWORD=your_cloudamqp_password
RABBITMQ_VHOST=your_cloudamqp_vhost
RABBITMQ_SSL=true
RABBITMQ_EXCHANGE=order_events
RABBITMQ_QUEUE_PRODUCT=order_service.product_stock
RABBITMQ_QUEUE_PAYMENT=order_service.payment

# JWT
JWT_SECRET=your_jwt_secret
JWT_TTL=15

# Cache & Session
CACHE_STORE=database
SESSION_DRIVER=file
```

### Step 3: Generate Keys

```bash
php artisan key:generate
php artisan jwt:secret
```

### Step 4: Database Setup

```bash
php artisan migrate
```

### Step 5: Build Frontend

```bash
npm run build
```

---

## Configuration

### RabbitMQ Configuration (`config/rabbitmq.php`)

```php
RABBITMQ_HOST           // CloudAMQP host
RABBITMQ_PORT           // 5671 (SSL), 5672 (non-SSL)
RABBITMQ_USER           // CloudAMQP username
RABBITMQ_PASSWORD       // CloudAMQP password
RABBITMQ_VHOST          // CloudAMQP vhost
RABBITMQ_SSL            // true/false
RABBITMQ_EXCHANGE       // order_events
RABBITMQ_QUEUE_PRODUCT  // Product validation/rejection queue
RABBITMQ_QUEUE_PAYMENT  // Payment response queue
```

### Event Topics (`app/Enums/EventTopic.php`)

```php
ORDER_CHECKOUT_REQUESTED   // order.created
PRODUCT_VALIDATED          // product.stock.reserved
PRODUCT_REJECTED           // product.stock.rejected
PAYMENT_REQUESTED          // payment.requested
PAYMENT_SUCCEEDED          // payment.authorized
PAYMENT_FAILED             // payment.failed
```

### Order Status (`app/Enums/OrderStatus.php`)

```
created         → Order created, awaiting product validation
pending_payment → Products validated, waiting for payment
confirmed       → Payment successful, order confirmed
rejected        → Product validation failed
failed          → Payment processing failed
```

---

## Testing

### Setup Test Environment

1. **Start Laravel Dev Server**
   ```bash
   php artisan serve
   ```
   Server runs on: `http://localhost:8000`

2. **Start RabbitMQ Consumer** (in another terminal)
   ```bash
   php artisan consume:product-validated
   ```
   Wait for: `Listening on queue: order_service.product_stock`

3. **Import Postman Collection**
   - File: `postman_collection.json`
   - Set environment variable: `base_url=http://localhost:8000`

### Complete Testing Workflow

#### Step 1: Create Order

**Postman**: Send `1. Create Order (Checkout)` request

```json
POST /api/checkout
Content-Type: application/json

{
  "items": [
    {
      "product_id": 1,
      "qty": 2,
      "price": 29.99
    }
  ]
}
```

**Response**:
```json
{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

✅ Order created with status: `created`

#### Step 2: Publish Product Validated Event

**Postman**: Send `2. Publish Product Validated Event` request

```json
POST /api/webhook/product-validated
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000",
  "total": 59.98,
  "items": [
    {
      "product_id": 1,
      "qty": 2,
      "price": 29.99
    }
  ]
}
```

✅ Consumer processes message
✅ Order status: `pending_payment`
✅ Order items created
✅ `PAYMENT_REQUESTED` event published

#### Step 3: Publish Payment Succeeded Event

**Postman**: Send `3. Publish Payment Succeeded Event` request

```json
POST /api/webhook/payment-succeeded
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

✅ Order status: `confirmed`
✅ Session cart cleared

### Verify Results

```bash
php artisan tinker
>>> use App\Models\Order;
>>> $order = Order::find('550e8400-e29b-41d4-a716-446655440000');
>>> $order->status;           // 'confirmed'
>>> $order->items()->get();   // Order items
>>> $order->total;            // 59.98
```

### Unit Tests

```bash
php artisan test
```

### Integration Tests

Test the complete flow from checkout to payment:

```bash
php artisan test tests/Feature/CheckoutTest.php
```

---

## API Endpoints

### Authentication

All endpoints (except webhooks) require JWT token:

```bash
Authorization: Bearer YOUR_JWT_TOKEN
```

### 1. Checkout - Create Order

**Create a new order from cart items**

```
POST /api/checkout
Content-Type: application/json
Authorization: Bearer <token>

{
  "items": [
    {
      "product_id": 1,
      "qty": 2,
      "price": 29.99
    }
  ]
}
```

**Response** (201 Created):
```json
{
  "success": true,
  "message": "Order created successfully",
  "data": {
    "order_id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "created",
    "total": 59.98
  }
}
```

**Status Codes**:
- `201` - Order created successfully
- `400` - Validation error (invalid items format)
- `401` - Unauthorized (invalid/missing token)
- `429` - Too many requests (rate limit exceeded)
- `500` - Server error (RabbitMQ connection issue)

---

### 2. List Orders - Get User's Orders

**Retrieve paginated list of user's orders**

```
GET /api/orders
Authorization: Bearer <token>
```

**Query Parameters** (optional):
```
?page=1
?per_page=15
?sort=-created_at
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Orders retrieved successfully",
  "data": [
    {
      "id": "uuid",
      "status": "confirmed",
      "total": 99.99,
      "items_count": 2,
      "items": [
        {
          "id": 1,
          "product_id": 1,
          "quantity": 2,
          "price": 49.99,
          "subtotal": 99.98,
          "created_at": "2026-01-15T10:30:00Z"
        }
      ],
      "created_at": "2026-01-15T10:00:00Z",
      "updated_at": "2026-01-15T10:30:00Z"
    }
  ],
  "meta": {
    "current_page": 1,
    "from": 1,
    "last_page": 1,
    "per_page": 15,
    "to": 1,
    "total": 1
  }
}
```

---

### 3. Get Order - Order Details

**Retrieve specific order with items**

```
GET /api/orders/{id}
Authorization: Bearer <token>
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Order retrieved successfully",
  "data": {
    "id": "550e8400-e29b-41d4-a716-446655440000",
    "status": "confirmed",
    "total": 99.99,
    "items_count": 2,
    "items": [
      {
        "id": 1,
        "product_id": 1,
        "quantity": 2,
        "price": 49.99,
        "subtotal": 99.98,
        "created_at": "2026-01-15T10:00:00Z"
      }
    ],
    "created_at": "2026-01-15T10:00:00Z",
    "updated_at": "2026-01-15T10:30:00Z"
  }
}
```

**Status Codes**:
- `200` - Success
- `401` - Unauthorized
- `403` - Cannot view other user's orders
- `404` - Order not found

---

### 4. Order Statistics - Analytics

**Get order statistics for authenticated user**

```
GET /api/orders/statistics
Authorization: Bearer <token>
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Statistics retrieved successfully",
  "data": {
    "total_orders": 15,
    "confirmed_orders": 12,
    "pending_orders": 2,
    "failed_orders": 1,
    "total_spent": 1499.99,
    "average_order_value": 99.99
  }
}
```

---

## Webhook Endpoints

### Webhooks from Other Microservices

These endpoints receive events from Product Service and Payment Service.
No authentication required (internal service communication).

### 1. Product Validated Webhook

**Called when product stock is reserved**

```
POST /api/webhooks/product/validated
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Product validation processed"
}
```

---

### 2. Product Rejected Webhook

**Called when product is out of stock**

```
POST /api/webhooks/product/rejected
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Product rejection processed"
}
```

---

### 3. Payment Succeeded Webhook

**Called when payment is authorized**

```
POST /api/webhooks/payment/succeeded
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Payment success processed"
}
```

---

### 4. Payment Failed Webhook

**Called when payment is declined**

```
POST /api/webhooks/payment/failed
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

**Response** (200 OK):
```json
{
  "success": true,
  "message": "Payment failure processed"
}
```

---

### 5. Health Check Endpoint

**Check service status**

```
GET /api/health
```

**Response** (200 OK):
```json
{
  "status": "ok",
  "service": "order-service",
  "timestamp": "2026-01-15T10:30:00Z"
}
```

---

## Error Handling
```

**Response** (400 Bad Request):
```json
{
  "error": "Cart empty"
}
```

---

### 2. Product Validated Webhook

**Simulate product service validation**

```
POST /api/webhook/product-validated
Content-Type: application/json

{
  "order_id": "uuid",
  "total": decimal,
  "items": [
    {
      "product_id": integer,
      "qty": integer,
      "price": decimal
    }
  ]
}
```

**Response** (200 OK):
```json
{
  "message": "Product validated event published",
  "status": "published"
}
```

---

### 3. Product Rejected Webhook

**Simulate product validation failure (out of stock)**

```
POST /api/webhook/product-rejected
Content-Type: application/json

{
  "order_id": "uuid"
}
```

---

### 4. Payment Succeeded Webhook

**Simulate successful payment**

```
POST /api/webhook/payment-succeeded
Content-Type: application/json

{
  "order_id": "uuid"
}
```

---

### 5. Payment Failed Webhook

**Simulate payment failure**

```
POST /api/webhook/payment-failed
Content-Type: application/json

{
  "order_id": "uuid"
}
```

---

## RabbitMQ Event Flow

### Order Creation Flow

```
1. Client: POST /api/checkout
   ↓
2. OrderService: Create order (status: created)
   ↓
3. Publisher: Emit ORDER_CHECKOUT_REQUESTED
   ↓
4. RabbitMQ: Route to order_events exchange
   ↓
5. Product Service: (Subscribes and validates stock)
   ↓
6. Product Service: Publishes PRODUCT_VALIDATED
   ↓
7. Order Service Consumer: Receives event
   ↓
8. OrderService: Update status to pending_payment
   ↓
9. OrderRepository: Create order items
   ↓
10. Publisher: Emit PAYMENT_REQUESTED
    ↓
11. Payment Service: (Subscribes and processes payment)
    ↓
12. Payment Service: Publishes PAYMENT_SUCCEEDED
    ↓
13. Order Service Consumer: Receives event
    ↓
14. OrderService: Confirm order (status: confirmed)
    ↓
✅ Order Complete
```

### Event Topics & Routing Keys

| Topic | Routing Key | Publisher | Consumer |
|-------|-------------|-----------|----------|
| ORDER_CHECKOUT_REQUESTED | order.created | Order Service | Product Service |
| PRODUCT_VALIDATED | product.stock.reserved | Product Service | Order Service |
| PRODUCT_REJECTED | product.stock.rejected | Product Service | Order Service |
| PAYMENT_REQUESTED | payment.requested | Order Service | Payment Service |
| PAYMENT_SUCCEEDED | payment.authorized | Payment Service | Order Service |
| PAYMENT_FAILED | payment.failed | Payment Service | Order Service |

---

## Database Schema

### Orders Table

```sql
CREATE TABLE orders (
  id UUID PRIMARY KEY,
  status VARCHAR(255),
  total DECIMAL(10, 2),
  created_at TIMESTAMP,
  updated_at TIMESTAMP
);
```

**Columns**:
- `id` (UUID): Unique order identifier
- `status`: Current order status (created, pending_payment, confirmed, rejected, failed)
- `total`: Order total amount
- `created_at`: Order creation timestamp
- `updated_at`: Last update timestamp

### Order Items Table

```sql
CREATE TABLE order_items (
  id INT PRIMARY KEY AUTO_INCREMENT,
  order_id UUID FOREIGN KEY,
  product_id INT,
  qty INT,
  price DECIMAL(10, 2)
);
```

**Columns**:
- `id`: Item ID
- `order_id`: Reference to orders table (cascade delete)
- `product_id`: Product identifier
- `qty`: Quantity ordered
- `price`: Unit price at time of order

---

## Project Structure

```
order-service/
├── app/
│   ├── Console/
│   │   ├── Commands/
│   │   │   ├── ConsumeProductValidated.php     # Listen for product validation
│   │   │   ├── ConsumeProductRejected.php      # Listen for product rejection
│   │   │   ├── ConsumePaymentSucceeded.php     # Listen for payment success
│   │   │   └── ConsumePaymentFailed.php        # Listen for payment failure
│   │   └── Kernel.php
│   ├── Contracts/
│   │   ├── BaseInterface.php                   # Base repository contract
│   │   └── OrderInterface.php                  # Order repository contract
│   ├── Enums/
│   │   ├── OrderStatus.php                     # Order status enum
│   │   └── EventTopic.php                      # RabbitMQ event topics
│   ├── Exceptions/
│   │   ├── BusinessException.php
│   │   └── RepositoryException.php
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Api/
│   │   │   │   ├── CheckoutController.php      # Create order endpoint
│   │   │   │   └── WebhookController.php       # Event webhook endpoints
│   │   │   └── Controller.php
│   │   └── Middleware/
│   │       ├── Authenticate.php
│   │       └── SanitizeInput.php               # XSS protection
│   ├── Models/
│   │   ├── Order.php                           # Order model
│   │   └── OrderItem.php                       # OrderItem model
│   ├── Providers/
│   │   ├── AppServiceProvider.php
│   │   ├── RepositoryServiceProvider.php       # Auto-bind repositories
│   │   └── RouteServiceProvider.php
│   ├── Repositories/
│   │   ├── BaseRepository.php                  # Base CRUD operations
│   │   └── OrderRepository.php                 # Order-specific queries
│   ├── Services/
│   │   ├── OrderService.php                    # Business logic
│   │   └── RabbitMQ/
│   │       ├── RabbitMQConnection.php          # AMQP connection
│   │       ├── Publisher.php                   # Publish events
│   │       └── BaseConsumer.php                # Consume events
│   └── Traits/
│       └── Cachable.php                        # Cache helper methods
├── config/
│   ├── rabbitmq.php                            # RabbitMQ configuration
│   ├── app.php
│   ├── database.php
│   └── ... (other Laravel configs)
├── database/
│   ├── migrations/
│   │   ├── *_create_orders_table.php
│   │   └── *_create_order_items_table.php
│   └── seeders/
├── routes/
│   ├── api.php                                 # API routes
│   └── web.php
├── tests/
│   ├── Feature/
│   │   └── CheckoutTest.php
│   └── Unit/
├── storage/
│   └── logs/
│       └── laravel.log                         # Application logs
├── .env
├── .env.example
├── composer.json
├── package.json
├── phpunit.xml
├── postman_collection.json                     # Postman test collection
└── README.md                                   # This file
```

---

## Troubleshooting

### Issue: RabbitMQ Connection Failed

**Error**: `PhpAmqpLib\Exception\AMQPConnectionClosedException`

**Solutions**:
1. Verify CloudAMQP credentials in `.env`
2. Check SSL is enabled: `RABBITMQ_SSL=true`
3. Use correct port: `5671` (SSL) or `5672` (non-SSL)
4. Test connection in tinker:
   ```bash
   php artisan tinker
   >>> use App\Services\RabbitMQ\RabbitMQConnection;
   >>> $conn = RabbitMQConnection::make();
   >>> $conn->isConnected();  // Should return true
   ```

### Issue: Consumer Not Processing Messages

**Solutions**:
1. Verify consumer is running: `php artisan consume:product-validated`
2. Check CloudAMQP dashboard for messages in queue
3. Monitor logs: `php artisan pail`
4. Verify routing keys match in events and consumers

### Issue: Order Items Not Created

**Solutions**:
1. Check database transaction errors in logs
2. Verify payload structure matches expected format
3. Test with simpler payload (fewer items)
4. Check database connection

### Issue: Queue Not Appearing in CloudAMQP

**Solutions**:
1. Queues are created dynamically when consumers start
2. Restart consumer: `php artisan consume:product-validated`
3. Refresh CloudAMQP dashboard UI
4. Check exchange bindings in CloudAMQP

### View Logs

```bash
# Real-time logs
php artisan pail

# Or tail file
tail -f storage/logs/laravel.log
```

### Database Issues

```bash
# Reset database
php artisan migrate:refresh

# Seed database
php artisan db:seed

# Check migrations status
php artisan migrate:status
```

---

## Development Commands

```bash
# Start development server
php artisan serve

# Start RabbitMQ consumer
php artisan consume:product-validated

# Run tests
php artisan test

# Clear cache
php artisan cache:clear

# Clear logs
php artisan log:clear

# Access Tinker
php artisan tinker

# Watch frontend changes
npm run dev

# Build frontend
npm run build
```

---


