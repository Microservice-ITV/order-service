# Order Service - Complete Project Review

**Date**: January 21, 2026  
**Framework**: Laravel 12  
**Message Queue**: RabbitMQ (CloudAMQP)  
**Database**: MySQL  
**Authentication**: JWT (tymon/jwt-auth)

---

## 📋 Project Overview

The Order Service is an **event-driven microservice** responsible for:
- Creating orders from customer checkouts
- Managing order lifecycle (created → pending_payment → confirmed/failed)
- Publishing and consuming events via RabbitMQ
- Integrating with Product Service and Payment Service through asynchronous event-driven architecture

### Tech Stack
- **Framework**: Laravel 12
- **Database**: MySQL
- **Message Queue**: RabbitMQ (CloudAMQP)
- **Authentication**: JWT (tymon/jwt-auth)
- **Build Tool**: Vite + Tailwind CSS
- **Testing**: PHPUnit

---

## 🏗️ Architecture

### Layered Architecture
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

### Event-Driven Flow
```
User Checkout
    ↓
[ORDER_CHECKOUT_REQUESTED] → Product Service
    ↓
[PRODUCT_VALIDATED] → Mark pending payment + Request payment
    ↓
[PAYMENT_SUCCEEDED] → Confirm order
    ↓
Order Status: CONFIRMED

OR at any step:
[PRODUCT_REJECTED] / [PAYMENT_FAILED] → Order rejected/failed
```

---

## 📁 Project Structure

### app/Console
**Purpose**: Artisan console commands for event consumption and utility tasks

#### Commands
- **ConsumeAll.php** (NEW)
  - Manages all 4 RabbitMQ consumers simultaneously
  - Uses Symfony\Process\Process for subprocess management
  - Auto-restarts failed consumers
  - Formatted CLI output with status indicators
  - Command: `php artisan consume:all`

- **ConsumeProductValidated.php**
  - Listens for `product.stock.reserved` events
  - Creates OrderItems and publishes `payment.requested`
  - Queue: `rabbitmq.queues.product`

- **ConsumeProductRejected.php**
  - Listens for `product.stock.rejected` events
  - Updates order status to REJECTED
  - Queue: `rabbitmq.queues.product`

- **ConsumePaymentSucceeded.php**
  - Listens for `payment.authorized` events
  - Updates order status to CONFIRMED
  - Clears session cart
  - Queue: `rabbitmq.queues.payment`

- **ConsumePaymentFailed.php**
  - Listens for `payment.failed` events
  - Updates order status to FAILED
  - Queue: `rabbitmq.queues.payment`

#### Kernel.php
- Registers all 5 commands (4 consumers + 1 ConsumeAll)
- Handles command scheduling (currently empty)

---

### app/Http

#### Controllers/Api
- **CheckoutController.php**
  - Single endpoint: `POST /orders/checkout`
  - Receives items from request body or session
  - Creates order via OrderService
  - Publishes `ORDER_CHECKOUT_REQUESTED` event to RabbitMQ
  - Uses lightweight service injection

- **WebhookController.php**
  - Simulates events from Product & Payment services (for testing)
  - 4 endpoints:
    - `POST /webhooks/product/validated` - Publish product validation
    - `POST /webhooks/product/rejected` - Publish product rejection
    - `POST /webhooks/payment/succeeded` - Publish payment success
    - `POST /webhooks/payment/failed` - Publish payment failure
  - All endpoints validate input and publish to RabbitMQ

#### Middleware
- **Authenticate.php**
  - Extends Laravel's Authenticate middleware
  - Disables redirects for API endpoints
  - Returns JSON 401 on authentication failure

- **SanitizeInput.php** (XSS Protection)
  - Sanitizes all incoming request data
  - Uses `FILTER_SANITIZE_STRING` for keys
  - Uses `strip_tags()` for string values
  - Protects against XSS and malicious inputs
  - Applied globally via bootstrap/app.php

#### Responses
- **JsonResponseTrait.php**
  - Helper trait for consistent JSON responses
  - Methods: `success(array $data)`, `error(string $message, array $errors)`

