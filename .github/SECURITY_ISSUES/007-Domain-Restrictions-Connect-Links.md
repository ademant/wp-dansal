---
labels: security, input-validation, enhancement
severity: low
priority: medium
---

# Add Domain Restrictions for Connect Links

## Description

The connect link redemption currently validates the URL structure but doesn't restrict which domains can be used. This means a user could potentially connect to a malicious server that mimics the dansal API.

## Impact

- **Phishing Risk**: Users could be tricked into connecting to a malicious server
- **No Domain Whitelisting**: Cannot restrict to known, trusted dansal instances
- **Social Engineering**: Attackers could create fake dansal instances

## Current State

In `WPD_Settings::redeem_connect_link()`:
```php
if ('' === $connect_url || !preg_match('#^' . $scheme_re . '://\S+/api/v1/invites/[^/\s]+/publisher/?$#', $connect_url)) {
    return new WP_Error(...);
}
```

This validates the URL pattern but not the domain.

## Proposed Solution

Add an optional filter to allow administrators to restrict which domains can be used for connect links:

```php
// In WPD_Settings::redeem_connect_link()
// After URL pattern validation
$allowed_domains = apply_filters('wpd_allowed_dansal_domains', array());
if (!empty($allowed_domains)) {
    $host = wp_parse_url($connect_url, PHP_URL_HOST);
    if (!in_array($host, $allowed_domains, true)) {
        return new WP_Error(
            'wpd_connect_domain_not_allowed',
            sprintf(
                __('Connect link domain "%s" is not in the allowed domains list.', 'wp-dansal'),
                esc_html($host)
            ),
            array('status' => 403)
        );
    }
}
```

## Implementation Details

### Location
- File: `includes/class-wpd-settings.php`
- Method: `redeem_connect_link()`

### Usage Example

Site administrators can add this to their theme's `functions.php` or a custom plugin:

```php
add_filter('wpd_allowed_dansal_domains', function() {
    return array(
        'api.dansal.example.com',
        'api.dansal.org',
    );
});
```

### Wildcard Support

Consider supporting wildcards for subdomains:

```php
$allowed_domains = apply_filters('wpd_allowed_dansal_domains', array());
if (!empty($allowed_domains)) {
    $host = wp_parse_url($connect_url, PHP_URL_HOST);
    $allowed = false;
    
    foreach ($allowed_domains as $domain) {
        if ($domain === $host) {
            $allowed = true;
            break;
        }
        // Support wildcards: *.example.com matches foo.example.com
        if (strpos($domain, '*.') === 0) {
            $base_domain = substr($domain, 2);
            if (substr($host, -strlen($base_domain)) === $base_domain) {
                $allowed = true;
                break;
            }
        }
    }
    
    if (!$allowed) {
        return new WP_Error(...);
    }
}
```

## Testing

1. Test with no domain restrictions (empty filter)
2. Test with domain whitelist
3. Test with wildcard domains
4. Test with domain not in whitelist
5. Test with invalid domain format

## Edge Cases

- IP addresses as domains
- Internationalized domain names (IDN)
- Very long domain names
- Domains with ports

## Security Considerations

- Default should be no restrictions (backward compatible)
- Wildcard support should be optional
- Consider logging blocked domains for security monitoring
- Consider adding a UI for managing allowed domains (future enhancement)

---

## Acceptance Criteria

- [ ] Filter `wpd_allowed_dansal_domains` is implemented
- [ ] Domain validation is performed before processing connect links
- [ ] Wildcard domain support is available
- [ ] Default behavior is unchanged (no restrictions)
- [ ] Error messages are clear and helpful
- [ ] Unit tests for domain validation
- [ ] Documentation updated with usage examples
