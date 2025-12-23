# PowerShell script to monitor fiscal day close status
# Usage: .\monitor_fiscal_day_close.ps1 [device_id] [branch_id]
# Example: .\monitor_fiscal_day_close.ps1 30200 1

param(
    [int]$DeviceId = 30200,
    [int]$BranchId = 1
)

# Get the script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpScript = Join-Path $scriptDir "monitor_fiscal_day_close.php"

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
Write-Host "Fiscal Day Close Status Monitor" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Using PHP: $phpExe" -ForegroundColor Green
Write-Host "Device ID: $DeviceId" -ForegroundColor Yellow
Write-Host "Branch ID: $BranchId" -ForegroundColor Yellow
Write-Host ""
Write-Host "This script will:" -ForegroundColor Cyan
Write-Host "  - Check fiscal day status every 3 minutes" -ForegroundColor Cyan
Write-Host "  - Send email to nyazengamd@gmail.com when complete" -ForegroundColor Cyan
Write-Host "  - Exit when status is FiscalDayClosed or FiscalDayCloseFailed" -ForegroundColor Cyan
Write-Host ""
Write-Host "Starting monitoring..." -ForegroundColor Cyan
Write-Host ""

# Build command arguments
$arguments = @($phpScript, $DeviceId, $BranchId)

# Run PHP script and capture output
try {
    $output = & $phpExe $arguments 2>&1
    
    # Display output with color coding
    foreach ($line in $output) {
        $lineStr = $line.ToString()
        $isError = $false
        $isSuccess = $false
        $isInfo = $false
        $isWarning = $false
        
        if ($lineStr -match "ERROR" -or $lineStr -match "Failed" -or $lineStr.Contains("[ERROR]") -or $lineStr.Contains("✗")) {
            $isError = $true
        } elseif ($lineStr -match "SUCCESS" -or $lineStr -match "Closed" -or $lineStr.Contains("✓") -or $lineStr.Contains("successfully")) {
            $isSuccess = $true
        } elseif ($lineStr -match "Check" -or $lineStr -match "===" -or $lineStr -match "Status" -or $lineStr -match "Device ID" -or $lineStr -match "Branch ID" -or $lineStr -match "Fiscal Day" -or $lineStr -match "Operation ID") {
            $isInfo = $true
        } elseif ($lineStr -match "Warning" -or $lineStr -match "Waiting" -or $lineStr.Contains("⚠")) {
            $isWarning = $true
        }

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
        Write-Host "[SUCCESS] Monitoring completed successfully!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
    } elseif ($LASTEXITCODE -eq 1) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host "[FAILED] Fiscal day close failed!" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        exit $LASTEXITCODE
    } elseif ($LASTEXITCODE -eq 2) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Yellow
        Write-Host "[TIMEOUT] Maximum checks reached!" -ForegroundColor Yellow
        Write-Host "========================================" -ForegroundColor Yellow
        exit $LASTEXITCODE
    } else {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host "[FAILED] Script exited with code: $LASTEXITCODE" -ForegroundColor Red
        Write-Host "========================================" -ForegroundColor Red
        exit $LASTEXITCODE
    }
} catch {
    Write-Host ""
    Write-Host "========================================" -ForegroundColor Red
    Write-Host "[ERROR]: $_" -ForegroundColor Red
    Write-Host "========================================" -ForegroundColor Red
    exit 1
}