---

### app/Services

#### OrderService.php
**Responsibility**: Business logic for order lifecycle management

Methods:
- `create()`: Creates new order with CREATED status (UUID)
- `markPendingPayment(orderId, total)`: Updates status to PENDING_PAYMENT
- `confirm(orderId)`: Updates status to CONFIRMED
- `reject(orderId)`: Updates status to REJECTED
- `fail(orderId)`: Updates status to FAILED

#### RabbitMQ/

**RabbitMQConnection.php**
- Static factory for AMQPStreamConnection
- SSL support for CloudAMQP
- Reads config from config/rabbitmq.php

**Publisher.php**
- Static method: `publish(EventTopic $topic, array $payload)`
- Declares exchange as 'topic'
- Publishes persistent messages (DELIVERY_MODE_PERSISTENT)
- Auto-closes connection after publish

**BaseConsumer.php**
- Abstract base class for all consumer commands
- Abstract methods: `routingKeys()`, `queue()`, `process()`
- Handles connection, exchange/queue declaration, binding
- Infinite loop listening for messages with `$channel->wait()`

---

### app/Repositories

#### BaseRepository.php
**Responsibility**: Reusable CRUD operations for all models

Features:
- Error handling with logging and custom RepositoryException
- Transaction support
- Methods: `all()`, `findById()`, `create()`, `update()`, `delete()`
- Implements BaseInterface contract

#### OrderRepository.php
Extends BaseRepository, implements OrderInterface

Methods:
- `createPendingOrder(userId, items, total)`: Creates order + items in transaction
- `paginateByUser(userId)`: Get user's orders with pagination (10 per page)
- `getByUserAndId(userId, orderId)`: Fetch specific user's order with items
- `markAsPaid(orderId)`: Update status to PAID
- `markAsCancelled(orderId)`: Update status to CANCELLED

---

### app/Models

#### Order.php
- **Primary Key**: UUID (string, not auto-increment)
- **Timestamps**: Automatic created_at/updated_at
- **Fillable**: id, status, total
- **Relation**: `items()` → hasMany(OrderItem)

#### OrderItem.php
- **No timestamps**
- **Fillable**: order_id, product_id, qty, price
- **Foreign Key**: order_id → orders.id (cascadeOnDelete)

---

### app/Enums

#### EventTopic.php
```php
case ORDER_CHECKOUT_REQUESTED = 'order.created';
case PRODUCT_VALIDATED = 'product.stock.reserved';
case PRODUCT_REJECTED = 'product.stock.rejected';
case PAYMENT_REQUESTED = 'payment.requested';
case PAYMENT_SUCCEEDED = 'payment.authorized';
case PAYMENT_FAILED = 'payment.failed';
```

#### OrderStatus.php
```php
case CREATED = 'created';
case REJECTED = 'rejected';
case PENDING_PAYMENT = 'pending_payment';
case CONFIRMED = 'confirmed';
case FAILED = 'failed';
```

---

### app/Contracts

#### BaseInterface.php
Defines common repository contract methods:
- `create(array $data)`
- `all()`
- `update(int $id, array $data)`
- `delete(int $id)`
- `findById(int $id): ?Model`

#### OrderInterface.php
Extends BaseInterface with order-specific methods:
- `createPendingOrder(int $userId, array $items, float $total): Order`
- `paginateByUser(int $userId): LengthAwarePaginator`
- `getByUserAndId(int $userId, int $orderId): Order`
- `markAsPaid(int $orderId): void`
- `markAsCancelled(int $orderId): void`

---

### app/Exceptions

#### BusinessException.php
- Used for business logic errors
- Default status code: 422 (Unprocessable Entity)
- Stores status code as property

#### RepositoryException.php
- Used for repository/database errors
- Default status code: 500 (Internal Server Error)
- Supports exception chaining with previous
- `render()` method returns JSON response

---

### app/Providers

#### AppServiceProvider.php
- Registers no additional services
- Forces HTTPS in production (`APP_ENV !== 'local'`)

