---
labels: security, rest-api, authorization
severity: low
priority: medium
---

# Verify All REST Endpoints Have Proper Permission Checks

## Description

The plugin registers several REST endpoints. While most appear to have proper permission callbacks, a comprehensive audit should be performed to ensure all endpoints are properly protected.

## Impact

- **Unauthorized Access**: Endpoints without permission checks could be accessed by unauthorized users
- **Information Disclosure**: Sensitive data could be exposed
- **Action Execution**: Unauthorized actions could be performed

## Current State

Looking at the code, most REST endpoints have permission callbacks:

```php
// Example from WPD_Settings::register_rest_routes()
register_rest_route(
    'wpd/v1',
    '/connection/test',
    array(
        'methods' => 'POST',
        'callback' => array($this, 'rest_test_connection'),
        'permission_callback' => $manage_options,  // Good
    )
);
```

However, a comprehensive audit should be performed to ensure:
1. All REST endpoints have permission callbacks
2. Permission callbacks use appropriate capability checks
3. Public endpoints (intended for unauthenticated use) explicitly use `__return_true`

## Proposed Solution

### Step 1: Audit All REST Endpoints

Create a list of all REST endpoints and verify their permission callbacks:

1. **WPD_Settings** (`class-wpd-settings.php`):
   - `/connection/test` - `manage_options` ✅
   - `/connection/link` - `manage_options` ✅
   - `/connection` (DELETE) - `manage_options` ✅
   - `/connection/rotate-signing-secret` - `manage_options` ✅
   - `/webhook-subscription` (GET/POST/DELETE) - `manage_options` ✅
   - `/webhook-subscription/test` - `manage_options` ✅
   - `/nominatim/search` - `edit_posts` ✅
   - `/nominatim/reverse` - `edit_posts` ✅

2. **WPD_Webhook** (`class-wpd-webhook.php`):
   - `/webhook` (POST) - `__return_true` ⚠️ (This is intentional for webhooks)

3. **WPD_Nominatim** (`class-wpd-nominatim.php`):
   - `/nominatim/search` - Already covered above
   - `/nominatim/reverse` - Already covered above

4. **WPD_CPT_Event** (`class-wpd-cpt-event.php`):
   - Need to check REST endpoints

5. **WPD_CPT_Location** (`class-wpd-cpt-location.php`):
   - Need to check REST endpoints

6. **WPD_Frontend** (`class-wpd-frontend.php`):
   - Need to check REST endpoints

### Step 2: Document All Endpoints

Create a comprehensive list of all REST endpoints with:
- Endpoint path
- HTTP methods
- Permission callback
- Intended access level
- Purpose

### Step 3: Fix Any Issues

For any endpoints missing permission callbacks, add appropriate ones:

```php
// For admin-only endpoints
'permission_callback' => function() {
    return current_user_can('manage_options');
},

// For editor-level endpoints
'permission_callback' => function() {
    return current_user_can('edit_posts');
},

// For public endpoints
'permission_callback' => '__return_true',
```

## Implementation Details

### Location
- Various files in `includes/` directory
- Methods that register REST routes (`register_rest_routes()`)

### Automated Check

Consider adding a static analysis check or unit test that:
1. Finds all `register_rest_route()` calls
2. Verifies each has a `permission_callback`
3. Reports any missing callbacks

## Testing

1. List all REST endpoints
2. Verify each has a permission callback
3. Test each endpoint with:
   - Unauthenticated user
   - Subscriber role
   - Editor role
   - Administrator role
4. Verify only appropriate roles can access each endpoint

## Security Considerations

- Public endpoints should be clearly documented
- Consider rate limiting for public endpoints
- Consider adding IP restrictions for sensitive endpoints
- Log unauthorized access attempts

---

## Acceptance Criteria

- [ ] All REST endpoints have permission callbacks
- [ ] Permission callbacks use appropriate capability checks
- [ ] Public endpoints are clearly documented
- [ ] No unauthorized access is possible
- [ ] Comprehensive list of endpoints and their permissions is documented
- [ ] Unit tests for permission checks
- [ ] Automated check for missing permission callbacks (optional)
