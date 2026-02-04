# Payment MongoDB Update - Platform Tracking

## Overview

Updated the payment system to:
1. Store payment records in MongoDB cloud collection `test.payments`
2. Match the Node.js payment model structure exactly
3. Add platform tracking field with enum values

## Changes Made

### 1. Payment Model Structure

The payment records now match the Node.js model structure:

```php
[
    'user_id' => ObjectId,           // MongoDB ObjectId
    'transaction_id' => string,      // Unique transaction ID
    'gateway_id' => string,          // Payment gateway ID
    'amount' => int,                 // Amount in Rials
    'fee' => int,                    // Gateway fee (default: 0)
    'total_amount' => int,           // Total amount (amount + fee)
    'redirect_url' => string,        // Callback URL
    'plan_id' => string,             // Purchase ID (coin package ID, subscription ID, etc.)
    'payment_type' => string,        // Enum: 'coin_package', 'subscription', 'consultation'
    'description' => string,         // Payment description
    'status' => string,              // Enum: 'pending', 'completed', 'failed', 'refunded'
    'platform' => string,            // NEW: Enum: 'website', 'app', 'webapp'
    'paid_at' => UTCDateTime,       // Payment completion time
    'gateway_response' => array,    // Gateway response data
    'coins' => int,                 // For coin_package payments only
    'createdAt' => UTCDateTime,
    'updatedAt' => UTCDateTime,
]
```

### 2. Platform Field

**Enum Values:**
- `website` - Payment initiated from website (default)
- `app` - Payment initiated from mobile app
- `webapp` - Payment initiated from web app (PWA)

**Default Behavior:**
- If platform is not provided or invalid, defaults to `website`
- Platform is validated against enum values before storing

### 3. Payment Service Updates

**`PaymentService::initiatePayment()`:**
- Accepts `platform` parameter (defaults to 'website')
- Validates platform enum
- Stores payment record in MongoDB `test.payments` collection
- Maps `purchase_type` to `payment_type` enum:
  - `coinpackages` → `coin_package`
  - `subscriptions` → `subscription`
  - `consultations` → `consultation`

**`PaymentService::verifyPayment()`:**
- Finds payment by `gateway_response.reserve_number`
- Updates payment status to 'completed'
- Sets `paid_at` timestamp
- Merges gateway response data
- Updates user coins for `coin_package` payments

### 4. Payment Controller Updates

**`PaymentController::initiate()`:**
- Extracts `platform` from request (defaults to 'website')
- Validates platform enum
- Passes platform to PaymentService

## API Usage

### Initiate Payment

```http
POST /api/payment/initiate
Authorization: Bearer {token}
Content-Type: application/json

{
  "purchase_type": "coinpackages",
  "id": "coin_package_10",
  "gateway_id": "saman_bank",
  "platform": "website"  // Optional: 'website', 'app', 'webapp'
}
```

**Response:**
```json
{
  "success": true,
  "link": "https://sep.shaparak.ir/OnlinePG/SendToken?token=...",
  "body": {
    "Token": "...",
    "GetMethod": ""
  },
  "transaction_id": "TXN-1234567890-abc123",
  "reserve_number": "reserve1234567890"
}
```

## MongoDB Collection

**Collection:** `test.payments`

**Indexes (recommended):**
- `transaction_id` (unique)
- `user_id` + `status`
- `gateway_response.reserve_number`
- `payment_type` + `status`
- `platform`
- `createdAt` (descending)

## Platform Detection

### Website (Default)
- When `platform` is not provided
- When `platform` is invalid
- Explicitly set to `'website'`

### Mobile App
- Set `platform: 'app'` in request

### Web App (PWA)
- Set `platform: 'webapp'` in request

## Notes

1. **Backward Compatibility:** The system still searches for `reserve_number` directly if not found in `gateway_response.reserve_number`

2. **Currency Handling:** Amounts are stored in Rials. If purchase item currency is Toman (IRT), it's converted to Rials (×10) before storing.

3. **ObjectId Conversion:** User IDs are automatically converted to MongoDB ObjectId format when storing.

4. **Payment Type Mapping:** The `purchase_type` from the request is mapped to the `payment_type` enum used in the database.

5. **Coins Field:** Only added for `coin_package` payment types for easy reference.
