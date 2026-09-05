# Privacy Policy - wp-dansal Plugin

**Issue:** #115  
**Date:** 2026-09-05  
**Status:** Completed

## Introduction

This Privacy Policy explains how the **wp-dansal** WordPress plugin ("the Plugin") collects, uses, stores, and shares data when used on a WordPress website. By installing and using this Plugin, you agree to the terms outlined in this document.

## Data Controller

The **Site Owner** (the entity operating the WordPress website where this Plugin is installed) is the data controller for any personal data processed through this Plugin. The Plugin itself does not act as a data controller but facilitates data processing on behalf of the Site Owner.

## Information We Collect

### 1. Connection Data

To connect to the dansal API, the Plugin stores the following information in your WordPress database:

- **dansal API Base URL** - The URL of your dansal instance
- **Organization ID** - Your organization identifier in dansal
- **API Key** - Authentication token for API access
- **API Key Expiration** - When the key expires (if applicable)

**Purpose:** To authenticate and authorize API requests to dansal.

**Legal Basis:** Contractual necessity (to provide the requested service).

### 2. Event Data

When you create or update dance events through the Plugin, the following data is synced to dansal:

- Event title
- Event description/content
- Start and end dates/times
- Location information (linked to location posts)
- Organizer information
- Event tags and categories
- Event images/attachments
- Custom fields (e.g., price, instructor, level)

**Purpose:** To publish and manage dance events on dansal.

**Legal Basis:** Consent (you choose to create events) and contractual necessity.

### 3. Location Data

When you create or update dance locations through the Plugin, the following data is synced to dansal:

- Location title/name
- Address (street, city, postal code, country)
- Geographic coordinates (latitude, longitude)
- Description
- Phone number
- Website URL
- Email address
- Location tags and categories
- Images/attachments

**Purpose:** To publish and manage dance venues on dansal.

**Legal Basis:** Consent (you choose to create locations) and contractual necessity.

### 4. OpenStreetMap Geocoding Data

When you use the geocoding feature to find coordinates for an address:

- The address you enter is sent to **OpenStreetMap Nominatim** service
- The returned coordinates are stored with the location

**Purpose:** To automatically geocode addresses.

**Legal Basis:** Consent (you choose to use the geocoding feature).

### 5. Technical Data

The Plugin may collect and store the following technical information:

- Plugin version
- WordPress version
- PHP version
- Server information (for debugging purposes)
- API request/response logs (temporarily)

**Purpose:** To ensure compatibility and troubleshoot issues.

**Legal Basis:** Legitimate interest (improving the Plugin).

## How We Use Your Information

1. **To Provide Services** - The collected data is used to connect to dansal and sync events/locations.
2. **To Improve the Plugin** - Anonymous usage statistics may be collected to improve functionality.
3. **For Security** - API keys and connection data are used to authenticate requests securely.
4. **For Troubleshooting** - Technical data may be used to diagnose and fix issues.

## Data Storage

### Where Data is Stored

- **Connection Data** (Base URL, API Key, Org ID): Stored in WordPress options table (`wp_options`)
- **Event/Location Data**: Stored as WordPress posts in the posts table (`wp_posts`) and post meta (`wp_postmeta`)
- **Transient Data**: API responses may be temporarily cached using WordPress transients (`wp_options`)

### Data Retention

- **Connection Data**: Retained until explicitly deleted by the Site Owner
- **Event/Location Data**: Retained as long as the corresponding WordPress posts exist
- **API Keys**: Automatically refreshed before expiration; old keys are invalidated
- **Logs/Transients**: Typically retained for 24-48 hours, then automatically deleted

## Data Sharing

### 1. With dansal

The Plugin **sends data to dansal** for the following purposes:

- Syncing events and locations
- Authentication and authorization
- API key management

**Data Shared:** Event data, location data, organization information, API keys (for authentication).

**Purpose:** Core functionality of the Plugin.

### 2. With OpenStreetMap

The Plugin **sends addresses to OpenStreetMap Nominatim** for geocoding purposes.

**Data Shared:** Address information (street, city, country).

**Purpose:** To convert addresses to geographic coordinates.

### 3. With Third Parties

The Plugin **does not** share data with any other third parties, except as required by law.

## Data Security

### Security Measures

1. **HTTPS**: All API communications use HTTPS encryption
2. **Storage**: Data is stored in the WordPress database with standard WordPress security
3. **API Keys**: Keys are stored securely and automatically rotated
4. **Access Control**: Only users with appropriate WordPress capabilities can access settings
5. **No Hardcoded Secrets**: No credentials are stored in Plugin code

### Data Breach

In the event of a data breach, the Site Owner will be notified. As the Plugin developer, we will cooperate with the Site Owner to investigate and remediate any issues.

## User Rights

As a **Site Owner** or **End User**, you have the following rights regarding your data:

### 1. Right to Access

You have the right to request a copy of the data we hold about you.

### 2. Right to Rectification

You have the right to request correction of inaccurate or incomplete data.

### 3. Right to Erasure

You have the right to request deletion of your data, subject to technical constraints (e.g., WordPress post data).

### 4. Right to Restrict Processing

You have the right to request restriction of data processing in certain circumstances.

### 5. Right to Data Portability

You have the right to receive your data in a structured, commonly used format.

### 6. Right to Object

You have the right to object to data processing based on legitimate interests.

To exercise these rights, contact the **Site Owner** directly.

## International Data Transfers

If your dansal instance is hosted outside your jurisdiction, data may be transferred internationally. You are responsible for ensuring compliance with local data protection laws when configuring your dansal connection.

## Children's Privacy

The Plugin does not knowingly collect data from children under the age of 13. If you are a parent or guardian and believe we have collected information about your child, please contact the Site Owner.

## Changes to This Policy

We may update this Privacy Policy from time to time. We will notify Site Owners of any changes by posting the new Privacy Policy on our GitHub repository and updating the Plugin's documentation.

## Contact Information

For questions or concerns about this Privacy Policy or data practices:

- **Plugin Developer**: ademant (via GitHub: https://github.com/ademant/wp-dansal)
- **Site Owner**: The operator of the WordPress website using this Plugin

## Compliance

This Privacy Policy is designed to comply with:
- **GDPR** (General Data Protection Regulation) - For users in the European Economic Area
- **CCPA** (California Consumer Privacy Act) - For users in California, USA
- **Other applicable data protection laws**

---

**Document Version:** 1.0  
**Last Updated:** 2026-09-05  
**Effective Date:** 2026-09-05

*Generated by Mistral Vibe for wp-dansal Plugin*