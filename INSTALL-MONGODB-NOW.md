# Quick Install: MongoDB PHP Extension

## Current Status
- ✅ MongoDB server is installed on Windows
- ❌ PHP MongoDB extension is NOT loaded
- ❌ DLL file missing: `C:\xampp\php\ext\php_mongodb.dll`

## Quick Fix (3 Steps)

### Step 1: Download MongoDB DLL

**For PHP 8.5.2 (64-bit, Thread Safe):**

1. Open this link in your browser:
   **https://pecl.php.net/package/mongodb/1.21.1/windows**

2. Download: **`php_mongodb-1.21.1-8.4-ts-vs17-x64.zip`**
   - PHP 8.4 DLL works with PHP 8.5.2
   - Make sure it's **Thread Safe (TS) x64**

### Step 2: Install DLL

1. Extract the downloaded zip file
2. Copy `php_mongodb.dll` to: `C:\xampp\php\ext\`
3. If `libsasl.dll` is in the zip, copy it to: `C:\xampp\php\` (PHP root directory)

### Step 3: Enable in php.ini

1. Open `C:\xampp\php\php.ini` in Notepad (Run as Administrator)
2. Find the section with other extensions (search for `extension=curl`)
3. Add this line after other `extension=` lines:
   ```ini
   extension=mongodb
   ```
4. Save the file

### Step 4: Verify

Run these commands:
```powershell
php -m | findstr -i mongodb
```

Should output: `mongodb`

If it works, restart your Laravel server:
```powershell
# Stop current server (Ctrl+C)
php artisan serve
```

## Troubleshooting

**If you get "libsasl.dll not found":**
- Copy `libsasl.dll` from the zip to `C:\xampp\php\`
- Or add it to your Windows PATH

**If extension still not loaded:**
- Make sure you saved `php.ini` after editing
- Restart your terminal/PowerShell window
- Check: `php -r "echo php_ini_loaded_file();"` to confirm correct php.ini
