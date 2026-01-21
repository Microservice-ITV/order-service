# Security Best Practices & Implementation Guide

## Overview

This document outlines the security measures implemented in the Order Service and best practices for deployment and maintenance.

---

## 1. Input Validation & Sanitization

### FormRequest Validation

All user input is validated through Laravel FormRequest classes:

#### CheckoutRequest
```php
// Location: app/Http/Requests/CheckoutRequest.php
- items: required array with min:1, max:100 items
- items.*.product_id: integer, min:1
- items.*.qty: integer, min:1, max:1000
- items.*.price: numeric, min:0.01
```

#### WebhookRequest
```php
// Location: app/Http/Requests/WebhookRequest.php
- order_id: uuid, exists:orders,id
```

### XSS Prevention (SanitizeInput Middleware)

```php
// Automatically applied to all requests
- htmlspecialchars() - Converts HTML entities
- strip_tags() - Removes HTML/PHP tags
- Recursive sanitization for nested arrays
- File uploads bypass sanitization
```

**Example**:
```
Input: <script>alert('xss')</script>
Output: &lt;script&gt;alert('xss')&lt;/script&gt;
```

---

## 2. Authentication & Authorization

### JWT Authentication

Protected routes require JWT token:

```bash
Authorization: Bearer eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...
```

**Token Details**:
- **Guard**: `api` (JWT)
- **TTL**: 15 minutes (configurable in config/jwt.php)
- **Secret**: Stored in .env (APP_KEY)
- **Refresh**: Client must re-authenticate for new token

### Authorization Policies

**OrderPolicy** (`app/Policies/OrderPolicy.php`):

```php
// Only user who owns the order can view it
$this->authorize('view', $order);

// Check in controller:
if (! auth()->user()->can('view', $order)) {
    return response()->json(['message' => 'Unauthorized'], 403);
}
```

### Route Protection

```php
// Protected routes (require auth:api middleware)
Route::middleware(['auth:api'])->group(function () {
    Route::get('/orders', 'OrderController@index');
    Route::post('/checkout', 'CheckoutController@store');
});

// Public webhooks (no auth required)
Route::post('/webhooks/product/validated', 'WebhookController@productValidated');
```

---

## 3. Rate Limiting

### RateLimitAPI Middleware

```php
// Location: app/Http/Middleware/RateLimitAPI.php
- Limit: 100 requests per minute per user
- Fallback: IP address for unauthenticated requests
- Header: X-RateLimit-Remaining
```

**Response when limit exceeded** (429):
```json
{
  "success": false,
  "message": "Too many requests. Please try again later."
}
```

### Configuration

In `bootstrap/app.php`:
```php
$middleware->alias([
    'rate.limit' => RateLimitAPI::class,
]);
```

---

## 4. Data Protection

### Database Security

1. **UUID Primary Keys** - Prevent ID enumeration
   ```sql
   CREATE TABLE orders (
       id CHAR(36) PRIMARY KEY,  -- UUID
       user_id BIGINT UNSIGNED,
       status VARCHAR(20),
       total DECIMAL(10,2)
   );
   ```

2. **Password Hashing** - bcrypt with Laravel auth
   ```php
   Hash::make($password);  // bcrypt with 10 rounds
   ```

3. **Encrypted Configuration** - .env file
   ```
   APP_KEY=base64:ENCRYPTED_KEY_HERE
   DB_PASSWORD=ENCRYPTED_PASSWORD
   RABBITMQ_PASSWORD=ENCRYPTED_PASSWORD
   ```

### Data Encryption (Optional)

For sensitive fields, use Laravel's encryption:

```php
// In Order model
protected $casts = [
    'customer_notes' => 'encrypted',
];

// Automatic encryption/decryption
$order->customer_notes = 'sensitive data';  // Auto-encrypted on save
echo $order->customer_notes;  // Auto-decrypted on retrieval
```

---

## 5. HTTPS & SSL/TLS

### Force HTTPS in Production

In `AppServiceProvider.php`:
```php
if (env('APP_ENV') !== 'local') {
    URL::forceScheme('https');
}
```

