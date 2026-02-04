# Simple MongoDB PHP Extension Installer
# This script helps you install MongoDB extension step by step

Write-Host "MongoDB PHP Extension Installer" -ForegroundColor Cyan
Write-Host "================================" -ForegroundColor Cyan
Write-Host ""

$phpIni = php -r "echo php_ini_loaded_file();"
$phpExtDir = php -r "echo ini_get('extension_dir');"

Write-Host "PHP Configuration:" -ForegroundColor Yellow
Write-Host "  php.ini: $phpIni"
Write-Host "  Extension dir: $phpExtDir"
Write-Host ""

# Check if already installed - use extension_loaded() to verify actual loading
$mongodbLoaded = php -r "echo extension_loaded('mongodb') ? 'yes' : 'no';" 2>&1 | Select-String -Pattern "yes" -CaseSensitive
if ($null -ne $mongodbLoaded) {
    Write-Host "[OK] MongoDB extension is already loaded!" -ForegroundColor Green
    exit 0
}

# Check if DLL exists
$dllPath = Join-Path $phpExtDir "php_mongodb.dll"
if (Test-Path $dllPath) {
    Write-Host "[!] DLL exists but extension failed to load. Checking php.ini..." -ForegroundColor Yellow
} else {
    Write-Host "[!] MongoDB DLL not found. Need to download and install." -ForegroundColor Yellow
}

Write-Host "Step 1: Download MongoDB DLL" -ForegroundColor Cyan
Write-Host "  Open this URL in your browser:" -ForegroundColor Yellow
Write-Host "  https://pecl.php.net/package/mongodb/1.21.1/windows" -ForegroundColor White
Write-Host ""
Write-Host "  Or use curl command:" -ForegroundColor Yellow
Write-Host "  curl -L -o php_mongodb.zip https://windows.php.net/downloads/pecl/releases/mongodb/1.21.1/php_mongodb-1.21.1-8.4-ts-vs17-x64.zip" -ForegroundColor White
Write-Host ""
$download = Read-Host "Have you downloaded the DLL? (y/n)"

if ($download -ne "y" -and $download -ne "Y") {
    Write-Host ""
    Write-Host "Please download the DLL first, then run this script again." -ForegroundColor Yellow
    Write-Host "Download from: https://pecl.php.net/package/mongodb/1.21.1/windows" -ForegroundColor White
    Write-Host "File needed: php_mongodb-1.21.1-8.4-ts-vs17-x64.zip" -ForegroundColor White
    exit 1
}

Write-Host ""
Write-Host "Step 2: Extract and copy DLL" -ForegroundColor Cyan
$zipPath = Read-Host "Enter path to downloaded zip file (or press Enter if in current directory)"

if ([string]::IsNullOrWhiteSpace($zipPath)) {
    $zipPath = "php_mongodb.zip"
}

if (-not (Test-Path $zipPath)) {
    Write-Host "[X] File not found: $zipPath" -ForegroundColor Red
    exit 1
}

$tempExtract = Join-Path $env:TEMP "php_mongodb_extract"
if (Test-Path $tempExtract) {
    Remove-Item $tempExtract -Recurse -Force
}

Write-Host "  Extracting..." -ForegroundColor Gray
Expand-Archive -Path $zipPath -DestinationPath $tempExtract -Force

$dllFile = Get-ChildItem -Path $tempExtract -Filter "php_mongodb.dll" -Recurse | Select-Object -First 1

if ($null -eq $dllFile) {
    Write-Host "[X] php_mongodb.dll not found in archive" -ForegroundColor Red
    exit 1
}

Write-Host "  Copying DLL to: $phpExtDir" -ForegroundColor Gray
Copy-Item $dllFile.FullName -Destination (Join-Path $phpExtDir "php_mongodb.dll") -Force
Write-Host "  [OK] DLL copied" -ForegroundColor Green

# Check for libsasl.dll
$libsaslFile = Get-ChildItem -Path $tempExtract -Filter "libsasl.dll" -Recurse | Select-Object -First 1
if ($null -ne $libsaslFile) {
    $phpRoot = Split-Path $phpIni
    Copy-Item $libsaslFile.FullName -Destination (Join-Path $phpRoot "libsasl.dll") -Force
    Write-Host "  [OK] libsasl.dll copied" -ForegroundColor Green
}

Remove-Item $tempExtract -Recurse -Force

Write-Host ""
Write-Host "Step 3: Enable in php.ini" -ForegroundColor Cyan

$iniContent = Get-Content $phpIni -ErrorAction Stop
$hasMongodb = $iniContent | Select-String -Pattern "^extension\s*=\s*mongodb" -CaseSensitive

if ($null -ne $hasMongodb) {
    Write-Host "  [OK] Extension already enabled in php.ini" -ForegroundColor Green
} else {
    # Find where to add it
    $insertIndex = -1
    for ($i = 0; $i -lt $iniContent.Length; $i++) {
        if ($iniContent[$i] -match '^extension\s*=\s*openssl') {
            $insertIndex = $i + 1
            break
        }
    }
    
    if ($insertIndex -gt 0) {
        $newContent = @()
        for ($i = 0; $i -lt $iniContent.Length; $i++) {
            $newContent += $iniContent[$i]
            if ($i -eq ($insertIndex - 1)) {
                $newContent += "extension=mongodb"
            }
        }
        
        $backupPath = "$phpIni.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
        Copy-Item $phpIni -Destination $backupPath -Force
        Write-Host "  [OK] Backup created: $backupPath" -ForegroundColor Gray
        
        $newContent | Set-Content $phpIni -Encoding UTF8
        Write-Host "  [OK] Added 'extension=mongodb' to php.ini" -ForegroundColor Green
    } else {
        Add-Content -Path $phpIni -Value "extension=mongodb" -Encoding UTF8
        Write-Host "  [OK] Added 'extension=mongodb' to php.ini" -ForegroundColor Green
    }
}

Write-Host ""
Write-Host "Step 4: Verify" -ForegroundColor Cyan
Write-Host "  Close and reopen your terminal, then run:" -ForegroundColor Yellow
Write-Host "  php -m | findstr -i mongodb" -ForegroundColor White
Write-Host ""
Write-Host "  Should output: mongodb" -ForegroundColor Gray
Write-Host ""
Write-Host "[OK] Installation complete! Restart your terminal and PHP server." -ForegroundColor Green
