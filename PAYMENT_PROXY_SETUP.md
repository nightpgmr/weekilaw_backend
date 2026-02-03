# SEP Payment Proxy Setup Guide

This guide explains how to set up a payment proxy on a whitelisted domain to handle SEP payment callbacks.

## Problem

SEP payment gateway requires your callback URL and IP address to be whitelisted. If your main backend domain (`panel.weekilaw.com`) is not whitelisted, you can use a proxy on a whitelisted domain.

## Solution

Use a proxy endpoint on a whitelisted domain (e.g., `payment.weekilaw.com`) that forwards callbacks to your actual backend.

## Setup Steps

### 1. Upload Proxy Script

Upload the `payment-proxy.php` file to your whitelisted domain:

**Location:** `https://payment.weekilaw.com/api/payment/payment-listener`

Make sure the file path matches exactly what you've whitelisted in SEP.

### 2. Configure Proxy Script

Edit `payment-proxy.php` and update:

```php
$backendUrl = 'https://panel.weekilaw.com/api/wallet/process-callback';
```

Change to your actual backend URL if different.

### 3. Configure Backend

Add to your `.env` file:

```env
SEP_CALLBACK_URL=https://payment.weekilaw.com
SEP_CALLBACK_PATH=/api/payment/payment-listener
FRONTEND_URL=https://weekilaw.com
```

The backend will automatically use this URL when creating payment requests.

### 4. Whitelist Domain in SEP

Make sure `payment.weekilaw.com` is whitelisted in your SEP payment gateway panel:
- Domain: `payment.weekilaw.com`
- Callback URL: `https://payment.weekilaw.com/api/payment/payment-listener`

### 5. Test the Setup

1. Initiate a test payment
2. Complete payment on SEP gateway
3. Verify callback is received by proxy
4. Verify proxy forwards to backend
5. Verify user is redirected to frontend

## How It Works

```
┌─────────────┐
│ SEP Gateway │
└──────┬──────┘
       │ Callback
       ▼
┌─────────────────────────┐
│ payment.weekilaw.com    │  ← Whitelisted domain
│ (Proxy Script)          │
└──────┬──────────────────┘
       │ Forward to backend
       ▼
┌─────────────────────────┐
│ panel.weekilaw.com      │  ← Your backend
│ /api/wallet/process-    │
│ callback                │
└──────┬──────────────────┘
       │ Returns JSON with redirect URL
       ▼
┌─────────────────────────┐
│ payment.weekilaw.com    │  ← Proxy redirects user
│ (Proxy Script)          │
└──────┬──────────────────┘
       │ Redirect user
       ▼
┌─────────────────────────┐
│ weekilaw.com/account    │  ← Frontend
└─────────────────────────┘
```

## Security Notes

1. **HTTPS Required**: Make sure the proxy domain uses HTTPS
2. **IP Whitelisting**: You may also need to whitelist the proxy server's IP in SEP
3. **Error Handling**: The proxy includes error handling and logging
4. **Timeout**: Backend request has 30-second timeout

## Troubleshooting

### Callback Not Received

1. Check SEP panel - is domain whitelisted?
2. Check proxy script location - is URL correct?
3. Check server logs for errors

### Backend Not Processing

1. Check backend logs: `storage/logs/laravel.log`
2. Verify `SEP_CALLBACK_URL` in `.env`
3. Test backend endpoint directly: `https://panel.weekilaw.com/api/wallet/process-callback?Token=test&ResNum=test&State=0`

### User Not Redirected

1. Check proxy script logs
2. Verify `FRONTEND_URL` in backend `.env`
3. Check browser console for redirect issues

## Alternative: Nginx Reverse Proxy

If you prefer using Nginx instead of PHP:

```nginx
location /api/wallet/callback {
    proxy_pass https://panel.weekilaw.com/api/wallet/process-callback;
    proxy_set_header Host $host;
    proxy_set_header X-Real-IP $remote_addr;
    proxy_set_header X-Forwarded-For $proxy_add_x_forwarded_for;
    proxy_set_header X-Forwarded-Proto $scheme;
    
    # Handle redirects
    proxy_intercept_errors on;
    error_page 301 302 303 307 = @handle_redirect;
}

location @handle_redirect {
    set $saved_redirect_location $upstream_http_location;
    proxy_pass $saved_redirect_location;
}
```

## Files

- `payment-proxy.php` - PHP proxy script for whitelisted domain
- `app/Http/Controllers/Api/WalletController.php` - Backend callback processor
- `routes/api.php` - API routes including `/wallet/process-callback`
