# SEP Payment Gateway Integration

This document explains how to configure and use the SEP (Saman Electronic Payment) gateway for payment processing.

## Environment Variables

Add the following environment variables to your `.env` file:

```env
# SEP Payment Gateway Configuration
SEP_MERCHANT_ID=your_merchant_id_here
SEP_TERMINAL_ID=your_terminal_id_here
SEP_SANDBOX=true
```

### Variable Descriptions

- `SEP_MERCHANT_ID`: Your SEP merchant ID provided by Saman Bank
- `SEP_TERMINAL_ID`: Your SEP terminal ID provided by Saman Bank
- `SEP_SANDBOX`: Set to `true` for testing, `false` for production

## API Endpoints

### Request Payment Token
```
POST https://sep.shaparak.ir/OnlinePG/OnlinePG
Content-Type: application/json

{
  "action": "token",
  "TerminalId": "YOUR_TERMINAL_ID",
  "Amount": 10000,
  "ResNum": "TXN-123-1234567890",
  "RedirectUrl": "https://yourdomain.com/api/wallet/callback",
  "CellNumber": "09123456789" // Optional
}
```

### Verify Payment
```
POST https://sep.shaparak.ir/OnlinePG/OnlinePG
Content-Type: application/json

{
  "action": "verify",
  "TerminalId": "YOUR_TERMINAL_ID",
  "Amount": 10000,
  "ResNum": "TXN-123-1234567890"
}
```

### Government Payments with IBAN Settlement

For government payments requiring IBAN settlement:

```json
{
  "action": "token",
  "TerminalId": "YOUR_TERMINAL_ID",
  "Amount": 10000,
  "ResNum": "TXN-123-1234567890",
  "RedirectUrl": "https://yourdomain.com/api/wallet/callback",
  "TranType": "Government",
  "SettlementIBANInfo": [
    {
      "IBAN": "IR111111111111111111111111",
      "Amount": 5000,
      "PurchaseID": "PID-12345"
    },
    {
      "IBAN": "IR222222222222222222222222",
      "Amount": 5000,
      "PurchaseID": "PID-67890"
    }
  ]
}
```

## Callback Parameters

After payment completion, SEP redirects to your callback URL with these parameters:

- `Token`: Payment token
- `ResNum`: Reservation number
- `State`: Payment state (0 = success)
- `RefNum`: Reference number
- `TraceNo`: Trace number

## State Codes

- `0`: Successful payment
- Other values: Failed/cancelled payment

## Testing

Use the sandbox environment by setting `SEP_SANDBOX=true`. You can use test cards provided by SEP for testing.

## Migration from Zarinpal

This implementation replaces the previous Zarinpal integration. Key differences:

1. **Amount**: SEP works with Rials (not Tomans)
2. **Token System**: SEP uses tokens instead of authorities
3. **Callback Parameters**: Different parameter names and structure
4. **API Structure**: Action-based requests instead of REST endpoints

## Error Handling

The SEPPaymentService includes comprehensive error handling and logging. Check the Laravel logs for detailed error information.

## API Endpoints

### Regular Payment
```
POST /api/wallet/add-money
Authorization: Bearer {token}
Content-Type: application/json

{
  "amount": 10000
}
```

### Government Payment with IBAN Settlement
```
POST /api/wallet/add-money-government
Authorization: Bearer {token}
Content-Type: application/json

{
  "amount": 10000,
  "iban_info": [
    {
      "iban": "IR111111111111111111111111",
      "amount": 5000,
      "purchase_id": "PID-12345"
    },
    {
      "iban": "IR222222222222222222222222",
      "amount": 5000,
      "purchase_id": "PID-67890"
    }
  ]
}
```

## Payment Flow

1. **Request Payment**: Client calls `/api/wallet/add-money` or `/api/wallet/add-money-government`
2. **Redirect to SEP**: Server returns payment URL, client redirects to SEP gateway
3. **Payment Completion**: User completes payment on SEP gateway
4. **Callback**: SEP redirects to `/api/wallet/callback` with payment result
5. **Verification**: Server verifies payment with SEP and updates wallet balance

## Support

For SEP integration issues, contact Saman Bank technical support or refer to the official SEP documentation.