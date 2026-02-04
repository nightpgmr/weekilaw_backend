# Fix MongoDB DLL - Download correct version for PHP 8.5.2 TS

Write-Host "Fixing MongoDB DLL..." -ForegroundColor Cyan
Write-Host ""

$phpExtDir = php -r "echo ini_get('extension_dir');" 2>$null
if ([string]::IsNullOrWhiteSpace($phpExtDir)) {
    $phpExtDir = "C:\xampp\php\ext"
}
$dllPath = Join-Path $phpExtDir "php_mongodb.dll"

# Remove wrong DLL
if (Test-Path $dllPath) {
    Write-Host "Removing incorrect DLL..." -ForegroundColor Yellow
    Remove-Item $dllPath -Force
    Write-Host "[OK] Removed" -ForegroundColor Green
}

Write-Host ""
Write-Host "Downloading correct DLL for PHP 8.5.2 (TS x64)..." -ForegroundColor Cyan
Write-Host "Using PHP 8.4 TS DLL (compatible with 8.5.2)" -ForegroundColor Gray
Write-Host ""

# Try multiple download sources
$downloadUrls = @(
    "https://github.com/mongodb/mongo-php-driver/releases/download/1.21.1/php_mongodb-1.21.1-8.4-ts-vs17-x64.zip",
    "https://windows.php.net/downloads/pecl/releases/mongodb/1.21.1/php_mongodb-1.21.1-8.4-ts-vs17-x64.zip"
)

$tempZip = Join-Path $env:TEMP "php_mongodb_correct.zip"
$tempExtract = Join-Path $env:TEMP "php_mongodb_fix"

$downloaded = $false

foreach ($url in $downloadUrls) {
    try {
        Write-Host "Trying: $url" -ForegroundColor Gray
        Invoke-WebRequest -Uri $url -OutFile $tempZip -UseBasicParsing -ErrorAction Stop
        Write-Host "[OK] Download successful!" -ForegroundColor Green
        $downloaded = $true
        break
    } catch {
        Write-Host "[X] Failed: $($_.Exception.Message)" -ForegroundColor Red
        continue
    }
}

if (-not $downloaded) {
    Write-Host ""
    Write-Host "[X] Automatic download failed" -ForegroundColor Red
    Write-Host ""
    Write-Host "Please download manually:" -ForegroundColor Yellow
    Write-Host "1. Visit: https://pecl.php.net/package/mongodb/1.21.1/windows" -ForegroundColor White
    Write-Host "2. Download: php_mongodb-1.21.1-8.4-ts-vs17-x64.zip" -ForegroundColor White
    Write-Host "   (PHP 8.4 Thread Safe x64)" -ForegroundColor Gray
    Write-Host "3. Save it to: $tempZip" -ForegroundColor White
    Write-Host "4. Run this script again" -ForegroundColor White
    Write-Host ""
    Write-Host "Or extract manually and copy php_mongodb.dll to: $phpExtDir" -ForegroundColor Yellow
    exit 1
}

# Extract
Write-Host ""
Write-Host "Extracting..." -ForegroundColor Cyan
if (Test-Path $tempExtract) {
    Remove-Item $tempExtract -Recurse -Force
}
Expand-Archive -Path $tempZip -DestinationPath $tempExtract -Force

# Find and copy DLL
$dllFile = Get-ChildItem -Path $tempExtract -Filter "php_mongodb.dll" -Recurse | Select-Object -First 1

if ($null -eq $dllFile) {
    Write-Host "[X] php_mongodb.dll not found in archive" -ForegroundColor Red
    Write-Host "Please check the downloaded file" -ForegroundColor Yellow
    exit 1
}

Write-Host "Copying DLL to: $phpExtDir" -ForegroundColor Cyan
Copy-Item $dllFile.FullName -Destination $dllPath -Force
Write-Host "[OK] DLL installed" -ForegroundColor Green

# Check for libsasl.dll
$libsaslFile = Get-ChildItem -Path $tempExtract -Filter "libsasl.dll" -Recurse | Select-Object -First 1
if ($null -ne $libsaslFile) {
    $phpRoot = Split-Path (php -r "echo php_ini_loaded_file();")
    $libsaslDest = Join-Path $phpRoot "libsasl.dll"
    Write-Host "Copying libsasl.dll to: $phpRoot" -ForegroundColor Cyan
    Copy-Item $libsaslFile.FullName -Destination $libsaslDest -Force
    Write-Host "[OK] libsasl.dll installed" -ForegroundColor Green
}

# Cleanup
Remove-Item $tempZip -Force -ErrorAction SilentlyContinue
Remove-Item $tempExtract -Recurse -Force -ErrorAction SilentlyContinue

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Installation Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Next steps:" -ForegroundColor Yellow
Write-Host "1. Close and reopen your terminal" -ForegroundColor White
Write-Host "2. Verify: php -m | findstr -i mongodb" -ForegroundColor White
Write-Host "3. Start server: php artisan serve" -ForegroundColor White
Write-Host ""