### RabbitMQ SSL Connection

```php
// config/rabbitmq.php
'host'      => env('RABBITMQ_HOST'),
'port'      => 5671,  // SSL port (not 5672)
'ssl'       => true,  // Enable SSL
'ssl_verify' => true,
```

---

## 6. Logging & Monitoring

### Centralized Logging

All critical operations are logged:

```php
// Order creation
Log::info('Order created successfully', [
    'order_id' => $order->id,
    'user_id'  => $order->user_id,
]);

// Event publishing
Log::error('Failed to publish event', [
    'order_id' => $order->id,
    'error'    => $e->getMessage(),
]);

// Authorization failures
Log::warning('Unauthorized access attempt', [
    'user_id' => auth()->id(),
    'resource' => 'order_' . $orderId,
]);
```

**Log Location**: `storage/logs/laravel.log`

### Monitoring Checklist

```
☐ Monitor API response times
☐ Track failed checkout attempts
☐ Watch for repeated 401/403 errors (potential attacks)
☐ Monitor RabbitMQ queue depth
☐ Alert on consumer process crashes
☐ Track payment webhook failures
```

---

## 7. Error Handling

### Avoid Information Leakage

❌ **DO NOT expose**:
```json
{
  "error": "SQLSTATE[HY000]: General error: 1030 Got error"
}
```

✅ **DO return**:
```json
{
  "success": false,
  "message": "Unable to process request. Please try again later."
}
```

### Exception Handling Strategy

```php
try {
    // Code that might fail
} catch (QueryException $e) {
    Log::error('Database error', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'Service unavailable'], 503);
} catch (Exception $e) {
    Log::error('Unknown error', ['error' => $e->getMessage()]);
    return response()->json(['message' => 'An error occurred'], 500);
}
```

---

## 8. Webhook Security

### Current Implementation

For **testing/internal use only**:
- No authentication required
- Order ID validation ensures order exists
- FormRequest validation prevents invalid data

### Production Implementation

Add these security measures:

#### 1. Webhook Signature Verification

```php
// In WebhookRequest
public function rules(): array {
    return [
        'order_id' => 'uuid|exists:orders,id',
        'signature' => 'required|string',
        'timestamp' => 'required|integer',
    ];
}

// In WebhookController
private function verifySignature($payload, $signature): bool {
    $computed = hash_hmac(
        'sha256',
        json_encode($payload),
        config('services.webhook_secret')
    );
    return hash_equals($computed, $signature);
}
```

#### 2. IP Whitelisting

```php
// In WebhookController
private function validateIp(Request $request): bool {
    $allowedIps = config('services.webhook_ips');
    return in_array($request->ip(), $allowedIps);
}
```

#### 3. Request Signing Middleware

```php
// Verify timestamp is recent (within 5 minutes)
if (time() - $request->timestamp > 300) {
    return response()->json(['message' => 'Request expired'], 401);
}
```

#### 4. Idempotency Key

```php
// Prevent duplicate processing
$idempotencyKey = $request->header('X-Idempotency-Key');
if (Cache::has("webhook:{$idempotencyKey}")) {
    return response()->json(['message' => 'Already processed'], 200);
}
Cache::put("webhook:{$idempotencyKey}", true, 3600);
```

---

## 9. Database Query Security

### SQL Injection Prevention

✅ **Use parameterized queries**:
```php
// Safe - Uses parameter binding
$order = Order::where('id', $id)->first();

// Safe - Uses Eloquent ORM
$order = Order::find($id);

// Safe - Query builder with bindings
DB::table('orders')->where('id', '=', $id);
```

❌ **Never concatenate user input**:
```php
// VULNERABLE - DO NOT USE
$order = DB::select("SELECT * FROM orders WHERE id = '$id'");
```

---

## 10. API Security Headers

### Recommended Headers

Add to middleware or web server:

```
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-XSS-Protection: 1; mode=block
Strict-Transport-Security: max-age=31536000; includeSubDomains
Content-Security-Policy: default-src 'self'
```

