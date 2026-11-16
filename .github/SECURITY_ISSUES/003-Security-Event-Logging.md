---
labels: security, logging, monitoring
severity: medium
priority: high
---

# Implement Security Event Logging

## Description

The plugin currently doesn't log security-relevant events. Without logging, it's difficult to:
- Audit security incidents
- Detect brute force attempts
- Monitor for suspicious activity
- Comply with security standards (SOC 2, PCI DSS, etc.)

## Impact

- **No Audit Trail**: Cannot track who did what and when
- **Incident Response**: Limited ability to investigate security incidents
- **Compliance**: May not meet requirements for security logging in regulated environments

## Current State

Security events are not logged. Examples of events that should be logged:
- Failed webhook signature verification
- Failed authentication attempts
- Rate limit triggers
- API key rotation/revocation
- Connect link redemption

## Proposed Solution

Create a `WPD_Security_Log` class to centralize security logging:

```php
final class WPD_Security_Log {
    
    const OPTION_NAME = 'wpd_security_log';
    const MAX_ENTRIES = 1000;
    
    public static function log(string $event, array $details = [], string $severity = 'info'): void {
        $log_entry = array(
            'timestamp' => current_time('mysql'),
            'event' => $event,
            'severity' => $severity,
            'details' => $details,
            'ip' => self::get_client_ip(),
            'user_id' => self::get_user_id(),
            'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? '',
        );
        
        $logs = get_option(self::OPTION_NAME, array());
        $logs[] = $log_entry;
        
        // Keep only the most recent entries
        if (count($logs) > self::MAX_ENTRIES) {
            $logs = array_slice($logs, -self::MAX_ENTRIES);
        }
        
        update_option(self::OPTION_NAME, $logs);
        
        // Optionally log to PHP error log
        if (defined('WPD_SECURITY_SYSLOG') && WPD_SECURITY_SYSLOG) {
            error_log(sprintf('[WP Dansal Security] %s: %s', $event, wp_json_encode($details)));
        }
        
        // Trigger action for external logging integration
        do_action('wpd_security_log', $event, $details, $severity);
    }
    
    private static function get_client_ip(): string {
        // Handle proxies if configured
        $ip = $_SERVER['REMOTE_ADDR'] ?? '';
        if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
            $ip = $_SERVER['HTTP_X_FORWARDED_FOR'];
        }
        return $ip;
    }
    
    private static function get_user_id(): int {
        return is_user_logged_in() ? get_current_user_id() : 0;
    }
    
    public static function get_logs(int $limit = 100): array {
        $logs = get_option(self::OPTION_NAME, array());
        return array_slice($logs, -$limit);
    }
    
    public static function clear(): void {
        delete_option(self::OPTION_NAME);
    }
}
```

## Usage Examples

### In webhook verification (WPD_Webhook::verify_signature)
```php
if (!hash_equals($expected, (string) $signature)) {
    WPD_Security_Log::log('webhook_bad_signature', array(
        'timestamp' => $ts,
        'nonce' => $nonce,
        'expected_signature' => substr($expected, 0, 8) . '...', // Don't log full signature
        'provided_signature' => substr((string) $signature, 0, 8) . '...',
    ), 'warning');
    return new WP_Error('wpd_webhook_bad_signature', ...);
}
```

### In authentication failure (WPD_Api_Client::get_session_token)
```php
if ($code < 200 || $code >= 300 || empty($body['token'])) {
    WPD_Security_Log::log('token_exchange_failed', array(
        'http_status' => $code,
        'error' => $message,
    ), 'error');
    return new WP_Error(...);
}
```

## Implementation Details

### Location
- New file: `includes/class-wpd-security-log.php`
- Initialize in `WPD_Plugin::__construct()`

### Logged Events
- Webhook signature failures
- Authentication failures
- Rate limit triggers
- API key renewal (success/failure)
- Connect link redemption (success/failure)
- Disconnect events
- Failed decryption attempts

### Severity Levels
- `info`: Normal operations (connect, disconnect)
- `warning`: Suspicious activity (failed signature, rate limit)
- `error`: Critical failures (auth failure)

### Storage
- Store in WordPress option (limited to 1000 entries)
- Optionally log to PHP error log
- Optionally integrate with external logging services

## Testing

1. Trigger various security events
2. Verify they appear in the security log
3. Verify log entries contain all expected fields
4. Verify log rotation works (max entries limit)
5. Test the filter for external logging integration

## Security Considerations

- Don't log sensitive data (full API keys, passwords, etc.)
- Truncate sensitive values (signatures, tokens)
- Consider adding a capability check for viewing logs
- Provide a way to clear logs

---

## Acceptance Criteria

- [ ] `WPD_Security_Log` class is implemented
- [ ] Security events are logged in key locations
- [ ] Logs are stored in WordPress database
- [ ] Log entries include: timestamp, event, severity, details, IP, user ID
- [ ] Maximum of 1000 log entries are retained
- [ ] Option to log to PHP error log via constant
- [ ] Action hook for external logging integration
- [ ] No sensitive data is logged in full
- [ ] Unit tests for security logging
