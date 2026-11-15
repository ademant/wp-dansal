---
labels: security, input-validation, xss
severity: medium
priority: high
---

# Strengthen tile_url_template Input Validation

## Description

The `tile_url_template` setting accepts arbitrary URL templates for map tiles, including Leaflet placeholders like `{z}`, `{x}`, `{y}`. The current sanitization uses `sanitize_text_field()` which doesn't properly validate URLs and could potentially allow malicious input.

## Impact

- **Potential XSS**: If the template value is output without proper escaping, it could allow script injection
- **Malformed URLs**: Invalid templates could cause client-side errors or break functionality

## Current State

In `WPD_Settings::sanitize()`:
```php
$raw_tile = isset($input['tile_url_template']) ? trim(wp_unslash($input['tile_url_template'])) : ...;
if ('' === $raw_tile || preg_match($scheme_re, $raw_tile)) {
    $out['tile_url_template'] = sanitize_text_field($raw_tile);
}
```

The issue is that `sanitize_text_field()` doesn't validate URL structure and doesn't preserve the `{` and `}` characters that are needed for Leaflet templates.

## Proposed Solution

Create a custom sanitizer that:
1. Allows Leaflet placeholders (`{z}`, `{x}`, `{y}`)
2. Validates the rest of the URL structure
3. Properly escapes or removes dangerous characters

```php
/**
 * Sanitize a tile URL template, allowing Leaflet placeholders.
 *
 * @param string $value The URL template to sanitize
 * @return string Sanitized URL template
 */
private function sanitize_tile_url_template($value) {
    if (!is_string($value)) {
        return '';
    }
    
    $value = trim($value);
    
    // Replace placeholders with temporary markers
    $placeholders = array('{z}', '{x}', '{y}');
    $temp_markers = array();
    foreach ($placeholders as $ph) {
        $marker = '{PLACEHOLDER_' . substr($ph, 1, -1) . '}';
        $temp_markers[$ph] = $marker;
        $value = str_replace($ph, $marker, $value);
    }
    
    // Validate and sanitize the URL
    $sanitized = esc_url_raw($value);
    
    // If URL validation failed (returned empty), try basic sanitization
    if (empty($sanitized) && !empty($value)) {
        $sanitized = sanitize_text_field($value);
    }
    
    // Restore placeholders
    foreach ($temp_markers as $ph => $marker) {
        $sanitized = str_replace($marker, $ph, $sanitized);
    }
    
    return $sanitized;
}
```

Then update the sanitize method:
```php
$raw_tile = isset($input['tile_url_template']) ? trim(wp_unslash($input['tile_url_template'])) : ...;
if ('' === $raw_tile || preg_match($scheme_re, $raw_tile)) {
    $out['tile_url_template'] = $this->sanitize_tile_url_template($raw_tile);
} else {
    $out['tile_url_template'] = $existing['tile_url_template'];
}
```

## Implementation Details

### Location
- File: `includes/class-wpd-settings.php`
- Method: `sanitize()`

### Validation Rules
1. Must start with `http://` or `https://` (respecting the `wpd_allow_insecure_connect_url` filter)
2. Can contain `{z}`, `{x}`, `{y}` placeholders
3. No script tags or JavaScript URIs
4. No dangerous protocols (javascript:, data:, etc.)

### Additional Protection
When outputting the template value, ensure it's properly escaped:
```php
// In frontend code
esc_url($this->settings->get_tile_url_template())
```

## Testing

1. Test with valid URLs:
   - `https://tile.openstreetmap.org/{z}/{x}/{y}.png`
   - `https://example.com/tiles/{z}/{x}/{y}.png?key=123`
   
2. Test with invalid URLs:
   - `javascript:alert(1)`
   - `data:text/html,<script>alert(1)</script>`
   - `<script>alert(1)</script>`
   
3. Test with malformed URLs:
   - Missing protocol
   - Invalid characters
   
4. Verify placeholders are preserved

## Edge Cases

- URLs with query parameters containing `{` or `}`
- URLs with fragments
- Very long URLs
- URLs with special characters

---

## Acceptance Criteria

- [ ] Custom sanitizer for tile_url_template is implemented
- [ ] Leaflet placeholders ({z}, {x}, {y}) are preserved
- [ ] Malicious URLs are rejected or sanitized
- [ ] Valid URLs with placeholders pass validation
- [ ] Invalid URLs are rejected
- [ ] No XSS vulnerabilities introduced
- [ ] Existing functionality is not broken
- [ ] Unit tests for various URL patterns