#### RepositoryServiceProvider.php
- Automatically binds interfaces to repositories
- Scans Contracts/ directory for interfaces
- Converts interface names to repository names
- Example: `OrderInterface` → `OrderRepository`
- Skips `BaseInterface`

---

### app/Traits

#### Cachable.php
Currently empty (for future caching logic)

---

## 🛣️ Routes

### API Routes (routes/api.php)

#### Checkout Endpoint
```
POST /api/orders/checkout
Controller: CheckoutController@checkout
```
**Request Body**:
```json
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
  "order_id": "uuid-here"
}
```

#### Webhook Endpoints (for testing)
```
POST /api/webhooks/product/validated
POST /api/webhooks/product/rejected
POST /api/webhooks/payment/succeeded
POST /api/webhooks/payment/failed
```

---

## 🗄️ Database Schema

### orders table
- `id` (UUID, primary key)
- `status` (string: created, rejected, pending_payment, confirmed, failed)
- `total` (decimal, nullable)
- `created_at`, `updated_at` (timestamps)

### order_items table
- `id` (integer, auto-increment)
- `order_id` (UUID, foreign key → orders.id, cascadeOnDelete)
- `product_id` (integer)
- `qty` (integer)
- `price` (decimal)

---

## ⚙️ Configuration

### config/rabbitmq.php
```php
'host' => env('RABBITMQ_HOST'),          // CloudAMQP URL or localhost
'port' => env('RABBITMQ_PORT', 5672),
'user' => env('RABBITMQ_USER'),
'password' => env('RABBITMQ_PASSWORD'),
'vhost' => env('RABBITMQ_VHOST'),
'exchange' => env('RABBITMQ_EXCHANGE'),
'ssl' => env('RABBITMQ_SSL', false),
'queues' => [
    'product' => env('RABBITMQ_QUEUE_PRODUCT'),
    'payment' => env('RABBITMQ_QUEUE_PAYMENT'),
]
```

### .env Requirements
```dotenv
RABBITMQ_HOST=amqps://user:pass@host/vhost
RABBITMQ_PORT=5672
RABBITMQ_USER=guest
RABBITMQ_PASSWORD=guest
RABBITMQ_VHOST=/
RABBITMQ_EXCHANGE=order_exchange
RABBITMQ_QUEUE_PRODUCT=product_queue
RABBITMQ_QUEUE_PAYMENT=payment_queue

DB_DATABASE=order_service
DB_USERNAME=root
DB_PASSWORD=root

JWT_SECRET=your-secret-here
JWT_TTL=15
JWT_REFRESH_TTL=20160
```

---

## 🔒 Security Features

### 1. Input Sanitization (XSS Protection)
- **Middleware**: SanitizeInput
- Sanitizes all request inputs using `FILTER_SANITIZE_STRING`
- Strips HTML tags from string values
- Applied globally via bootstrap/app.php

### 2. Authentication
- JWT-based authentication
- Routes protected with `middleware('auth:api')`
- Returns 401 JSON on unauthenticated requests

### 3. Error Handling
- Custom exception rendering in bootstrap/app.php
- 401: Unauthenticated
- 403: Authorization failed
- Sanitized error messages (no stack traces in production)

### 4. Database Security
- Foreign key constraints with cascadeOnDelete
- UUID primary keys (harder to guess/enumerate)
- Transaction support for data consistency

---

## 🚀 Usage

### Development Setup
```bash
# Install dependencies
composer install
npm install

# Create .env from .env.example
cp .env.example .env

# Generate app key
php artisan key:generate

# Run migrations
php artisan migrate

# Build frontend assets
npm run build
```

### Running Consumers

#### Option 1: Run All Consumers At Once (NEW)
```bash
php artisan consume:all
```
This single command starts all 4 consumers simultaneously with auto-restart on failure.

#### Option 2: Run Individual Consumers
```bash
# Terminal 1
php artisan consume:product-validated

# Terminal 2
php artisan consume:product-rejected

# Terminal 3
php artisan consume:payment-succeeded

# Terminal 4
php artisan consume:payment-failed
```

