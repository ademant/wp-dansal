---
labels: security, session-management, enhancement
severity: medium
priority: medium
---

# Implement IP-Pinning for Session Tokens

## Description

The plugin's authentication flow uses long-lived publisher API keys that are exchanged for short-lived session tokens. The documentation mentions "IP-pinned session token", but the current implementation doesn't actually enforce IP restrictions on session token usage.

If a session token is leaked, it could be used from any IP address, potentially allowing unauthorized access.

## Impact

- **Token Theft**: If a session token is leaked (e.g., through logs, MITM, etc.), an attacker could use it from any location
- **No Geographic Restriction**: Tokens can be used from anywhere, not just the IP that obtained them

## Current State

Session tokens are cached in transients but not associated with any IP address. The comment in `class-wpd-api-client.php` states:

```php
// Auth model (see dansal API.md, "Building a third-party integration on a
// publisher account"): the plugin holds a long-lived publisher API key
// (ak_...) in settings, and exchanges it for a short-lived, IP-pinned
// session token via POST /api/v1/publishers/token.
```

However, the implementation doesn't verify the IP when using the token.

## Proposed Solution

Store the IP address with the session token and verify it on each use:

```php
// In WPD_Api_Client::get_session_token()
public function get_session_token($force = false) {
    if (!$force) {
        $cached = get_transient(self::TOKEN_TRANSIENT);
        if ($cached && is_array($cached)) {
            // Check if IP matches
            if ($cached['ip'] === $this->get_client_ip()) {
                return $cached['token'];
            }
            // IP changed - invalidate and get new token
            delete_transient(self::TOKEN_TRANSIENT);
        }
    }
    
    // ... get new token from dansal
    
    // Store with IP
    set_transient(self::TOKEN_TRANSIENT, array(
        'token' => $token,
        'ip' => $this->get_client_ip(),
        'expires' => time() + $ttl,
    ), $ttl);
    
    return $token;
}

private function get_client_ip(): string {
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        // Handle proxies - use first IP in chain
        $ip = trim(explode(',', $_SERVER['HTTP_X_FORWARDED_FOR'])[0] ?? '');
    }
    return $ip;
}
```

## Implementation Details

### Location
- File: `includes/class-wpd-api-client.php`
- Method: `get_session_token()`

### Configuration
- Make IP-pinning optional via filter: `wpd_session_token_ip_pinning`
- Default: `true` (enabled)
- Allow site administrators to disable if needed (e.g., for mobile users)

### Considerations

1. **Mobile Users**: Users on mobile networks may change IPs frequently
2. **Proxy Environments**: Need to handle X-Forwarded-For header properly
3. **Multiple IPs**: Some environments may have multiple IPs (IPv4 + IPv6)
4. **VPN/Proxy**: Users behind VPNs or proxies may have changing IPs

### Fallback Strategy

If IP changes:
1. Invalidate the current token
2. Get a new token from dansal
3. Update the cached IP
4. Optionally log the IP change for security monitoring

## Testing

1. Get a session token
2. Make requests from the same IP - should work
3. Change IP (simulate with proxy or VPN)
4. Make request from new IP - should:
   - Get a new token automatically
   - Work with the new token
5. Test with disabled IP-pinning (via filter)

## Security Considerations

- Log IP changes for security monitoring
- Consider rate limiting token requests to prevent brute force
- Don't expose full IP in logs (hash or truncate)
- Consider adding user agent check as additional factor

---

## Acceptance Criteria

- [ ] Session tokens are associated with IP address
- [ ] Token validation checks IP address
- [ ] IP change triggers automatic token renewal
- [ ] IP-pinning is configurable via filter
- [ ] IP-pinning is enabled by default
- [ ] Proxy headers (X-Forwarded-For) are handled
- [ ] Works with mobile users (token renewal on IP change)
- [ ] Unit tests for IP-pinning functionality
- [ ] Documentation updated
