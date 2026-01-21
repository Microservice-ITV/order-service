# Quick Reference Guide

## 🚀 Getting Started

### **1. Start the Application**
```bash
# Terminal 1 - Web Server
cd c:\xampp\htdocs\order-service
php artisan serve

# Terminal 2 - Consumer (pick one)
php artisan consume:product-validated
php artisan consume:product-rejected
php artisan consume:payment-succeeded
php artisan consume:payment-failed
```

### **2. Get JWT Token**
```bash
# Login endpoint (from your auth service)
POST /api/login
{
  "email": "user@example.com",
  "password": "password"
}

# Response will include:
{
  "access_token": "eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9..."
}
```

### **3. Test the API**
Use Postman with the `postman_collection.json`

---

## 📚 File Quick Navigation

### **Controllers** (Handle requests)
- `app/Http/Controllers/CheckoutController.php` - Create orders
- `app/Http/Controllers/OrderController.php` - List, show, stats
- `app/Http/Controllers/WebhookController.php` - Webhook handlers

### **Services** (Business logic)
- `app/Services/OrderService.php` - Order operations
- `app/Services/OrderEventService.php` - Event publishing
- `app/Services/RabbitMQ/Publisher.php` - RabbitMQ publishing
- `app/Services/RabbitMQ/BaseConsumer.php` - RabbitMQ consuming

### **Repositories** (Data access)
- `app/Repositories/OrderRepository.php` - Order queries
- `app/Repositories/BaseRepository.php` - Base CRUD

### **Models** (Database)
- `app/Models/Order.php` - Order model with scopes
- `app/Models/OrderItem.php` - Order item model

### **Requests** (Validation)
- `app/Http/Requests/CheckoutRequest.php`
- `app/Http/Requests/ProductValidatedRequest.php`
- `app/Http/Requests/WebhookRequest.php`

### **Resources** (API transformation)
- `app/Http/Resources/OrderResource.php`
- `app/Http/Resources/OrderItemResource.php`

### **Middleware** (Request processing)
- `app/Http/Middleware/SanitizeInput.php` - XSS prevention
- `app/Http/Middleware/RateLimitAPI.php` - Rate limiting
- `app/Http/Middleware/Authenticate.php` - JWT authentication

### **Policies** (Authorization)
- `app/Policies/OrderPolicy.php` - Resource authorization

### **Routes**
- `routes/api.php` - All API routes

---

## 🔑 Common Tasks

### **Create Order**
```php
// In OrderRepository or OrderService
$order = $orderRepository->createPendingOrder(
    userId: 123,
    items: [
        ['product_id' => 1, 'qty' => 2, 'price' => 29.99]
    ],
    total: 59.98
);

// Publish event
$orderEventService->publishCheckoutRequested($order);
```

### **Get Orders for User**
```php
// In OrderController
$orders = Order::where('user_id', $userId)
    ->with('items')
    ->latest()
    ->paginate(15);
```

### **Update Order Status**
```php
$orderRepository->updateStatus($orderId, OrderStatus::CONFIRMED);
// Cache automatically invalidated
```

### **Handle Webhook Event**
```php
// In WebhookController
$orderEventService->handleProductValidated($order);
// or
$orderEventService->handlePaymentSucceeded($orderId);
```

### **Check Authorization**
```php
// In controller
$this->authorize('view', $order);

// Or in route
Route::middleware(['auth:api'])->group(function () {
    // Protected routes
});
```

### **Log Important Event**
```php
Log::info('Order created', [
    'order_id' => $order->id,
    'user_id' => $order->user_id,
    'total' => $order->total,
]);
```

---

## 🔄 Request Flow

```
1. HTTP Request comes in
   ↓
2. SanitizeInput Middleware (XSS prevention)
   ↓
3. RateLimitAPI Middleware (Rate limiting)
   ↓
4. Authenticate Middleware (JWT token)
   ↓
5. Route matching
   ↓
6. Controller method
   ↓
7. FormRequest Validation
   ↓
8. Service (business logic)
   ↓
9. Repository (data access)
   ↓
10. Database/Cache
   ↓
11. Resource (transform to JSON)
   ↓
12. HTTP Response
```

