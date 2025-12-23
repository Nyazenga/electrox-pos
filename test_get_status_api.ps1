# PowerShell script to test ZIMRA GetStatus API endpoint
# Usage: .\test_get_status_api.ps1 [device_id]
# Example: .\test_get_status_api.ps1 30200

param(
    [int]$DeviceId = 30200
)

# Get the script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpScript = Join-Path $scriptDir "test_get_status_api.php"

# Check if PHP script exists
if (-not (Test-Path $phpScript)) {
    Write-Host "ERROR: PHP script not found at: $phpScript" -ForegroundColor Red
    exit 1
}

# Find PHP executable (common locations)
$phpExe = $null
$phpPaths = @(
    "C:\xampp\php\php.exe",
    "C:\php\php.exe",
    "php.exe",
    (Get-Command php -ErrorAction SilentlyContinue | Select-Object -ExpandProperty Source)
)

foreach ($path in $phpPaths) {
    if ($path -and (Test-Path $path)) {
        $phpExe = $path
        break
    }
}

if (-not $phpExe) {
    Write-Host "ERROR: PHP executable not found!" -ForegroundColor Red
    Write-Host "Please install PHP or add it to your PATH." -ForegroundColor Yellow
    Write-Host "Common locations:" -ForegroundColor Yellow
    Write-Host "  - C:\xampp\php\php.exe" -ForegroundColor Yellow
    Write-Host "  - C:\php\php.exe" -ForegroundColor Yellow
    exit 1
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "ZIMRA GetStatus API Test" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Using PHP: $phpExe" -ForegroundColor Green
Write-Host "Device ID: $DeviceId" -ForegroundColor Yellow
Write-Host ""
Write-Host "API Endpoint: GET /Device/v1/$DeviceId/GetStatus" -ForegroundColor Cyan
Write-Host "Base URL: https://fdmsapitest.zimra.co.zw" -ForegroundColor Cyan
Write-Host ""
Write-Host "Making API call..." -ForegroundColor Cyan
Write-Host ""

# Build command arguments
$arguments = @($phpScript, $DeviceId)

# Run PHP script and capture output
try {
    $output = & $phpExe $arguments 2>&1
    
    # Display output
    foreach ($line in $output) {
        $lineStr = $line.ToString()
        $isError = $false
        $isSuccess = $false
        $isInfo = $false
        $isWarning = $false
        
        # Check for errors
        if ($lineStr -match "ERROR" -or $lineStr -match "Failed") {
            $isError = $true
        }
        # Check for success indicators
        elseif ($lineStr -match "SUCCESS" -or $lineStr -match "Operation ID" -or $lineStr -match "Fiscal Day Status" -or $lineStr -match "Test Complete" -or $lineStr -match "STATUS RESPONSE" -or $lineStr -match "STATUS DETAILS") {
            $isSuccess = $true
        }
        # Check for step information
        elseif ($lineStr -match "Step" -or $lineStr -match "===" -or $lineStr -match "Endpoint" -or $lineStr -match "Method" -or $lineStr -match "Headers" -or $lineStr -match "Certificate" -or $lineStr -match "Response") {
            $isInfo = $true
        }
        # Check for HTTP error codes
        elseif ($lineStr -match "401" -or $lineStr -match "422" -or $lineStr -match "400" -or $lineStr -match "500") {
            $isWarning = $true
        }
        # Check for status values
        elseif ($lineStr -match "FiscalDayOpened" -or $lineStr -match "FiscalDayClosed" -or $lineStr -match "FiscalDayCloseFailed" -or $lineStr -match "FiscalDayCloseInitiated") {
            $isSuccess = $true
        }
        
        # Output with appropriate color
        if ($isError) {
            Write-Host $line -ForegroundColor Red
        } elseif ($isSuccess) {
            Write-Host $line -ForegroundColor Green
        } elseif ($isInfo) {
            Write-Host $line -ForegroundColor Cyan
        } elseif ($isWarning) {
            Write-Host $line -ForegroundColor Yellow
        } else {
            Write-Host $line
        }
    }
    
    # Check exit code
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green
        Write-Host "[SUCCESS] Test completed successfully!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host "[FAILED] Test failed (exit code: $LASTEXITCODE)" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        exit $LASTEXITCODE
    }
} catch {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Red
    Write-Host "[ERROR] $_" -ForegroundColor Red
    Write-Host "========================================" -ForegroundColor Red
    exit 1
}


