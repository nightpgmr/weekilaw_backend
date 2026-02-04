# MongoDB Setup for PHP Backend

## Overview

The PHP backend now reads users from MongoDB (shared with your Node.js backend). OTP codes are still stored in SQL (`temp_otps` table) for verification.

## Requirements

1. **MongoDB PHP Extension** - Install the MongoDB PHP driver:

   ### Windows Installation (XAMPP)
   
   **Your PHP Info:**
   - PHP Version: 8.5.2
   - Architecture: 64-bit
   - Thread Safety: Yes (ZTS)
   - php.ini location: `C:\xampp\php\php.ini`
   
   **Step-by-step:**
   
   1. **Download MongoDB DLL:**
      - **Direct link**: https://pecl.php.net/package/mongodb/1.21.1/windows
      - Or go to: https://pecl.php.net/package/mongodb → Click "DLL" tab
      - Download: **PHP 8.4 Thread Safe (TS) x64** (works with PHP 8.5.2)
      - File: `php_mongodb-1.21.1-8.4-ts-vs17-x64.zip`
      - **Note**: PHP 8.5 DLLs may not be available yet, but PHP 8.4 DLL is compatible
   
   2. **Extract and Copy DLL:**
      ```powershell
      # Extract the zip file
      # Copy php_mongodb.dll to C:\xampp\php\ext\
      ```
      - Extract the downloaded zip
      - Copy `php_mongodb.dll` to `C:\xampp\php\ext\`
      - **Important**: Also check if `libsasl.dll` is in the zip - if so, copy it to `C:\xampp\php\` (root PHP directory) or ensure it's in your system PATH
   
   3. **Enable Extension in php.ini:**
      - Open `C:\xampp\php\php.ini` in a text editor (as Administrator)
      - Find the section with other `extension=` lines (around line 900-1000)
      - Add this line:
        ```ini
        extension=mongodb
        ```
      - Save the file
   
   4. **Verify Installation:**
      ```powershell
      php -m | findstr -i mongodb
      ```
      Should output: `mongodb`
   
   5. **Restart PHP Server:**
      - Stop `php artisan serve` (Ctrl+C)
      - Start again: `php artisan serve`

   **Quick Install Script:**
   ```powershell
   # Run the helper script to check your setup:
   cd backend
   .\install-mongodb-windows.ps1
   ```
   This script will show your PHP configuration and guide you through installation.
   
   **Alternative: Using PECL (if available):**
   ```powershell
   # If pecl is available in your PATH:
   pecl install mongodb
   ```

2. **MongoDB PHP Library** - Already added to `composer.json`:
   ```powershell
   cd backend
   composer install
   ```

## Configuration

Add to `.env`:
```env
MONGODB_URI=mongodb://WeekiLa:vaki1a2W@130.185.75.96:27017/?authSource=admin
MONGODB_DATABASE=weekilaw
MONGODB_COLLECTION=users
```

## How It Works

1. **Login Flow:**
   - User enters phone → Backend checks MongoDB for user
   - OTP generated (12345 in dev, Kavenegar SMS in prod)
   - OTP stored in SQL `temp_otps` table
   - User verifies OTP → Backend checks `temp_otps`, reads user from MongoDB
   - Returns tokens: `access_token`, `refresh_token`, `user_data`

2. **User Storage:**
   - **MongoDB**: User data (phone, name, email, etc.) - read-only from PHP
   - **SQL**: OTP codes (`temp_otps` table) - temporary storage

3. **Token Format (matches Node.js backend):**
   ```json
   {
     "success": true,
     "access_token": "...",
     "auth_token": "...",
     "refresh_token": "...",
     "user": {...},
     "user_data": {...}
   }
   ```

## Testing

1. Check MongoDB connection:
   ```powershell
   php artisan tinker
   >>> $service = app(\App\Services\MongoUserService::class);
   >>> $user = $service->findUserByPhone('09380587367');
   >>> dd($user);
   ```

2. Test login flow:
   - Use any phone number that exists in MongoDB
   - In dev: OTP is **12345**
   - Verify and check localStorage for: `access_token`, `refresh_token`, `user_data`

## Troubleshooting

- **"Class 'MongoDB\Client' not found"**: 
  - MongoDB PHP extension not installed or not enabled
  - Check: `php -m | findstr -i mongodb` (should show `mongodb`)
  - Verify `extension=mongodb` is in `php.ini` and not commented out
  - Make sure DLL matches your PHP version (8.5) and architecture (64-bit, TS)
  
- **"MongoDB PHP extension is not installed"**:
  - Download correct DLL for PHP 8.5 (64-bit, Thread Safe)
  - Copy to `C:\xampp\php\ext\`
  - Add `extension=mongodb` to `php.ini`
  - Restart PHP server
  
- **"Connection timeout"**: 
  - Check MongoDB URI and network access
  - Verify firewall allows connection to `130.185.75.96:27017`
  - Test connection: `php artisan tinker` → `app(\App\Services\MongoUserService::class)`
  
- **"User not found"**: 
  - User must exist in MongoDB collection (shared with Node.js backend)
  - In dev mode, user will be auto-created if not found (check logs)
  
- **"pecl is not recognized"**:
  - Normal on Windows - use precompiled DLL instead (see Windows Installation above)
