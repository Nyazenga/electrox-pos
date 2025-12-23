# PowerShell script to check submitted files from ZIMRA
# Usage: .\check_submitted_files.ps1 [device_id] [fiscal_day_no]
# Example: .\check_submitted_files.ps1 30200 1

param(
    [int]$DeviceId = 30200,
    [int]$FiscalDayNo = 1
)

# Get the script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpScript = Join-Path $scriptDir "check_submitted_files.php"

# Check if PHP script exists
if (-not (Test-Path $phpScript)) {
    Write-Host "ERROR: PHP script not found at: $phpScript" -ForegroundColor Red
    exit 1
}

# Find PHP executable
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
    exit 1
}

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Check Submitted Files from ZIMRA" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "Device ID: $DeviceId" -ForegroundColor Yellow
Write-Host "Fiscal Day No: $FiscalDayNo" -ForegroundColor Yellow
Write-Host ""

# Build command arguments
$arguments = @($phpScript, $DeviceId, $FiscalDayNo)

# Run PHP script
try {
    $output = & $phpExe $arguments 2>&1
    
    foreach ($line in $output) {
        $lineStr = $line.ToString()
        $isError = $false
        $isSuccess = $false
        $isInfo = $false
        $isWarning = $false
        
        if ($lineStr -match "ERROR" -or $lineStr.Contains("[ERROR]")) {
            $isError = $true
        } elseif ($lineStr -match "SUCCESS" -or $lineStr.Contains("[OK]")) {
            $isSuccess = $true
        } elseif ($lineStr -match "Step" -or $lineStr -match "===" -or $lineStr -match "COMPARISON" -or $lineStr -match "ZIMRA SUBMITTED") {
            $isInfo = $true
        } elseif ($lineStr -match "Warning" -or $lineStr -match "NOTE") {
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
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Green
        Write-Host "[SUCCESS] Check completed!" -ForegroundColor Green
        Write-Host "========================================" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "========================================" -ForegroundColor Red
        Write-Host "[FAILED] Check failed (exit code: $LASTEXITCODE)" -ForegroundColor Red
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


