---
labels: security, csrf, ajax
severity: medium
priority: high
---

# Add CSRF Protection to Public AJAX Endpoints

## Description

Several AJAX endpoints are available to non-logged-in users (`nopriv` actions):
- `wp_ajax_nopriv_wpd_mini_calendar`
- `wp_ajax_nopriv_wpd_nearby`
- `wp_ajax_nopriv_wpd_tile`

These endpoints currently don't have nonce verification, which could make them vulnerable to Cross-Site Request Forgery (CSRF) attacks if:
1. A user is logged in and visits a malicious site
2. The malicious site makes requests to these endpoints
3. The requests are processed with the logged-in user's credentials

## Impact

- **CSRF Vulnerability**: Logged-in users could be tricked into making unauthorized requests
- **Data Exposure**: Could potentially expose data if the endpoints return sensitive information
- **Action Execution**: Could potentially execute actions on behalf of logged-in users

## Current State

The endpoints use the `nopriv` suffix, which means they're accessible without authentication. However, they don't verify nonces.

## Proposed Solution

### Option 1: Add Nonce Verification (Recommended)

Add nonce verification to public AJAX endpoints. The nonce can be:
- Embedded in the page when it's loaded
- Sent with the AJAX request

```php
public function ajax_mini_calendar() {
    // Check nonce if user is logged in
    if (is_user_logged_in()) {
        check_ajax_referer('wpd_mini_calendar', 'nonce');
    }
    
    // ... rest of the method
}
```

And in the frontend JavaScript:
```javascript
// When making the AJAX call
var data = {
    action: 'wpd_mini_calendar',
    nonce: wpd_vars.nonce,  // Pass the nonce
    // ... other parameters
};
```

### Option 2: Check Referer

For public endpoints that don't need authentication, verify the referer:

```php
public function ajax_mini_calendar() {
    // Allow if called from same site
    $referer = wp_get_referer();
    $home_url = home_url();
    
    if ($referer && strpos($referer, $home_url) !== 0) {
        // Not from our site - reject
        wp_send_json_error('Invalid request origin', 403);
    }
    
    // ... rest of the method
}
```

### Option 3: Use REST API Instead (Best Long-Term Solution)

The REST API has built-in nonce handling via `X-WP-Nonce` header. Migrating these endpoints to REST API would provide better security and consistency.

## Implementation Details

### Location
- File: `includes/class-wpd-frontend.php`
- Methods: `ajax_mini_calendar()`, `ajax_nearby()`, `ajax_tile()`

### Frontend Changes
- Generate nonces for public AJAX endpoints
- Pass nonces with AJAX requests
- For public (non-authenticated) usage, consider:
  - Short-lived tokens
  - Rate limiting
  - Referer checking

### Considerations
- Public endpoints need to work for non-logged-in users
- Nonces should be generated per-session or per-page
- Need to handle both authenticated and non-authenticated cases

## Testing

1. Test AJAX endpoints with valid nonce
2. Test AJAX endpoints with invalid nonce
3. Test AJAX endpoints with missing nonce
4. Test from different origins (referer checking)
5. Verify non-logged-in users can still use the endpoints
6. Verify logged-in users are protected

## Security Considerations

- Don't break legitimate public usage
- Consider rate limiting for public endpoints
- Log failed nonce checks
- Return appropriate HTTP status codes (403 for CSRF)

---

## Acceptance Criteria

- [ ] Public AJAX endpoints have CSRF protection
- [ ] Protection works for both logged-in and non-logged-in users
- [ ] Legitimate usage is not broken
- [ ] Invalid requests are rejected with appropriate errors
- [ ] Unit tests for CSRF protection
- [ ] Documentation updated to include nonce usage