### Implementation in Nginx

```nginx
add_header X-Content-Type-Options "nosniff" always;
add_header X-Frame-Options "DENY" always;
add_header X-XSS-Protection "1; mode=block" always;
add_header Strict-Transport-Security "max-age=31536000" always;
```

---

## 11. Environment Configuration

### .env Security Checklist

```env
# ✅ DO
APP_KEY=base64:ENCRYPTED_STRONG_KEY_32_BYTES
APP_ENV=production
APP_DEBUG=false  # NEVER true in production

DB_PASSWORD=STRONG_PASSWORD_MIN_16_CHARS
RABBITMQ_PASSWORD=STRONG_PASSWORD_MIN_16_CHARS
JWT_SECRET=STRONG_JWT_SECRET_MIN_32_CHARS

# ❌ DON'T
APP_DEBUG=true              # Exposes stack traces
RABBITMQ_PASSWORD=password  # Default password
JWT_SECRET=secret           # Weak secret
```

### Permissions

```bash
# Restrict .env file access
chmod 600 .env

# Restrict storage/logs
chmod 755 storage
chmod 755 storage/logs

# Web server should not have write access to app/
chmod 755 app
```

---

## 12. Deployment Security Checklist

```
Database
☐ Enable binary logging
☐ Disable external network access
☐ Use strong passwords for DB users
☐ Restrict DB user privileges (no DROP, TRUNCATE)
☐ Enable SSL for remote connections
☐ Regular backups with encryption
☐ Monitor slow query logs

Application
☐ Set APP_DEBUG=false
☐ Generate new APP_KEY
☐ Set secure JWT_SECRET
☐ Enable HTTPS with valid SSL certificate
☐ Configure CORS properly
☐ Disable directory listing
☐ Remove .env from version control

RabbitMQ
☐ Use SSL port (5671)
☐ Change default credentials
☐ Restrict management UI access
☐ Monitor connection count
☐ Enable SSL certificate verification

Server
☐ Keep OS and dependencies updated
☐ Configure firewall rules
☐ Enable SELinux/AppArmor
☐ Use strong SSH keys (no root login)
☐ Set up WAF (Web Application Firewall)
☐ Enable DDoS protection
☐ Configure rate limiting at server level
```

---

## 13. Incident Response

### Security Incident Procedure

1. **Immediate Actions**
   - Take service offline if compromised
   - Preserve logs and evidence
   - Notify stakeholders

2. **Investigation**
   - Review access logs
   - Check database audit logs
   - Analyze RabbitMQ message queue

3. **Remediation**
   - Rotate credentials
   - Update security rules
   - Patch vulnerabilities
   - Restore from clean backup

4. **Communication**
   - Notify affected users
   - Provide security guidance
   - Publish post-incident report

---

## 14. Regular Security Tasks

### Daily
- Monitor error logs
- Review failed login attempts
- Check RabbitMQ queue health

### Weekly
- Review access logs
- Check for rate limit violations
- Test backup restoration

### Monthly
- Update dependencies (`composer update`)
- Review authorization policies
- Audit new code changes
- Performance analysis

### Quarterly
- Security audit
- Penetration testing
- Dependency vulnerability scan
- Compliance review

---

## 15. Tools & Resources

### Testing Tools
- **Postman** - API testing
- **SoapUI** - Security testing
- **OWASP ZAP** - Vulnerability scanning
- **Burp Suite** - Penetration testing

### Monitoring Tools
- **Laravel Telescope** - Debug tool
- **Sentry** - Error tracking
- **Datadog** - Performance monitoring
- **ELK Stack** - Log aggregation

### Security Tools
- **composer audit** - Dependency vulnerabilities
- **PHPStan** - Static analysis
- **PHPMD** - Code quality

---

## Contact & Support

For security concerns or vulnerability reports, contact the development team.

**Do not** publicly disclose security vulnerabilities.

---

**Last Updated**: January 2026
**Security Level**: Production-Ready
**Compliance**: OWASP Top 10
