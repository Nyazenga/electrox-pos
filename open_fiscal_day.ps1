# PowerShell script to open a fiscal day
# Usage: .\open_fiscal_day.ps1 [branch_id]
# Example: .\open_fiscal_day.ps1 1

param(
    [int]$BranchId = 0
)

# Get the script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$phpScript = Join-Path $scriptDir "open_fiscal_day.php"

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

Write-Host "Using PHP: $phpExe" -ForegroundColor Green
Write-Host ""

# Build command
$arguments = @($phpScript)
if ($BranchId -gt 0) {
    $arguments += $BranchId
}

# Run PHP script
Write-Host "Opening fiscal day..." -ForegroundColor Cyan
Write-Host ""

try {
    & $phpExe $arguments
    
    if ($LASTEXITCODE -eq 0) {
        Write-Host ""
        Write-Host "✓ Fiscal day opened successfully!" -ForegroundColor Green
    } else {
        Write-Host ""
        Write-Host "✗ Failed to open fiscal day (exit code: $LASTEXITCODE)" -ForegroundColor Red
        exit $LASTEXITCODE
    }
} catch {
    Write-Host ""
    Write-Host "✗ ERROR: $_" -ForegroundColor Red
    exit 1
}


