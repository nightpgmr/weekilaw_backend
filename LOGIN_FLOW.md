# Login Flow Documentation

## Overview
The authentication system uses MongoDB for user storage and OTP (One-Time Password) verification for login.

## MongoDB Configuration

**Connection URI:** `mongodb://WeekiLa:vaki1a2W@130.185.75.96:27017/?authSource=admin`
- **Database:** `test`
- **Collection:** `users`

Configuration is stored in:
- `.env` file: `MONGODB_URI`, `MONGODB_DATABASE`, `MONGODB_COLLECTION`
- `config/mongodb.php`: Default values and options

## Login Flow

### 1. Send OTP (`POST /api/auth/send-otp`)

**Request:**
```json
{
  "phone": "09123456789",
  "type": "login"  // optional, defaults to "login"
}
```

**Process:**
1. Validates phone number format (`09XXXXXXXXX`)
2. Searches MongoDB (`test.users`) for user with matching phone number
3. In **dev mode only**: Auto-creates test user if not found
4. Generates OTP code:
   - **Dev mode:** Returns `12345` (from `OTP_DEV_CODE` in `.env`)
   - **Production:** Generates random 4-digit code
5. Stores OTP in `temp_otps` table (SQLite) with 5-minute expiration
6. Sends OTP:
   - **Dev mode:** No SMS sent, returns OTP in response
   - **Production:** Sends SMS via Kavenegar API

**Response (Dev Mode):**
```json
{
  "success": true,
  "message": "کد تایید به شماره موبایل شما ارسال شد",
  "expires_in": 300,
  "dev_otp": "12345",
  "code": "12345"
}
```

**Response (Production):**
```json
{
  "success": true,
  "message": "کد تایید به شماره موبایل شما ارسال شد",
  "expires_in": 300
}
```

### 2. Verify OTP (`POST /api/auth/verify-otp`)

**Request:**
```json
{
  "phone": "09123456789",
  "otp_code": "12345"  // or "otp": "12345"
}
```

**Process:**
1. Validates phone and OTP format (4-6 digits)
2. Searches MongoDB (`test.users`) for user
3. Retrieves stored OTP from `temp_otps` table
4. Verifies OTP matches and hasn't expired
5. Creates/updates local User model (for Sanctum tokens)
6. Generates access token (24 hours) and refresh token (30 days)
7. Returns user data and tokens

**Response:**
```json
{
  "success": true,
  "message": "ورود با موفقیت انجام شد",
  "access_token": "...",
  "refresh_token": "...",
  "user": {
    "id": "mongodb_object_id",
    "name": "User Name",
    "phone": "09123456789",
    "email": "user@example.com",
    "role": "user"
  }
}
```

## Environment Modes

### Development Mode (`APP_ENV=local`)

**OTP Behavior:**
- Uses test code: `12345` (configured in `OTP_DEV_CODE`)
- No SMS sent via Kavenegar
- OTP code included in API response for testing
- Auto-creates test users in MongoDB if not found

**Configuration:**
```env
APP_ENV=local
OTP_DEV_CODE=12345
```

### Production Mode (`APP_ENV=production`)

**OTP Behavior:**
- Generates random 4-digit OTP code
- Sends SMS via Kavenegar API
- Requires valid Kavenegar API key
- No OTP code in response

**Configuration:**
```env
APP_ENV=production
KAVENEGAR_API_KEY=your_api_key
KAVENEGAR_TEMPLATE=weekilaw
```

## MongoDB User Document Structure

Users are stored in MongoDB collection `test.users` with the following structure:

```json
{
  "_id": "ObjectId(...)",
  "phone": "09123456789",
  "name": "User Name",
  "email": "user@example.com",
  "auth_provider": "phone",
  "role": "user",
  "phone_verified_at": ISODate("..."),
  "created_at": ISODate("..."),
  "updated_at": ISODate("...")
}
```

## Key Files

- **Controller:** `app/Http/Controllers/Auth/PhoneAuthController.php`
- **MongoDB Service:** `app/Services/MongoUserService.php`
- **OTP Service:** `app/Services/OTPService.php`
- **Config:** `config/mongodb.php`
- **Environment:** `.env`

## Testing

### Test OTP Code
Run the test script to verify OTP configuration:
```bash
php test-code-12345.php
```

### Test MongoDB Connection
```bash
php artisan mongodb:check
```

## Notes

1. **MongoDB is the source of truth** for user data
2. **Local SQLite database** stores OTP codes temporarily and local User models for Sanctum tokens
3. **Dev mode** uses fixed OTP `12345` for easy testing
4. **Production mode** requires valid Kavenegar API credentials
5. After changing `.env`, run: `php artisan config:clear`
