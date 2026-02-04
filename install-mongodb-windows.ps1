# MongoDB PHP Extension Installer for Windows (XAMPP)
# Run this script as Administrator

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "MongoDB PHP Extension Installer" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check PHP version
$phpVersion = php -r "echo PHP_VERSION;"
$phpArch = php -r "echo (PHP_INT_SIZE * 8) . '-bit';"
$phpTS = php -r "echo (ZEND_THREAD_SAFE ? 'TS' : 'NTS');"
$phpIni = php -r "echo php_ini_loaded_file();"
$phpExtDir = php -r "echo ini_get('extension_dir');"

Write-Host "Detected PHP Configuration:" -ForegroundColor Yellow
Write-Host "  Version: $phpVersion"
Write-Host "  Architecture: $phpArch"
Write-Host "  Thread Safety: $phpTS"
Write-Host "  php.ini: $phpIni"
Write-Host "  Extension dir: $phpExtDir"
Write-Host ""

# Check if MongoDB extension already loaded
$mongodbLoaded = php -m 2>&1 | Select-String -Pattern "mongodb" -CaseSensitive
if ($mongodbLoaded) {
    Write-Host "✓ MongoDB extension is already loaded!" -ForegroundColor Green
    Write-Host "  You can skip installation." -ForegroundColor Green
    Write-Host ""
    exit 0
}

# Check if DLL exists
$dllPath = Join-Path $phpExtDir "php_mongodb.dll"
if (Test-Path $dllPath) {
    Write-Host "✓ MongoDB DLL found at: $dllPath" -ForegroundColor Green
    Write-Host "  But extension is not loaded. Check php.ini." -ForegroundColor Yellow
    Write-Host ""
} else {
    Write-Host "✗ MongoDB DLL not found at: $dllPath" -ForegroundColor Red
    Write-Host ""
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Manual Installation Steps:" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "1. Download MongoDB DLL:" -ForegroundColor Yellow
Write-Host "   Visit: https://pecl.php.net/package/mongodb" -ForegroundColor White
Write-Host "   Click 'DLL' tab" -ForegroundColor White
Write-Host "   Download: PHP 8.4 (or 8.5 if available) - Thread Safe (TS) x64" -ForegroundColor White
Write-Host ""
Write-Host "2. Extract and copy php_mongodb.dll to:" -ForegroundColor Yellow
Write-Host "   $phpExtDir" -ForegroundColor White
Write-Host ""
Write-Host "3. Edit php.ini:" -ForegroundColor Yellow
Write-Host "   File: $phpIni" -ForegroundColor White
Write-Host "   Add line: extension=mongodb" -ForegroundColor White
Write-Host "   (Find other extension= lines and add it there)" -ForegroundColor White
Write-Host ""
Write-Host "4. Restart PHP server:" -ForegroundColor Yellow
Write-Host "   Stop: php artisan serve (Ctrl+C)" -ForegroundColor White
Write-Host "   Start: php artisan serve" -ForegroundColor White
Write-Host ""
Write-Host "5. Verify:" -ForegroundColor Yellow
Write-Host "   php -m | findstr -i mongodb" -ForegroundColor White
Write-Host "   Should output: mongodb" -ForegroundColor White
Write-Host ""

# Check if php.ini is writable
try {
    $iniContent = Get-Content $phpIni -ErrorAction Stop
    $hasMongodb = $iniContent | Select-String -Pattern "^extension\s*=\s*mongodb" -CaseSensitive
    
    if ($hasMongodb) {
        Write-Host "✓ Found 'extension=mongodb' in php.ini" -ForegroundColor Green
    } else {
        Write-Host "✗ 'extension=mongodb' not found in php.ini" -ForegroundColor Red
        Write-Host "  You need to add it manually." -ForegroundColor Yellow
    }
} catch {
    Write-Host "Could not read php.ini (may need Administrator access)" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "For more details, see: MONGODB-SETUP.md" -ForegroundColor Cyan
