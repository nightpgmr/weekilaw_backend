# Payment Flow - Corrected Implementation

## Overview

The payment gateway redirects **directly to the React frontend** payment listener page, not to Laravel backend.

## Payment Flow

### Step 1: Initiate Payment
**Frontend → Backend**
```
POST /api/payment/initiate
{
  "purchase_type": "coinpackages",
  "id": "coin_package_10",
  "gateway_id": "saman_bank",
  "platform": "website"  // Optional: 'website', 'app', 'webapp'
}
```

**Backend Response:**
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

### Step 2: User Pays on Gateway
- Frontend submits form to gateway link (or redirects to gateway URL)
- User completes payment on Saman gateway
- **Gateway redirects directly to React frontend** with GET query parameters

### Step 3: Gateway Redirects to React Listener
**Gateway → React Frontend**
```
GET https://www.weekilaw.com/payment-listener?State=OK&RefNum=...&ResNum=...&TraceNo=...&MID=...
```

**React Page (`/payment-listener`):**
- Extracts all data from URL query parameters
- Does NOT process or break the data
- Forwards everything to Laravel verify endpoint

### Step 4: React Listener → Laravel Verify
**React → Backend**
```
POST /api/payment/verify
{
  "link": "https://www.weekilaw.com/payment-listener?State=OK&RefNum=...&ResNum=...",
  "body": {
    "ResNum": "...",
    "RefNum": "...",
    "State": "OK",
    "Status": "...",
    "Token": "...",
    "TraceNo": "...",
    "MID": "..."
  }
}
```

**Backend Response:**
```json
{
  "success": true,
  "message": "پرداخت شما (10000 تومان) موفق بود",
  "link": "https://www.weekilaw.com/account"
}
```

### Step 5: React Listener Shows Result
- React page displays success/error message
- Redirects user to `/account` page after 2-3 seconds

## Key Points

1. **Gateway redirects directly to React frontend** (`/payment-listener`)
2. **No backend listener route needed** - gateway goes straight to frontend
3. **React listener extracts data from URL query params** (gateway redirects with GET)
4. **React listener forwards data to Laravel verify** without processing
5. **Laravel verify processes payment** and updates MongoDB
6. **React listener shows result** and redirects user

## Configuration

### Listener URL in Payment Initiation
The listener URL sent to gateway should be the React frontend URL:
```php
$frontendUrl = rtrim(config('app.frontend_url', 'http://localhost:3000'), '/');
$listenerUrl = $frontendUrl . '/payment-listener';
```

### Gateway Registration
In Saman gateway panel, register the callback URL as:
```
https://www.weekilaw.com/payment-listener
```
(React frontend URL, not backend URL)

## MongoDB Payment Records

All payment records are stored in `test.payments` collection with:
- Platform tracking (`website`, `app`, `webapp`)
- Payment type (`coin_package`, `subscription`, `consultation`)
- Status (`pending`, `completed`, `failed`, `refunded`)
- Gateway response data
- User coins updated automatically for `coin_package` payments
