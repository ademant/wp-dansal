---
labels: security, webhook, rate-limiting
severity: medium
priority: high
---

# Add Rate Limiting to Webhook Endpoint

## Description

The webhook endpoint (`/wp-json/wpd/v1/webhook`) currently has no rate limiting protection. This makes it vulnerable to denial-of-service attacks where an attacker could flood the endpoint with requests.

## Impact

- **Denial of Service**: An attacker could send many requests to exhaust server resources
- **Transient Spam**: Excessive requests could fill up the transients table
- **Log Pollution**: Error logs could be flooded with failed requests

## Current State

The webhook endpoint accepts POST requests without any rate limiting. It does have signature verification, but this happens after the request is processed, which still consumes resources.

## Proposed Solution

Implement per-IP rate limiting for the webhook endpoint:

```php
// In WPD_Webhook::receive()
private function check_rate_limit() {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $key = 'wpd_webhook_ratelimit_' . md5($ip);
    
    $count = (int) get_transient($key);
    $max_requests = (int) apply_filters('wpd_webhook_max_requests_per_minute', 60);
    
    if ($count >= $max_requests) {
        return new WP_Error(
            'wpd_webhook_ratelimit',
            __('Too many webhook requests. Please try again later.', 'wp-dansal'),
            array('status' => 429)
        );
    }
    
    set_transient($key, $count + 1, MINUTE_IN_SECONDS);
    return true;
}
```

Then call it at the start of `receive()`:

```php
public function receive(WP_REST_Request $request) {
    $rate_check = $this->check_rate_limit();
    if (is_wp_error($rate_check)) {
        return $rate_check;
    }
    // ... rest of method
}
```

## Implementation Details

### Location
- File: `includes/class-wpd-webhook.php`
- Method: Add to `receive()` method

### Configuration
- Make the rate limit configurable via filter: `wpd_webhook_max_requests_per_minute`
- Default: 60 requests per minute per IP
- Allow site administrators to adjust based on their needs

### Considerations
- Use IP address for rate limiting (could be extended to use API key if authenticated)
- Return HTTP 429 (Too Many Requests) status code
- Consider adding a `Retry-After` header
- Log rate-limited requests for security monitoring

## Testing

1. Send 60 requests to the webhook endpoint from the same IP within a minute
2. Verify the 61st request returns 429 status code
3. Wait 1 minute and verify requests work again
4. Verify legitimate webhook deliveries still work

## Edge Cases

- Requests from the same IP but different signatures (different publishers)
- Requests from behind a proxy (X-Forwarded-For header)
- Burst requests vs. sustained rate

---

## Acceptance Criteria

- [ ] Webhook endpoint has rate limiting implemented
- [ ] Rate limit is configurable via filter
- [ ] Rate-limited requests return HTTP 429
- [ ] Rate limiting is per-IP
- [ ] Legitimate webhook deliveries are not affected
- [ ] Rate limit defaults to 60 requests/minute