### Development Server
```bash
php artisan serve

# In another terminal - watch frontend changes
npm run dev
```

### Testing
```bash
php artisan test

# Run specific test
php artisan test tests/Feature/CheckoutTest.php
```

---

## 🔄 Event Flow Example

### Successful Order Flow

1. **User places order**
   ```
   POST /api/orders/checkout
   Body: { items: [...] }
   ```

2. **CheckoutController**
   - Creates Order (status: CREATED)
   - Publishes: ORDER_CHECKOUT_REQUESTED

3. **Product Service** (external)
   - Validates stock
   - Publishes: PRODUCT_VALIDATED

4. **ConsumeProductValidated** (this service)
   - Marks order as PENDING_PAYMENT
   - Creates OrderItems
   - Publishes: PAYMENT_REQUESTED

5. **Payment Service** (external)
   - Processes payment
   - Publishes: PAYMENT_SUCCEEDED

6. **ConsumePaymentSucceeded** (this service)
   - Marks order as CONFIRMED
   - Clears cart session
   - ✅ Order complete!

### Failure Flow

1. **Product Service**
   - Stock unavailable
   - Publishes: PRODUCT_REJECTED

2. **ConsumeProductRejected**
   - Marks order as REJECTED
   - ❌ Order failed

OR

1. **Payment Service**
   - Payment declined
   - Publishes: PAYMENT_FAILED

2. **ConsumePaymentFailed**
   - Marks order as FAILED
   - ❌ Order failed

---

## 📊 Key Dependencies

### Production
- `laravel/framework: ^12.0` - Core framework
- `php-amqplib/php-amqplib: ^3.6` - RabbitMQ client
- `tymon/jwt-auth: ^2.2` - JWT authentication
- `laravel/tinker: ^2.10.1` - Interactive shell

### Development
- `phpunit/phpunit: ^11.5.3` - Testing
- `mockery/mockery: ^1.6` - Mocking
- `laravel/pail: ^1.2.2` - Log viewing
- `laravel/sail: ^1.41` - Docker containerization

### Frontend
- `vite: ^7.0.7` - Build tool
- `laravel-vite-plugin: ^2.0.0` - Vite integration
- `tailwindcss: ^4.0.0` - CSS framework
- `axios: ^1.11.0` - HTTP client

---

## 🐛 Current Status

### ✅ Completed
- Full repository pattern implementation
- Service layer architecture
- RabbitMQ event-driven communication
- Input sanitization (XSS protection)
- Error handling with custom exceptions
- JWT authentication
- ConsumeAll command for managing multiple consumers
- Database migrations and models
- Webhook endpoints for testing

### 🔧 How to Extend
1. **Add new event**: Add case to `app/Enums/EventTopic.php`
2. **Create consumer**: Extend `BaseConsumer`, implement abstract methods
3. **Register command**: Add to `app/Console/Kernel.php` (auto-included in ConsumeAll)
4. **Add validation**: Create FormRequest in `app/Http/Requests/`
5. **Add repository method**: Extend `OrderRepository` with new queries

---

## 📚 Testing with Postman

Use `postman_collection.json` in root directory:
1. Import into Postman
2. Set environment variables:
   - `base_url`: http://localhost:8000/api
   - `order_id`: UUID from checkout response
3. Test endpoints in order:
   - POST /orders/checkout
   - POST /webhooks/product/validated
   - POST /webhooks/payment/succeeded

---

## 🎯 Summary

The Order Service is a well-architected, event-driven microservice with:
- ✅ Clean separation of concerns (Controller → Service → Repository → Model)
- ✅ Type-safe enums for events and statuses
- ✅ Comprehensive error handling
- ✅ Security hardening (XSS protection, input validation)
- ✅ Asynchronous event processing via RabbitMQ
- ✅ Easy consumer management via ConsumeAll command
- ✅ Production-ready database schema
- ✅ Full documentation and examples

The architecture follows SOLID principles and is ready for production deployment.
