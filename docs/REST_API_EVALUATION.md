# REST API Evaluation - wp-dansal Plugin

**Issue:** #116  
**Date:** 2026-09-05  
**Status:** Completed

## Executive Summary

**Recommendation: DO NOT add a server-side REST API to wp-dansal at this time.**

The current architecture, where wp-dansal syncs data to dansal and dansal serves as the source of truth with its own REST API, is **superior** to adding a separate REST API layer. Adding a REST API would create redundancy, synchronization challenges, and maintenance overhead without providing significant benefits.

## Current Architecture Analysis

### How wp-dansal Works Today

```
WordPress Site (wp-dansal)
    │
    ├─ User creates Event/Location in WP Admin
    │
    └─ Plugin syncs to dansal API (one-way: WP → dansal)
            │
            ▼
    dansal Server (Source of Truth)
        │
        ├─ REST API: GET /api/v1/events
        ├─ REST API: GET /api/v1/locations  
        ├─ REST API: GET /api/v1/organizations
        └─ Web interface: dansal-web
            │
            ├─ Public event/location pages
            └─ Admin interface
```

### Data Flow

1. **Create/Update:** WordPress → dansal (via Plugin)
2. **Read (Public):** Browser → dansal API or dansal-web
3. **Read (Admin):** WordPress reads from its own DB (synced copy)
4. **Delete:** WordPress → dansal (via Plugin)

## Proposed REST API: What Would It Do?

A wp-dansal REST API would expose endpoints like:
- `GET /wp-json/wp-dansal/v1/events`
- `GET /wp-json/wp-dansal/v1/locations`
- `GET /wp-json/wp-dansal/v1/events/{id}`
- `POST /wp-json/wp-dansal/v1/events`

These would essentially **proxy** requests to the dansal API or return data from the WordPress database.

## Evaluation Criteria

| Criterion | Current Approach | With REST API | Verdict |
|-----------|-----------------|---------------|---------|
| **Data Consistency** | Single source (dansal) | Two sources (WP + dansal) | ✅ Current better |
| **Maintenance** | Plugin only | Plugin + API layer | ✅ Current better |
| **Performance** | Direct dansal access | Extra hop | ✅ Current better |
| **Real-time Data** | dansal is authoritative | WP cache may be stale | ✅ Current better |
| **Offline Access** | Not applicable | Not applicable | ⚠️ Neither supports |
| **External Integrations** | Use dansal API directly | Use WP API (extra layer) | ✅ Current better |
| **Complexity** | Simple sync | Complex sync + API | ✅ Current better |

## Use Cases Analysis

### Use Case 1: Mobile App for Editing

**Requirement:** Edit events/locations from mobile device.

**Current Solution:** 
- Use WordPress admin on mobile browser
- Or use dansal-web directly

**With REST API:**
- Mobile app calls WP REST API
- WP REST API calls dansal API
- **Problem:** Extra layer, potential sync issues

**Better Solution:**
- Mobile app calls **dansal API directly**
- Or use **dansal-web** (responsive design)
- **Verdict:** REST API not needed

### Use Case 2: Third-Party Website Display

**Requirement:** Display events on another website.

**Current Solution:**
- Use dansal API directly: `GET /api/v1/events?org=12345`
- Embed using iframe or JavaScript

**With REST API:**
- Third party calls WP REST API
- WP REST API calls dansal API
- **Problem:** Extra hop, rate limiting, dependency

**Verdict:** REST API adds no value

### Use Case 3: Headless WordPress

**Requirement:** Use WordPress as a headless CMS with wp-dansal.

**Current Solution:**
- Frontend calls dansal API directly
- Use dansal-web for display

**With REST API:**
- Frontend calls WP REST API
- **Problem:** WordPress would be a proxy, not adding value

**Verdict:** REST API not beneficial

### Use Case 4: Custom Frontend Applications

**Requirement:** Build custom frontend with React/Vue/other.

**Current Solution:**
- Call dansal API directly
- dansal provides all needed data

**With REST API:**
- Call WP REST API
- **Problem:** Extra layer, no transformation needed

**Verdict:** REST API adds complexity without benefit

## What About Caching?

**Argument:** A REST API could cache dansal responses to reduce load on dansal.

**Counter-Argument:**
1. dansal already caches responses
2. WordPress transients can cache dansal API responses internally
3. Adding a REST API doesn't inherently provide caching benefits
4. Caching can be implemented at the Plugin level without exposing an API

## What About Data Transformation?

**Argument:** A REST API could transform dansal data to match WordPress conventions.

**Counter-Argument:**
1. The Plugin already transforms data when syncing
2. WordPress admin displays data from its own DB (already transformed)
3. External consumers should use dansal's standard format
4. Transformation adds overhead and maintenance

## Alternative: GraphQL or Custom Endpoints

Instead of a full REST API, consider:

### 1. Custom WordPress Endpoints (for internal use)
```php
add_action('rest_api_init', function() {
    register_rest_route('wp-dansal/v1', '/sync-now', [
        'methods' => 'POST',
        'callback' => 'wpd_force_sync',
        'permission_callback' => '__return_true',
    ]);
});
```
**Use Case:** Trigger sync from external systems
**Verdict:** Only if needed, minimal overhead

### 2. Shortcode Enhancements
Instead of REST API, enhance shortcode parameters:
- `[dansal_events api="1"]` - Use dansal API directly
- `[dansal_events proxy="1"]` - Proxy through WP for caching

**Verdict:** Better approach than REST API

## Security Considerations

A REST API would introduce:
1. **New attack surface** - More endpoints to secure
2. **Authentication complexity** - API keys, OAuth, rate limiting
3. **Data exposure risk** - Potential to expose sensitive data
4. **DDoS vulnerability** - External endpoints could be targeted

The current architecture minimizes these risks by keeping data flow simple and controlled.

## Performance Considerations

Current architecture:
```
Browser → dansal API (direct)
```

With REST API:
```
Browser → WP REST API → dansal API (extra hop)
```

The extra hop adds latency and resource usage without benefit.

## Maintenance Considerations

A REST API would require:
1. Documentation
2. Versioning strategy
3. Backward compatibility
4. Testing
5. Security updates
6. Performance optimization
7. Rate limiting
8. Error handling

All for functionality that can be achieved by using dansal API directly.

## Recommendation

### ❌ DO NOT Implement

1. **Full REST API** - Adds complexity without clear benefits
2. **Public-facing endpoints** - dansal API serves this purpose better
3. **Data transformation layer** - Unnecessary, dansal format is standard

### ✅ DO Implement (if needed)

1. **Custom admin endpoints** - For triggering sync from external systems
2. **Shortcode enhancements** - For flexible display options
3. **Caching improvements** - Internal caching of dansal responses

### 📋 Action Items

- [ ] Document how to use dansal API directly for external integrations
- [ ] Add filter to allow custom API URL for special use cases
- [ ] Consider adding a "sync now" endpoint for webhooks
- [ ] Monitor dansal API usage and performance

## Conclusion

The wp-dansal Plugin's current architecture is **well-designed**. It uses dansal as the source of truth and leverages dansal's existing REST API. Adding a wp-dansal REST API would create a **proxy pattern** without adding value.

**Final Recommendation:** Maintain current architecture. Do not add a server-side REST API.

---
*Generated by Mistral Vibe evaluation*