---

## 🧪 Testing with Postman

### **Test Checkout**
```
POST /api/checkout
Authorization: Bearer YOUR_TOKEN
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

### **Test Get Orders**
```
GET /api/orders?page=1
Authorization: Bearer YOUR_TOKEN
```

### **Test Webhook**
```
POST /api/webhooks/product/validated
Content-Type: application/json

{
  "order_id": "550e8400-e29b-41d4-a716-446655440000"
}
```

---

## 🔐 Security Checklist

- [ ] JWT token required for protected routes
- [ ] Input validated with FormRequest
- [ ] Output sanitized for XSS
- [ ] Authorization checked with Policy
- [ ] Database queries use bindings
- [ ] Sensitive data logged securely
- [ ] Errors don't expose internals
- [ ] Rate limiting prevents abuse
- [ ] HTTPS enforced in production

---

## 📊 Database Scopes

### **Order Scopes**
```php
Order::confirmed()  // status = 'confirmed'
Order::pending()    // status = 'pending_payment'
Order::failed()     // status = 'failed'
Order::rejected()   // status = 'rejected'
Order::recent()     // order by created_at DESC
```

### **Usage**
```php
$confirmedOrders = Order::confirmed()->get();
$recentOrders = Order::recent()->paginate(10);
$userOrders = Order::where('user_id', $userId)
    ->confirmed()
    ->recent()
    ->get();
```

---

## 🐛 Debugging

### **Check Logs**
```bash
tail -f storage/logs/laravel.log

# Or filter by error
grep ERROR storage/logs/laravel.log

# Or last 20 lines
tail -20 storage/logs/laravel.log
```

### **Debug Mode (development only)**
```
# .env
APP_DEBUG=true

# bootstrap/app.php
'debug' => true,
```

### **Check Cache**
```bash
php artisan cache:clear
php artisan cache:forget order:UUID_HERE
```

### **Check RabbitMQ Connection**
```bash
php artisan tinker
>>> $conn = App\Services\RabbitMQ\RabbitMQConnection::make();
>>> $conn->channel();  // Should return channel object
```

---

## 🚨 Common Issues & Solutions

### **Issue: 401 Unauthorized**
**Solution**: Ensure JWT token is valid and in header:
```
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

### **Issue: 429 Too Many Requests**
**Solution**: Wait a minute or use different user/IP

### **Issue: 422 Validation Error**
**Solution**: Check error message in response and validate input

### **Issue: RabbitMQ Connection Failed**
**Solution**: 
```bash
# Verify .env has correct credentials
# Check if CloudAMQP is accessible
# Run consumer with: php artisan consume:product-validated
```

### **Issue: Cache Not Working**
**Solution**:
```bash
php artisan cache:clear
php artisan cache:forget order:UUID
```

---

## 📈 Monitoring

### **Key Metrics to Track**
```
- Response time (target: < 200ms)
- Error rate (target: < 0.1%)
- Queue depth (RabbitMQ)
- Cache hit rate
- Database query time
- Rate limit violations
```

### **Logs to Monitor**
```
- EventPublishingException
- Queue processing failures
- Authorization denials
- Rate limit hits
- Database errors
```

---

## 📞 Quick Contact Points

### **For API Issues**
- Check `README.md` for endpoint documentation
- Check `routes/api.php` for route definitions

### **For Security Issues**
- Check `SECURITY.md` for implementation details
- Review `app/Http/Middleware/` for middleware

### **For Architecture**
- Check `REFACTORING.md` for overview
- Review `app/` directory structure

---

## ✅ Pre-Deployment Checklist

```
☐ APP_DEBUG=false
☐ APP_KEY set to strong value
☐ DB credentials secured in .env
☐ RabbitMQ credentials secured
☐ JWT_SECRET set to strong value
☐ HTTPS enabled
☐ Database migrations run
☐ Queue consumers configured
☐ Logs rotation configured
☐ Backups scheduled
☐ Monitoring alerts set up
☐ Rate limiting configured
☐ CORS configured
☐ SSL certificate valid
```

---

**Last Updated**: January 15, 2026
**Status**: Production Ready
