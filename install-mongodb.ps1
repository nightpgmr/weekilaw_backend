# MongoDB PHP Extension Auto-Installer for Windows
# Run this script as Administrator

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "MongoDB PHP Extension Installer" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Get PHP configuration
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

# Initialize flags
$installDll = $false
$installIni = $false

# Check if already installed
$mongodbCheck = php -m 2>&1 | Select-String -Pattern "mongodb" -CaseSensitive
if ($null -ne $mongodbCheck) {
    Write-Host "[OK] MongoDB extension is already loaded!" -ForegroundColor Green
    Write-Host "  No installation needed." -ForegroundColor Green
    exit 0
}

# Check if DLL exists
$dllPath = Join-Path $phpExtDir "php_mongodb.dll"
if (Test-Path $dllPath) {
    Write-Host "[OK] MongoDB DLL found at: $dllPath" -ForegroundColor Green
    Write-Host "  Checking php.ini configuration..." -ForegroundColor Yellow
    
    # Check php.ini
    $iniContent = Get-Content $phpIni -ErrorAction SilentlyContinue
    $hasMongodb = $iniContent | Select-String -Pattern "^extension\s*=\s*mongodb" -CaseSensitive
    
    if ($null -ne $hasMongodb) {
        Write-Host "[OK] Extension is enabled in php.ini" -ForegroundColor Green
        Write-Host "  Try restarting PHP server or terminal." -ForegroundColor Yellow
    } else {
        Write-Host "[X] Extension not enabled in php.ini" -ForegroundColor Red
        Write-Host "  Will add it now..." -ForegroundColor Yellow
        $installIni = $true
    }
} else {
    Write-Host "[X] MongoDB DLL not found" -ForegroundColor Red
    Write-Host "  Will download and install..." -ForegroundColor Yellow
    $installDll = $true
    $installIni = $true
}

Write-Host ""

# Download and install DLL
if ($installDll) {
    Write-Host "Step 1: Downloading MongoDB DLL..." -ForegroundColor Cyan
    
    # Use PHP 8.4 DLL (compatible with 8.5)
    $downloadUrl = "https://windows.php.net/downloads/pecl/releases/mongodb/1.21.1/php_mongodb-1.21.1-8.4-ts-vs17-x64.zip"
    $tempZip = Join-Path $env:TEMP "php_mongodb.zip"
    $tempExtract = Join-Path $env:TEMP "php_mongodb_extract"
    
    try {
        Write-Host "  Downloading from: $downloadUrl" -ForegroundColor Gray
        Invoke-WebRequest -Uri $downloadUrl -OutFile $tempZip -UseBasicParsing -ErrorAction Stop
        Write-Host "  [OK] Download complete" -ForegroundColor Green
        
        Write-Host "  Extracting..." -ForegroundColor Gray
        if (Test-Path $tempExtract) {
            Remove-Item $tempExtract -Recurse -Force
        }
        Expand-Archive -Path $tempZip -DestinationPath $tempExtract -Force
        Write-Host "  [OK] Extraction complete" -ForegroundColor Green
        
        # Find php_mongodb.dll in extracted files
        $dllFile = Get-ChildItem -Path $tempExtract -Filter "php_mongodb.dll" -Recurse | Select-Object -First 1
        
        if ($null -ne $dllFile) {
            Write-Host "  Copying DLL to: $phpExtDir" -ForegroundColor Gray
            Copy-Item $dllFile.FullName -Destination $dllPath -Force
            Write-Host "  [OK] DLL installed successfully" -ForegroundColor Green
            
            # Check for libsasl.dll
            $libsaslFile = Get-ChildItem -Path $tempExtract -Filter "libsasl.dll" -Recurse | Select-Object -First 1
            if ($null -ne $libsaslFile) {
                $phpRoot = Split-Path $phpIni
                $libsaslDest = Join-Path $phpRoot "libsasl.dll"
                Write-Host "  Copying libsasl.dll to: $phpRoot" -ForegroundColor Gray
                Copy-Item $libsaslFile.FullName -Destination $libsaslDest -Force
                Write-Host "  [OK] libsasl.dll installed" -ForegroundColor Green
            }
        } else {
            Write-Host "  [X] php_mongodb.dll not found in downloaded archive" -ForegroundColor Red
            Write-Host "  Please download manually from: https://pecl.php.net/package/mongodb/1.21.1/windows" -ForegroundColor Yellow
            exit 1
        }
        
        # Cleanup
        Remove-Item $tempZip -Force -ErrorAction SilentlyContinue
        Remove-Item $tempExtract -Recurse -Force -ErrorAction SilentlyContinue
        
    } catch {
        Write-Host "  [X] Download failed: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "  Please download manually from: https://pecl.php.net/package/mongodb/1.21.1/windows" -ForegroundColor Yellow
        Write-Host "  File: php_mongodb-1.21.1-8.4-ts-vs17-x64.zip" -ForegroundColor Yellow
        Write-Host "  Extract and copy php_mongodb.dll to: $phpExtDir" -ForegroundColor Yellow
        exit 1
    }
}

