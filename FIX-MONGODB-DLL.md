# Fix MongoDB DLL - Wrong Version Installed

## Problem
You installed the **NTS (Non Thread Safe)** DLL, but your PHP 8.5.2 requires **TS (Thread Safe)** DLL.

## Solution

### Step 1: Download Correct DLL

**Visit:** https://pecl.php.net/package/mongodb/1.21.1/windows

**Download:** `php_mongodb-1.21.1-8.4-ts-vs17-x64.zip`
- ✅ Must be **TS** (Thread Safe)
- ✅ Must be **x64** (64-bit)
- ✅ PHP 8.4 DLL works with PHP 8.5.2

**⚠️ DO NOT download:**
- ❌ `php_mongodb-1.21.1-8.1-nts-vs16-x64.zip` (NTS - wrong!)
- ❌ Any file with "nts" in the name

### Step 2: Install Correct DLL

Run these commands in PowerShell:

```powershell
# Extract the downloaded zip file
Expand-Archive -Path "C:\Users\Sajjad Sa\Downloads\php_mongodb-1.21.1-8.4-ts-vs17-x64.zip" -DestinationPath temp_extract -Force

# Copy DLL to PHP extensions directory
Copy-Item temp_extract\php_mongodb.dll C:\xampp\php\ext\ -Force

# Copy libsasl.dll if present (to PHP root directory)
Copy-Item temp_extract\libsasl.dll C:\xampp\php\ -Force -ErrorAction SilentlyContinue

# Cleanup
Remove-Item temp_extract -Recurse -Force
```

**Or use the interactive script:**
```powershell
powershell -ExecutionPolicy Bypass -File install-mongodb-simple.ps1
```
(When prompted, provide the path to the **TS** zip file)

### Step 3: Verify

Close and reopen your terminal, then:

```powershell
php -m | findstr -i mongodb
```

Should output: `mongodb`

### Step 4: Test Laravel

```powershell
php artisan serve
```

The MongoDB extension should now load without errors!

## Quick Command (if you have the zip file)

Replace `path\to\zip` with your actual zip file path:

```powershell
$zipPath = "C:\Users\Sajjad Sa\Downloads\php_mongodb-1.21.1-8.4-ts-vs17-x64.zip"
Expand-Archive -Path $zipPath -DestinationPath temp_extract -Force
Copy-Item temp_extract\php_mongodb.dll C:\xampp\php\ext\ -Force
Copy-Item temp_extract\libsasl.dll C:\xampp\php\ -Force -ErrorAction SilentlyContinue
Remove-Item temp_extract -Recurse -Force
php -m | findstr -i mongodb
```