# Enable in php.ini
if ($installIni) {
    Write-Host "Step 2: Enabling extension in php.ini..." -ForegroundColor Cyan
    
    try {
        $iniContent = Get-Content $phpIni -ErrorAction Stop
        $hasMongodb = $iniContent | Select-String -Pattern "^extension\s*=\s*mongodb" -CaseSensitive
        
        if ($null -eq $hasMongodb) {
            # Find where to add it (after other extensions)
            $insertIndex = -1
            for ($i = 0; $i -lt $iniContent.Length; $i++) {
                if ($iniContent[$i] -match '^extension\s*=\s*openssl') {
                    $insertIndex = $i + 1
                    break
                }
            }
            
            if ($insertIndex -gt 0) {
                # Insert after openssl
                $newContent = @()
                for ($i = 0; $i -lt $iniContent.Length; $i++) {
                    $newContent += $iniContent[$i]
                    if ($i -eq ($insertIndex - 1)) {
                        $newContent += "extension=mongodb"
                    }
                }
                
                # Backup php.ini
                $backupPath = "$phpIni.backup.$(Get-Date -Format 'yyyyMMdd_HHmmss')"
                Copy-Item $phpIni -Destination $backupPath -Force
                Write-Host "  [OK] Backup created: $backupPath" -ForegroundColor Gray
                
                # Write new content
                $newContent | Set-Content $phpIni -Encoding UTF8
                Write-Host "  [OK] Added 'extension=mongodb' to php.ini" -ForegroundColor Green
            } else {
                Write-Host "  [WARN] Could not find extension section. Adding at end of file..." -ForegroundColor Yellow
                Add-Content -Path $phpIni -Value "extension=mongodb" -Encoding UTF8
                Write-Host "  [OK] Added 'extension=mongodb' to php.ini" -ForegroundColor Green
            }
        } else {
            Write-Host "  [OK] Extension already enabled in php.ini" -ForegroundColor Green
        }
    } catch {
        Write-Host "  [X] Failed to edit php.ini: $($_.Exception.Message)" -ForegroundColor Red
        Write-Host "  Please edit manually: $phpIni" -ForegroundColor Yellow
        Write-Host "  Add this line: extension=mongodb" -ForegroundColor Yellow
        exit 1
    }
}

Write-Host ""
Write-Host "Step 3: Verifying installation..." -ForegroundColor Cyan

# Verify
Start-Sleep -Seconds 1
$mongodbCheck = php -m 2>&1 | Select-String -Pattern "mongodb" -CaseSensitive

if ($null -ne $mongodbCheck) {
    Write-Host "  [OK] MongoDB extension is now loaded!" -ForegroundColor Green
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host "Installation Complete!" -ForegroundColor Green
    Write-Host "========================================" -ForegroundColor Cyan
    Write-Host ""
    Write-Host "Next steps:" -ForegroundColor Yellow
    Write-Host "  1. Restart your PHP server: php artisan serve" -ForegroundColor White
    Write-Host "  2. Test MongoDB connection in Laravel" -ForegroundColor White
} else {
    Write-Host "  [WARN] Extension not yet loaded" -ForegroundColor Yellow
    Write-Host ""
    Write-Host "Please:" -ForegroundColor Yellow
    Write-Host "  1. Close and reopen your terminal/PowerShell" -ForegroundColor White
    Write-Host "  2. Run: php -m | findstr -i mongodb" -ForegroundColor White
    Write-Host "  3. If still not loaded, restart your computer" -ForegroundColor White
}
